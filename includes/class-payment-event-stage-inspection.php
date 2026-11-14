<?php
/** D2 association/outcome semantic postconditions. Read-only under D1 inspection. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-operational-stage-inspection.php';

final class PaymentEventStageInspection implements OperationalStageInspection {
 private readonly array $identities;
 public function __construct(
  public readonly string $stage,private readonly OrderRecord $order,private readonly PaymentSessionRecord $session,
  private readonly PaymentEventRecord $event,private readonly string $at,private readonly string $code,array $identities
 ) {
  if(!in_array($stage,['association','ignored','attention','quarantine_processing','integrity_conflict'],true))throw new \InvalidArgumentException('Unsupported Event inspection.');
  $this->identities=$identities;
 }
 /** Fixed record accessors; snapshots include every persisted record field. */
 private static function facts(PaymentEventRecord|PaymentSessionRecord $record):array {
  $out=[];
  foreach(get_class_methods($record) as $method){
   if(in_array($method,['__construct','from_row','is_active_at'],true))continue;
   $out[$method]=$record->$method();
  }
  return $out;
 }
 public function inspect(CommittedOperation $operation,\wpdb $db,TableNames $tables):OperationalInspectionResult {
  if(!(new TransactionManager($db,$tables))->supports_payment_event_processing())return new OperationalInspectionResult('database_error','event_stage_storage_unavailable');
  $rows=[];
  foreach(['orders'=>$operation->claim->order_id(),'payment_sessions'=>$operation->claim->payment_session_id(),'payment_events'=>$this->event->id()] as $table=>$id){
   $db->last_error='';$row=$db->get_row($db->prepare('SELECT * FROM '.$tables->$table().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',$id));
   if($db->last_error!=='')return new OperationalInspectionResult('database_error','event_stage_read_failed');
   if($row===null)return new OperationalInspectionResult('verification_required','event_stage_tuple_absent');
   $rows[$table]=$row;
  }
  $o=OrderRecord::from_row($rows['orders']);$s=PaymentSessionRecord::from_row($rows['payment_sessions']);$e=PaymentEventRecord::from_row($rows['payment_events']);
  if(!PaymentEventContinuation::business_tuple($operation,$o,$s,$e)||!PaymentEventContinuation::same_receipt($this->event,$e))return new OperationalInspectionResult('conflict','event_stage_owner_or_receipt_changed');
  if(serialize($o)!==serialize($this->order))return new OperationalInspectionResult('verification_required','event_stage_order_advanced');
  $registry=new ProviderRegistry($db,$tables);$actual=[];
  foreach($this->identities as $kind=>$before){
   $i=$registry->identity($operation->claim->provider(),$kind,$before['identity_value']);
   if($i===false)return new OperationalInspectionResult('database_error','event_stage_identity_read_failed');
   if($i===null)return new OperationalInspectionResult('verification_required','event_stage_identity_absent');
   $actual[$kind]=get_object_vars($i);
  }
  $beforeEvent=self::facts($this->event);$beforeSession=self::facts($this->session);
  $afterEvent=$beforeEvent;$afterSession=$beforeSession;$afterIdentities=$this->identities;
  if($this->stage==='association'){
   $afterEvent['payment_session_id']=$operation->claim->payment_session_id();$afterEvent['epoch_id']=$operation->claim->epoch_id();$afterEvent['ownership_state']='bound';
   if($beforeSession['provider_session_id']===null){$afterSession['provider_session_id']=$e->provider_session_id();$afterSession['updated_at']=$this->at;}
  } else {
   $terminal=$this->stage==='ignored'?'ignored':'attention';
   if($this->stage!=='integrity_conflict'){
	$afterEvent['processing_status']=$terminal;$afterEvent['result_code']=$this->code;$afterEvent['processed_at']=$this->at;
	if($terminal==='attention')$afterEvent['attention_at']=$this->at;
	if($this->stage!=='quarantine_processing'){$afterEvent['error_code']=null;$afterEvent['error_detail']=null;}
   }
   if(in_array($this->stage,['attention','integrity_conflict'],true)&&$this->event->payment_session_id()!==null){
	$afterSession['reconciliation_required_at']=$this->at;$afterSession['reconciliation_code']=$this->code;$afterSession['reconciliation_event_id']=$e->id();$afterSession['updated_at']=$this->at;
   }
   $afterIdentities['event']['disposition']=$terminal;
  }
  if(self::facts($e)===$afterEvent&&self::facts($s)===$afterSession&&$actual===$afterIdentities)return new OperationalInspectionResult('logically_completed','exact_event_stage_postcondition');
  if(self::facts($e)===$beforeEvent&&self::facts($s)===$beforeSession&&$actual===$this->identities)return new OperationalInspectionResult('reentry_eligible','exact_event_stage_before_state');
  if(in_array($e->processing_status(),['processed','ignored','attention'],true)&&($e->processing_status()!==$afterEvent['processing_status']||$e->result_code()!==$afterEvent['result_code']))return new OperationalInspectionResult('conflict','different_terminal_event_outcome');
  return new OperationalInspectionResult('verification_required','event_stage_changed_or_partial');
 }
}
