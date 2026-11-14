<?php
/** Exact Checkout expiration; reservations intentionally remain evidence. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH')||exit;
require_once __DIR__.'/class-payment-financial-stage-inspection.php';
final class PaymentExpirationService {
 private PaymentEventContinuation $continuation;
 public function __construct(private OrderRepository $orders,private PaymentSessionRepository $sessions,private PaymentEventRepository $events,private TransactionManager $tx,private PaymentEventOutcomeService $outcomes,private UtcClock $clock,?OrderDeletionRegistry $deletions=null,?PaymentEventContinuation $continuation=null){$this->continuation=$continuation??$events->continuation();}
 public function expire(int $order_id,int $session_id,int $event_id,?CommittedOperation $operation=null):WebhookHttpDisposition {
  if($operation?->receipt===null||$operation->claim->order_id()!==$order_id||$operation->claim->payment_session_id()!==$session_id||$operation->receipt->event_type()!=='checkout.session.expired')return WebhookHttpDisposition::failure('continuation_verification_required');
  if($this->tx->is_active())return WebhookHttpDisposition::failure($this->tx->is_outcome_uncertain()?'transaction_outcome_unknown':'transaction_already_active');
  $stage=null;
  try {
   $admission=$this->continuation->begin_financial($operation,$this->tx);
   if($admission->status!=='admitted'){
	$code=PaymentEventContinuation::disposition($admission);
	return in_array($code,['order_deleted','commerce_reset_fenced'],true)?WebhookHttpDisposition::accepted($code):WebhookHttpDisposition::failure($code);
   }
   if(!$this->tx->supports_sale_conversion())return $this->rollback_failure('transactions_not_supported',$operation,$stage);
   $o=$this->orders->find_for_update($order_id);
   $s=$o instanceof OrderRecord?$this->sessions->find_for_update($session_id):false;
   if($s instanceof PaymentSessionRecord){
	$later=$this->sessions->has_later_attempt_for_update($order_id,$session_id);
	if($later===null)return $this->rollback_failure('database_error',$operation,$stage);
	if($later)return $this->rollback_failure('continuation_verification_required',$operation,$stage);
   }
   $e=$s instanceof PaymentSessionRecord?$this->events->find_for_update($event_id):false;
   if(!$o instanceof OrderRecord||!$s instanceof PaymentSessionRecord||!$e instanceof PaymentEventRecord)return $this->rollback_failure('continuation_verification_required',$operation,$stage);
   if(!$this->continuation->validate($operation,$e,$o,$s,$e))return $this->rollback_failure($this->continuation->validation_failure_code(),$operation,$stage);
   if($e->ownership_state()!=='bound'||$e->payment_session_id()!==$s->id()||!PaymentFinancialStageInspection::expiration_receipt($e))return $this->rollback_failure('payment_identity_conflict',$operation,$stage);
   if($o->currency()!==$s->currency()||($e->currency()!==null&&$e->currency()!==$s->currency())||$o->total_minor()!==$s->amount_minor()||($e->amount_minor()!==null&&$e->amount_minor()!==$s->amount_minor()))return $this->rollback_failure('expiration_money_conflict',$operation,$stage);
   if($e->processing_status()===PaymentEventStatuses::PROCESSED){
	$stage=new PaymentFinancialStageInspection($e->id(),'expiration');
	$inspection=$this->events->inspect_financial($operation,$stage);
	if($inspection->status!=='logically_completed')return $this->rollback_failure($inspection->status==='database_error'?'database_error':'expiration_verification_required',$operation,$stage);
	return $this->tx->rollback()?WebhookHttpDisposition::accepted('coherent_idempotent_delivery'):$this->unknown($operation,$stage);
   }
   // Preserve a different terminal outcome without claiming that it expired the payment.
   if(in_array($e->processing_status(),[PaymentEventStatuses::IGNORED,PaymentEventStatuses::ATTENTION],true))return $this->tx->rollback()?WebhookHttpDisposition::accepted($e->processing_status()):$this->unknown($operation,$stage);
   if($e->processing_status()===PaymentEventStatuses::PROCESSING){
	if(!$this->tx->rollback())return $this->unknown($operation,$stage);
	return $this->outcomes->quarantine_processing($e,operation:$operation);
   }
   if(!in_array($e->processing_status(),[PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED],true))return $this->rollback_failure('event_state_conflict',$operation,$stage);
   $normal=$o->status()===OrderStatuses::AWAITING_PAYMENT&&$s->status()===PaymentSessionStatuses::ACTIVE&&$s->active_slot()===1&&$o->expired_at()===null&&$s->expired_at()===null;
   $expired=$o->status()===OrderStatuses::EXPIRED&&$o->expired_at()!==null&&$s->status()===PaymentSessionStatuses::EXPIRED&&$s->expired_at()!==null&&$o->expired_at()===$s->expired_at()&&$s->active_slot()===null;
   $paid=$o->status()===OrderStatuses::PAID&&$s->status()===PaymentSessionStatuses::COMPLETED;
   if($paid){
	$proof=$this->events->inspect_financial($operation,new PaymentFinancialStageInspection($e->id(),'paid_before_terminal'));
	if($proof->status!=='logically_completed')return $this->rollback_failure($proof->status==='database_error'?'database_error':($proof->status==='conflict'?'inconsistent_conversion_state':'continuation_verification_required'),$operation,$stage);
   }
   if((!$normal&&!$expired&&!$paid)||(!$paid&&($o->paid_at()!==null||$s->payment_confirmed_at()!==null||$s->completed_at()!==null||$s->failed_at()!==null||$s->cancelled_at()!==null))){
	if(!$this->tx->rollback())return $this->unknown($operation,$stage);
	return $this->outcomes->after_confirmed_rollback($e,'expiration_state_conflict',$operation);
   }
   $code=$normal?'checkout_session_expired':($expired?'already_expired':'sale_already_completed');
   $stage=new PaymentFinancialStageInspection($e->id(),'expiration',$code);$at=$this->clock->now_mysql();
   if(!$this->events->mark_processing($e->id(),$e->processing_status(),$at)){
	if(!$this->tx->rollback())return $this->unknown($operation,$stage);
	return $this->outcomes->after_confirmed_rollback($e,'processing_acquisition_conflict',$operation,true);
   }
   if($normal&&(!$this->orders->update_status($o->id(),OrderStatuses::AWAITING_PAYMENT,OrderStatuses::EXPIRED,$at,null,null,$at)||!$this->sessions->transition_terminal($s->id(),PaymentSessionStatuses::ACTIVE,PaymentSessionStatuses::EXPIRED,$at)))return $this->rollback_failure('database_error',$operation,$stage);
   if(!$this->events->mark_terminal($e->id(),PaymentEventStatuses::PROCESSING,PaymentEventStatuses::PROCESSED,$code,$at))return $this->rollback_failure('database_error',$operation,$stage);
   $registry=$this->events->registry();$claim=$registry->claim_by_id($operation->claim->id(),true);
   if(!is_object($claim)||!$registry->matches($claim,$s,$this->tx->commerce_context())||'bound'!==$registry->bind_event($e,$claim,$at)||!$registry->terminal($e->provider(),$e->provider_event_id(),'processed'))return $this->rollback_failure('payment_identity_conflict',$operation,$stage);
   return $this->tx->commit()?WebhookHttpDisposition::accepted('expiration_processed'):$this->unknown($operation,$stage);
  }catch(\Throwable $error){return $this->tx->is_active()?$this->rollback_failure('database_error',$operation,$stage):WebhookHttpDisposition::failure('database_error');}
 }
 private function rollback_failure(string $code,CommittedOperation $op,?PaymentFinancialStageInspection $stage):WebhookHttpDisposition{return $this->tx->rollback()?WebhookHttpDisposition::failure($code):$this->unknown($op,$stage);}
 private function unknown(CommittedOperation $op,?PaymentFinancialStageInspection $stage):WebhookHttpDisposition {
  if($stage!==null)return WebhookHttpDisposition::inspected($this->continuation->inspect($op,$stage));
  $this->continuation->discard();return WebhookHttpDisposition::failure('transaction_outcome_unknown');
 }
}
