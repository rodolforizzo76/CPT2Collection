<?php
/** Synchronous verified Stripe delivery orchestrator. @package CPT2Collection */
declare(strict_types=1); namespace RodolfoRizzo\Cpt2Collection; defined('ABSPATH')||exit;
require_once __DIR__.'/class-provider-webhook-evidence.php';
final class PaymentWebhookOrchestrator {
 private StripeEventNormalizer $normalizer; private PaymentEventRepository $events; private PaymentEventAssociationService $association; private SaleConversionService $conversion; private PaymentExpirationService $expiration; private PaymentEventOutcomeService $outcomes; private UtcClock $clock; private CartReconciliationService $reconciliation; private TransactionManager $transactions; private ProviderWebhookEvidence $evidence;
 /** Operational collaborators share the manager; evidence owns separate private connections. */
 public function __construct(StripeEventNormalizer $n,PaymentEventRepository $e,PaymentEventAssociationService $a,SaleConversionService $c,PaymentExpirationService $x,PaymentEventOutcomeService $o,UtcClock $clock,CartReconciliationService $reconciliation,TransactionManager $transactions,ProviderWebhookEvidence $evidence){$this->normalizer=$n;$this->events=$e;$this->association=$a;$this->conversion=$c;$this->expiration=$x;$this->outcomes=$o;$this->clock=$clock;$this->reconciliation=$reconciliation;$this->transactions=$transactions;$this->evidence=$evidence;}
 /** @param array<string,mixed> $verified */
 public function handle(array $verified,string $hash):WebhookHttpDisposition {
  // Also guard receipt persistence on reuse after an uncertain transaction.
  if($this->transactions->is_active())return WebhookHttpDisposition::failure($this->transactions->is_outcome_uncertain()?'transaction_outcome_unknown':'transaction_already_active');
  $normalized=$this->normalizer->normalize($verified,$hash);if(!$normalized->has_receipt())return WebhookHttpDisposition::bad_request('malformed_event');
  if(StripeEventNormalizationResult::UNSUPPORTED===$normalized->outcome())return WebhookHttpDisposition::accepted('unsupported_event_type');
  // Preserve the existing read-only baseline exclusion and HTTP policy.
  $scopePayload=json_decode($normalized->receipt()->normalized_payload()??'{}',true);
  $scope=$this->transactions->provider_scope($scopePayload['installation_id']??null,$scopePayload['epoch_identity']??null);
  if($scope!==CommerceBaseline::ELIGIBLE)return $this->scope_disposition($scope);
  // Authentication is established by StripeWebhookRestController before handle().
  // Evidence and operational transactions have separate connections and authority.
  $evidence=$this->evidence->retain($normalized->receipt(),$this->clock->now_mysql());
  if(!in_array($evidence->status,['retained','duplicate'],true))return $this->evidence_disposition($evidence);
  $owner=$evidence->truth?->owner;
  if($owner===null)return WebhookHttpDisposition::failure('unproven_claim',true);
  if($evidence->truth->fence!=='current_ready'||$owner->lifecycle!=='open'||$owner->claim_disposition!=='open')return WebhookHttpDisposition::accepted('provider_identity_neutralized');
  $expected=new CommerceContext($owner->installation_id,$owner->epoch_id,$owner->epoch_identity);
  if(!$this->transactions->supports_event_receipt() || !$this->transactions->begin_commerce_shared($expected))return WebhookHttpDisposition::failure($this->transactions->is_outcome_uncertain()?'transaction_outcome_unknown':'receipt_not_durable');
  $registry=$this->events->registry();$receipt=$normalized->receipt();$context=$this->transactions->commerce_context();
  try {$route=$registry->route($receipt,$context);}catch(\Throwable $error){return WebhookHttpDisposition::failure($this->transactions->rollback()?'registry_unavailable':'transaction_outcome_unknown');}
  if(in_array($route['status'],['scope_rejected','unresolved','conflict'],true)){
   $code=$route['code']??('conflict'===$route['status']?'modern_evidence_conflict':'unproven_claim');
   return $this->transactions->rollback()?$this->scope_disposition($code):WebhookHttpDisposition::failure('transaction_outcome_unknown');
  }
  if('database_error'===$route['status']){return WebhookHttpDisposition::failure($this->transactions->rollback()?'registry_unavailable':'transaction_outcome_unknown');}
  if(in_array($route['status'],array('retired','absent'),true)){
   // No target-epoch or historical operational work; A1 already retained evidence.
   return $this->transactions->rollback()?WebhookHttpDisposition::accepted('provider_identity_neutralized'):WebhookHttpDisposition::failure('transaction_outcome_unknown');
  }
  if((int)($route['claim']->id??0)!==$owner->claim_id)return WebhookHttpDisposition::failure($this->transactions->rollback()?'modern_evidence_conflict':'transaction_outcome_unknown');
  $known=$registry->identity($receipt->provider(),'event',$receipt->provider_event_id());
  if(is_object($known)&&in_array($known->disposition,array('processed','ignored','attention'),true)){
   $operational=$this->events->find_by_provider_event_id($receipt->provider(),$receipt->provider_event_id());
   if(false===$operational){return WebhookHttpDisposition::failure($this->transactions->rollback()?'database_error':'transaction_outcome_unknown');}
   if(null===$operational){
	if($known->disposition==='processed')return WebhookHttpDisposition::failure($this->transactions->rollback()?'continuation_verification_required':'transaction_outcome_unknown');
	return $this->transactions->commit()?WebhookHttpDisposition::accepted('durable_event_replay'):WebhookHttpDisposition::failure('transaction_outcome_unknown');
   }
  }
  if(is_object($known)&&str_starts_with($known->disposition,'neutralized_')){
   return $this->transactions->commit()?WebhookHttpDisposition::accepted('provider_identity_neutralized'):WebhookHttpDisposition::failure('transaction_outcome_unknown');
  }
  try { $stored=$this->events->insert_complete_receipt($normalized->receipt(),$this->clock->now_mysql()); }
  catch(\Throwable $error) { return WebhookHttpDisposition::failure($this->transactions->rollback()?'receipt_not_durable':'transaction_outcome_unknown'); }
  if(!$stored->event() instanceof PaymentEventRecord || in_array($stored->outcome(),array(PaymentEventReceiptResult::DATABASE_FAILURE,PaymentEventReceiptResult::INVALID_INPUT),true)) { return WebhookHttpDisposition::failure($this->transactions->rollback()?'receipt_not_durable':'transaction_outcome_unknown'); }
  $binding=$registry->bind_event($receipt,$route['claim']??null,$this->clock->now_mysql());
  if(!in_array($binding,['bound','conflict'],true)){return WebhookHttpDisposition::failure($this->transactions->rollback()?'registry_unavailable':'transaction_outcome_unknown');}
  if('conflict'===$binding)return WebhookHttpDisposition::failure($this->transactions->rollback()?'modern_evidence_conflict':'transaction_outcome_unknown');

  try {$operation=CommittedOperation::authenticated_receipt(ProviderClaimRecord::from_row($route['claim']),$receipt,$this->clock->now_mysql());}
  catch(\Throwable $error){return WebhookHttpDisposition::failure($this->transactions->rollback()?'continuation_verification_required':'transaction_outcome_unknown');}
  $context=$this->transactions->commerce_context();
  if(!$this->transactions->commit())return WebhookHttpDisposition::failure('transaction_outcome_unknown');
  // Contact stays outside receipt/evidence storage. Financial continuation still verifies the exact raw-body hash.
  $details=$verified['data']['object']['customer_details']??null;
  $customer_email=is_array($details)?($details['email']??null):null;
  $customer_name=is_array($details)?($details['name']??null):null;
  return $this->transactions->with_commerce_context($context,fn()=> $this->process_receipt($normalized,$stored,$operation,$customer_email,$customer_name));
 }
 private function evidence_disposition(ProviderEvidenceResult $result):WebhookHttpDisposition {
  if(in_array($result->status,['foreign','pre_baseline'],true))return WebhookHttpDisposition::accepted($result->status);
  if(in_array($result->status,['unattributable','unresolved'],true))return WebhookHttpDisposition::bad_request('unproven_claim');
  if($result->status==='unsupported')return $this->scope_disposition($result->truth?->reason==='baseline_pending'?'baseline_pending':CommerceBaseline::INVALID);
  if($result->status==='invalid_input')return WebhookHttpDisposition::bad_request('malformed_event');
  if(in_array($result->status,['conflict','ambiguous','corrupt','candidate_mismatch'],true))return WebhookHttpDisposition::failure('modern_evidence_conflict',true);
  return WebhookHttpDisposition::failure($result->status==='reentry_required'?'receipt_not_durable':$result->status);
 }
 private function scope_disposition(string $scope):WebhookHttpDisposition {
  if(in_array($scope,[CommerceBaseline::PRE_BASELINE,CommerceBaseline::FOREIGN],true))return WebhookHttpDisposition::accepted($scope);
  if(in_array($scope,[CommerceBaseline::INVALID,CommerceBaseline::UNKNOWN,'unproven_claim'],true))return WebhookHttpDisposition::bad_request($scope);
  return WebhookHttpDisposition::failure($scope,true);
 }
 private function process_receipt(StripeEventNormalizationResult $normalized,PaymentEventReceiptResult $stored,CommittedOperation $operation,mixed $customer_email,mixed $customer_name):WebhookHttpDisposition {
  $event=$stored->event();
  if(!$event instanceof PaymentEventRecord)return WebhookHttpDisposition::failure('receipt_not_durable');
  if(PaymentEventReceiptResult::DUPLICATE_INTEGRITY_CONFLICT===$stored->outcome())return $this->outcomes->integrity_conflict($event,$operation);
  if(PaymentEventReceiptResult::DATABASE_FAILURE===$stored->outcome()||PaymentEventReceiptResult::INVALID_INPUT===$stored->outcome())return WebhookHttpDisposition::failure('receipt_not_durable');
  $state=$event->processing_status();
  if(in_array($state,array(PaymentEventStatuses::ATTENTION,PaymentEventStatuses::IGNORED),true))return WebhookHttpDisposition::accepted($state);
  if(PaymentEventStatuses::PROCESSING===$state)return $this->outcomes->quarantine_processing($event,operation:$operation);
  if(PaymentEventStatuses::PROCESSED===$state)return $this->processed_probe($event,$operation);
  if(StripeEventNormalizationResult::UNSUPPORTED===$normalized->outcome())return $this->outcomes->ignore($event,$normalized->code(),$operation);
  if(StripeEventNormalizationResult::DETERMINISTIC_ATTENTION===$normalized->outcome())return $this->outcomes->attention($event,$normalized->code(),operation:$operation);
  $association=$this->association->associate($event,$operation);
  if(in_array($association->outcome(),['order_deleted','commerce_reset_fenced'],true))return WebhookHttpDisposition::accepted($association->outcome());
  if(WebhookAssociationResult::UNMATCHED===$association->outcome())return in_array($normalized->outcome(),array(StripeEventNormalizationResult::EXPIRATION,StripeEventNormalizationResult::LIFECYCLE),true)?$this->outcomes->ignore($event,'unmatched_lifecycle_event',$operation):$this->outcomes->attention($event,'unmatched_paid_event',operation:$operation);
  if(WebhookAssociationResult::IDENTITY_CONFLICT===$association->outcome()||WebhookAssociationResult::LIFECYCLE_CONFLICT===$association->outcome())return $this->outcomes->attention($event,'payment_identity_conflict',operation:$operation);
  if(!in_array($association->outcome(),array(WebhookAssociationResult::ASSOCIATED,WebhookAssociationResult::ALREADY_ASSOCIATED),true))return WebhookHttpDisposition::failure($association->outcome());
  if(StripeEventNormalizationResult::LIFECYCLE===$normalized->outcome())return $this->outcomes->ignore($event,$normalized->code(),$operation);
  if(StripeEventNormalizationResult::EXPIRATION===$normalized->outcome())return $this->expiration->expire((int)$association->order_id(),(int)$association->session_id(),$event->id(),$operation);
  $sale=$this->conversion->convert((int)$association->order_id(),(int)$association->session_id(),$event->id(),$operation,$customer_email,$customer_name);$category=SaleConversionResultClassifier::classify($sale->code());
  if($sale->is_success()&&in_array($sale->code(),array('sale_converted','sale_already_converted'),true)){
   $disposition=$this->reconcile_committed_sale($sale,$sale->code(),$operation);
   return $sale->code()==='sale_converted'?$disposition->with_paid_commit():$disposition;
  }
  if(in_array($sale->code(),['order_deleted','commerce_reset_fenced'],true))return WebhookHttpDisposition::accepted($sale->code());
  if(WebhookOutcomeCategories::SUCCESS===$category)return WebhookHttpDisposition::accepted($sale->code());
  if(WebhookOutcomeCategories::OUTCOME_UNKNOWN===$category)return $sale->inspection!==null?WebhookHttpDisposition::inspected($sale->inspection):WebhookHttpDisposition::failure('transaction_outcome_unknown');
  if(WebhookOutcomeCategories::RETRYABLE===$category)return WebhookHttpDisposition::failure($sale->code());
  if($sale->has_confirmed_rollback())return $this->outcomes->after_confirmed_rollback($event,$sale->code(),$operation);
  // Pre-transaction deterministic rejection is not a confirmed rollback.
  return $this->outcomes->attention($event,$sale->code(),operation:$operation);
 }
 private function processed_probe(PaymentEventRecord $event,CommittedOperation $operation):WebhookHttpDisposition {
  $sale=$this->conversion->probe_processed($event,$operation);
  if(in_array($sale->code(),['order_deleted','commerce_reset_fenced'],true))return WebhookHttpDisposition::accepted($sale->code());
  if($sale->is_success()&&$sale->code()==='expiration_already_processed')return WebhookHttpDisposition::accepted('coherent_idempotent_delivery');
  if($sale->is_success()&&$sale->code()==='sale_already_converted')return $this->reconcile_committed_sale($sale,'coherent_idempotent_delivery',$operation);
  return $sale->inspection!==null?WebhookHttpDisposition::inspected($sale->inspection):WebhookHttpDisposition::failure($sale->code());
 }
 /** SaleConversionService returns a confirmed sale COMMIT or a separately fenced read-only proof. */
 private function reconcile_committed_sale(SaleConversionResult $sale,string $accepted_code,CommittedOperation $operation):WebhookHttpDisposition {
  if($this->transactions->is_active())return WebhookHttpDisposition::failure($this->transactions->is_outcome_uncertain()?'transaction_outcome_unknown':'transaction_already_active');
  $result=$this->reconciliation->reconcile((int)$sale->order_id(),$operation);
  // The processed event remains authoritative payment evidence. Returning 500
  // lets a fresh delivery revalidate the sale and retry its separate cart work.
  // Never persist event failure/attention or probe SQL after reconciliation fails.
  if($result->is_retryable())return $result->inspection!==null?WebhookHttpDisposition::inspected($result->inspection):WebhookHttpDisposition::failure($result->code());
  if($result->is_completed()||'attention'===$result->status())return WebhookHttpDisposition::accepted($accepted_code);
  return WebhookHttpDisposition::failure('cart_reconciliation_state_unknown');
 }
}
