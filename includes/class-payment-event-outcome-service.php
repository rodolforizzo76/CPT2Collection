<?php
/** D2 Event outcomes: exact fresh authority and locked semantic transitions. */
declare(strict_types=1); namespace RodolfoRizzo\Cpt2Collection; defined('ABSPATH')||exit;
final class PaymentEventOutcomeService {
 private PaymentEventContinuation $continuation;
 public function __construct(private OrderRepository $orders,private PaymentSessionRepository $sessions,private PaymentEventRepository $events,private TransactionManager $tx,private UtcClock $clock,?PaymentEventContinuation $continuation=null){$this->continuation=$continuation??$events->continuation();}
 public function ignore(PaymentEventRecord $event,string $code,?CommittedOperation $operation=null):WebhookHttpDisposition{return $this->run($event,'ignored',$code,false,$operation);}
 public function attention(PaymentEventRecord $event,string $code,bool $confirmed_rollback=false,?CommittedOperation $operation=null):WebhookHttpDisposition{return $this->run($event,'attention',$code,$confirmed_rollback,$operation);}
 public function integrity_conflict(PaymentEventRecord $event,?CommittedOperation $operation=null):WebhookHttpDisposition{return $this->run($event,'integrity_conflict','duplicate_integrity_conflict',false,$operation);}
 public function quarantine_processing(PaymentEventRecord $event,string $code='anomalous_processing',?CommittedOperation $operation=null):WebhookHttpDisposition{return $this->run($event,'quarantine_processing',$code,false,$operation);}
 /** Trusted call sites invoke only after an acknowledged ROLLBACK, never unknown END.
  * The fresh locked Event selects the outcome; rollback is no business-success proof. */
 public function after_confirmed_rollback(PaymentEventRecord $event,string $code,?CommittedOperation $op,bool $processing_only=false):WebhookHttpDisposition {
  return $this->run($event,$processing_only?'processing_decision':'attention',$code,true,$op,true);
 }
 private function run(PaymentEventRecord $event,string $stage,string $code,bool $rollback,?CommittedOperation $op,bool $fresh_decision=false):WebhookHttpDisposition {
  $inspection=null;
  try {
  $admission=$this->continuation->begin($op,$event,$this->tx);
  if($admission->status!=='admitted'){
   $reason=PaymentEventContinuation::disposition($admission);
   return in_array($reason,['order_deleted','commerce_reset_fenced'],true)?WebhookHttpDisposition::accepted($reason):WebhookHttpDisposition::failure($reason);
  }
  if(!$this->tx->supports_payment_event_processing())return $this->failure('terminalization_failed');
  $o=$this->orders->find_for_update($op->claim->order_id());
  $s=$o instanceof OrderRecord?$this->sessions->find_for_update($op->claim->payment_session_id()):false;
  $e=$s instanceof PaymentSessionRecord?$this->events->find_for_update($event->id()):false;
  if(!$o instanceof OrderRecord||!$s instanceof PaymentSessionRecord||!$e instanceof PaymentEventRecord)return $this->failure('database_error');
  if(!$this->continuation->validate($op,$event,$o,$s,$e))return $this->failure('payment_identity_conflict');
  $state=$e->processing_status();
  if($fresh_decision)$event=$e;
  if($stage==='processing_decision'&&!in_array($state,[PaymentEventStatuses::PROCESSING,PaymentEventStatuses::PROCESSED,PaymentEventStatuses::IGNORED,PaymentEventStatuses::ATTENTION],true))return $this->failure('processing_acquisition_conflict');
  if($stage==='processing_decision')$stage='quarantine_processing';
  if($stage==='integrity_conflict'){
   if($state===PaymentEventStatuses::PROCESSING)$stage='quarantine_processing';
   elseif(in_array($state,[PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED],true))$stage='attention';
   elseif($state===PaymentEventStatuses::ATTENTION)return $this->acknowledge('duplicate_integrity_conflict');
   elseif(!in_array($state,[PaymentEventStatuses::PROCESSED,PaymentEventStatuses::IGNORED],true)||$e->payment_session_id()===null)return $this->failure('duplicate_integrity_not_recorded');
   if($stage==='integrity_conflict'&&$s->reconciliation_required_at()!==null&&$s->reconciliation_code()===$code&&$s->reconciliation_event_id()===$e->id()){
	$identity=$this->events->registry()->identity($e->provider(),'event',$e->provider_event_id(),true);
	if(is_object($identity)&&$identity->disposition==='attention')return $this->acknowledge($code);
   }
  }elseif(in_array($state,[PaymentEventStatuses::PROCESSED,PaymentEventStatuses::IGNORED,PaymentEventStatuses::ATTENTION],true)){
   return $this->acknowledge($e->result_code()??'terminal');
  }elseif($state===PaymentEventStatuses::PROCESSING&&$stage!=='quarantine_processing'){
   $stage='quarantine_processing';$code='anomalous_processing';
  }
  if($state!==$event->processing_status()||$e->attempt_count()!==$event->attempt_count()||$e->last_attempt_at()!==$event->last_attempt_at())return $this->failure('event_state_conflict');
  if($stage==='quarantine_processing'&&($state!==PaymentEventStatuses::PROCESSING||$e->attempt_count()<1))return $this->failure('processing_quarantine_failed');
  if(in_array($stage,['ignored','attention'],true)&&!in_array($state,[PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED],true))return $this->failure('event_state_conflict');
  $at=$this->clock->now_mysql();$inspection=$this->continuation->stage($stage,$o,$s,$e,$at,$code);
  if($stage==='ignored')$ok=$this->events->mark_terminal($e->id(),$state,PaymentEventStatuses::IGNORED,$code,$at);
  elseif($stage==='attention'){
   $r=$rollback?$this->events->mark_attention_after_confirmed_rollback($e->id(),$state,$e->attempt_count(),$e->last_attempt_at(),$e->payload_sha256(),$code,$at):$this->events->mark_source_attention($e->id(),$state,$e->attempt_count(),$e->last_attempt_at(),$e->payload_sha256(),$code,$at);
   $ok=in_array($r->outcome(),[PaymentEventAttentionResult::TRANSITIONED,PaymentEventAttentionResult::ALREADY_ATTENTION],true);
  }elseif($stage==='quarantine_processing')$ok=$this->events->quarantine_anomalous_processing($e->id(),$code,$at);
  else $ok=true;
  if(!$ok)return $this->failure('terminalization_failed',$op,$inspection);
  if(in_array($stage,['attention','integrity_conflict'],true)&&$e->payment_session_id()!==null&&!$this->sessions->mark_reconciliation_required($s->id(),$at,$code,$e->id()))return $this->failure('terminalization_failed',$op,$inspection);
  $terminal=$stage==='ignored'?'ignored':'attention';
  if(!$this->events->registry()->terminal($e->provider(),$e->provider_event_id(),$terminal))return $this->failure('registry_unavailable',$op,$inspection);
  if(!$this->tx->commit())return $this->unknown($op,$inspection);
  return WebhookHttpDisposition::accepted($code);
  } catch(\Throwable $error) {return $this->failure('database_error',$op,$inspection);}
 }
 private function acknowledge(string $code):WebhookHttpDisposition{return $this->tx->rollback()?WebhookHttpDisposition::accepted($code):$this->failure_after_unknown();}
 private function failure(string $code,?CommittedOperation $op=null,?PaymentEventStageInspection $stage=null):WebhookHttpDisposition {
  if($this->tx->rollback())return WebhookHttpDisposition::failure($code);
  return $op!==null&&$stage!==null?$this->unknown($op,$stage):$this->failure_after_unknown();
 }
 private function failure_after_unknown():WebhookHttpDisposition {$this->continuation->discard();return WebhookHttpDisposition::failure('transaction_outcome_unknown');}
 private function unknown(CommittedOperation $op,PaymentEventStageInspection $stage):WebhookHttpDisposition {
  $inspection=$this->continuation->inspect($op,$stage);
  // No replay or further stage is dispatched, even when logical completion is proved.
  return WebhookHttpDisposition::inspected($inspection);
 }
}
