<?php
/** D2 exact Event continuation boundary. No financial-stage or replay authority. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-committed-operation-authority.php';
require_once __DIR__.'/class-operational-recovery-inspector.php';
require_once __DIR__.'/class-payment-event-stage-inspection.php';

final class PaymentEventContinuation {
 private ?OperationalRecoveryInspector $recovery;
 public function __construct(private \wpdb $db,private TableNames $tables,?callable $connections=null) {
  $this->recovery=$connections===null?null:new OperationalRecoveryInspector($connections);
 }
 public function begin(?CommittedOperation $operation,PaymentEventRecord $event,TransactionManager $tx):ProviderDiscoveryResult {
  if($operation===null||!self::matches_receipt($operation,$event))return new ProviderDiscoveryResult('verification_required','original_operation_required');
  $truth=$this->classify($operation);
  if($truth->continuation()==='normal_candidate')$truth=(new CommittedOperationAuthority($this->db,$this->tables))->begin($operation,$tx);
  if($truth->status==='transaction_outcome_unknown')$this->discard();
  return $truth;
 }
 /** Financial stages receive the descriptor itself; no unlocked Event lookup selects ownership. */
 public function begin_financial(?CommittedOperation $operation,TransactionManager $tx):ProviderDiscoveryResult {
  if($operation?->receipt===null)return new ProviderDiscoveryResult('verification_required','original_operation_required');
  $truth=$this->classify($operation);
  if($truth->continuation()==='normal_candidate')$truth=(new CommittedOperationAuthority($this->db,$this->tables))->begin($operation,$tx);
  if($truth->status==='transaction_outcome_unknown')$this->discard();
  return $truth;
 }
 /** DATA classification only, on a healthy connection outside any transaction. */
 public function classify(CommittedOperation $operation):ProviderDiscoveryResult {
  return (new CommittedOperationAuthority($this->db,$this->tables))->classify($operation);
 }
 public static function disposition(ProviderDiscoveryResult $truth):string {
  if($truth->status==='transaction_outcome_unknown')return 'transaction_outcome_unknown';
  if($truth->status==='attributed'&&$truth->owner?->lifecycle==='permanently_deleted')return 'order_deleted';
  if($truth->status==='attributed'&&$truth->continuation()==='fenced')return 'commerce_reset_fenced';
  return in_array($truth->status,['conflict','ambiguous'],true)?'payment_identity_conflict':($truth->status==='database_error'?'database_error':'continuation_verification_required');
 }
 /** Immutable receipt fields cannot be filled, replaced or inferred by a continuation. */
 public static function same_receipt(PaymentEventReceipt|PaymentEventRecord $a,PaymentEventReceipt|PaymentEventRecord $b):bool {
  foreach(['provider','provider_event_id','event_type','provider_created_at','livemode','provider_session_id','local_reference','provider_payment_id','currency','amount_minor','payment_status','session_status','payload_sha256','normalized_payload'] as $field)if($a->$field()!==$b->$field())return false;
  return true;
 }
 public static function matches_receipt(CommittedOperation $op,PaymentEventRecord $e):bool {
  return $op->receipt!==null&&self::same_receipt($op->receipt,$e)
   &&(($e->ownership_state()==='unresolved'&&$e->payment_session_id()===null&&$e->epoch_id()===null)
	||($e->ownership_state()==='bound'&&$e->payment_session_id()===$op->claim->payment_session_id()&&$e->epoch_id()===$op->claim->epoch_id()));
 }
 /** Called after control/epoch -> exact order -> exact Session -> Event locks. */
 public function validate(CommittedOperation $op,PaymentEventRecord $supplied,OrderRecord $order,PaymentSessionRecord $session,PaymentEventRecord $locked):bool {
  if(!TransactionManager::context_on($this->db)?->equals($op->expected_context())||!self::business_tuple($op,$order,$session,$locked)
   ||$supplied->id()!==$locked->id()||!self::same_receipt($supplied,$locked))return false;
  // Claim/identity locks are last, in the same order as bind_event (event/payment/session).
  $registry=new ProviderRegistry($this->db,$this->tables);$claim=$registry->claim_by_id($op->claim->id(),true);
  try {if(!is_object($claim)||!$op->claim->same_request_as(ProviderClaimRecord::from_row($claim))||!$registry->matches($claim,$session,$op->expected_context()))return false;}
  catch(\Throwable $error){return false;}
  return self::identities($op,$locked,$registry,true);
 }
 public static function business_tuple(CommittedOperation $op,OrderRecord $o,PaymentSessionRecord $s,PaymentEventRecord $e):bool {
  $c=$op->claim;$request=PaymentProviderSessionRequest::from_snapshot($c->request_snapshot());
  return self::matches_receipt($op,$e)&&$o->id()===$c->order_id()&&$o->epoch_id()===$c->epoch_id()
   &&$s->id()===$c->payment_session_id()&&$s->order_id()===$o->id()&&$s->epoch_id()===$c->epoch_id()
   &&$s->reference()===$c->reference()&&$s->creation_key_hash()===$c->creation_key_hash()&&$s->provider()===$c->provider()
   &&$s->currency()===$request->currency()&&$s->amount_minor()===$request->amount_minor()
   &&($s->provider_session_id()===$e->provider_session_id()||($e->ownership_state()==='unresolved'&&$s->provider_session_id()===null))
   &&($s->provider_payment_id()===null||$e->provider_payment_id()===null||$s->provider_payment_id()===$e->provider_payment_id());
 }
 public static function identities(CommittedOperation $op,PaymentEventRecord $e,ProviderRegistry $registry,bool $lock):bool {
  foreach(['event'=>$e->provider_event_id(),'payment'=>$e->provider_payment_id(),'session'=>$e->provider_session_id()] as $kind=>$value){
   if($value===null)continue;$i=$registry->identity($e->provider(),$kind,$value,$lock);
   if(!is_object($i)||(int)$i->claim_id!==$op->claim->id()||$i->binding_state!=='bound'||$i->evidence_state!=='native'||$i->conflict_code!==null||$i->environment!==$op->claim->environment())return false;
   if($kind==='event'&&($i->payload_sha256!==$e->payload_sha256()||$i->event_type!==$e->event_type()))return false;
   if($kind==='event'){
	$allowed=match($e->processing_status()){'received','retryable_failed','processing'=>['pending'],'ignored'=>['ignored','attention'],'processed'=>['processed','attention'],'attention'=>['attention'],default=>[]};
	if(!in_array($i->disposition,$allowed,true))return false;
   }
  }
  return true;
 }
 public function inspect(CommittedOperation $op,OperationalStageInspection $stage):OperationalInspectionResult {
  if($this->recovery!==null)return $this->recovery->inspect($op,$stage,$this->db);
  $this->discard();
  return new OperationalInspectionResult('verification_required','fresh_connection_factory_required');
 }
 public function discard():void {try{$this->db->close();}catch(\Throwable $error){/* D1.1 revokes authority before driver close, including failure. */}}
 /** D3 must not turn a failed locked read into a deterministic business outcome. */
 public function validation_failure_code():string {return $this->db->last_error!==''?'database_error':'payment_identity_conflict';}
 /** Capture before any DML, while the service owns every required row lock. */
 public function stage(string $stage,OrderRecord $o,PaymentSessionRecord $s,PaymentEventRecord $e,string $at,string $code=''):PaymentEventStageInspection {
  $registry=new ProviderRegistry($this->db,$this->tables);$identities=[];
  foreach(['event'=>$e->provider_event_id(),'payment'=>$e->provider_payment_id(),'session'=>$e->provider_session_id()] as $kind=>$value){
   if($value===null)continue;$i=$registry->identity($e->provider(),$kind,$value,true);
   if(!is_object($i))throw new \RuntimeException('Missing locked Event evidence.');
   $identities[$kind]=get_object_vars($i);
  }
  return new PaymentEventStageInspection($stage,$o,$s,$e,$at,$code,$identities);
 }
}
