<?php
/** D3 financial logical postconditions. Trusted read-only inspection; never stock replay. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-operational-stage-inspection.php';

final class PaymentFinancialStageInspection implements OperationalStageInspection {
 public function __construct(private readonly int $event_id,private readonly string $stage,private readonly ?string $expected_code=null) {
  if($event_id<=0||!in_array($stage,['conversion','expiration','processed_replay','paid_before_terminal'],true))throw new \InvalidArgumentException('Invalid financial inspection.');
 }
 public function inspect(CommittedOperation $operation,\wpdb $db,TableNames $tables):OperationalInspectionResult {
  if(!TransactionManager::continuation_inspection_on($db)||$this->stage==='paid_before_terminal')return new OperationalInspectionResult('verification_required','financial_inspection_fence_required');
  return $this->read($operation,$db,$tables);
 }
 /** Same pure predicates for an independently admitted, locked normal probe. No inspection authority is minted. */
 public function inspect_current(CommittedOperation $operation,\wpdb $db,TableNames $tables):OperationalInspectionResult {
  if(!TransactionManager::admitted_on($db)||!TransactionManager::context_on($db)?->equals($operation->expected_context()))return new OperationalInspectionResult('verification_required','financial_normal_fence_required');
  return $this->read($operation,$db,$tables);
 }
 private function read(CommittedOperation $operation,\wpdb $db,TableNames $tables):OperationalInspectionResult {
  if(!(new TransactionManager($db,$tables))->supports_sale_conversion())return new OperationalInspectionResult('database_error','financial_storage_unavailable');
  $rows=[];
  foreach(['orders'=>$operation->claim->order_id(),'payment_sessions'=>$operation->claim->payment_session_id(),'payment_events'=>$this->event_id] as $table=>$id){
   $db->last_error='';$row=$db->get_row($db->prepare('SELECT * FROM '.$tables->$table().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',$id));
   if($db->last_error!=='')return new OperationalInspectionResult('database_error','financial_read_failed');
   if($row===null)return new OperationalInspectionResult('verification_required','financial_tuple_absent');
   $rows[$table]=$row;
  }
  $o=OrderRecord::from_row($rows['orders']);$s=PaymentSessionRecord::from_row($rows['payment_sessions']);$e=PaymentEventRecord::from_row($rows['payment_events']);
  if(!PaymentEventContinuation::business_tuple($operation,$o,$s,$e)||$e->ownership_state()!=='bound'||$e->payment_session_id()!==$s->id())return new OperationalInspectionResult('conflict','financial_owner_or_receipt_changed');
  $registry=new ProviderRegistry($db,$tables);
  if(!PaymentEventContinuation::identities($operation,$e,$registry,false))return new OperationalInspectionResult($db->last_error!==''?'database_error':'conflict','financial_identity_unproved');
  $before_terminal=$this->stage==='paid_before_terminal';
  if($before_terminal&&!in_array($e->processing_status(),['received','retryable_failed'],true))return new OperationalInspectionResult('conflict','preterminal_event_advanced');
  if(!$before_terminal&&$e->processing_status()!==PaymentEventStatuses::PROCESSED){
   return new OperationalInspectionResult(in_array($e->processing_status(),['ignored','attention'],true)?'conflict':'verification_required','financial_completion_unproved');
  }
  if(!$before_terminal&&($e->processed_at()===null||$e->attempt_count()<1||$e->last_attempt_at()===null||$e->error_code()!==null||$e->error_detail()!==null))return new OperationalInspectionResult('conflict','financial_terminal_metadata_inconsistent');
  $expiration=$e->event_type()==='checkout.session.expired';
  if($expiration&&!self::expiration_receipt($e))return new OperationalInspectionResult('conflict','expiration_receipt_lifecycle_conflict');
  if(($this->stage==='expiration'&&!$expiration)||($this->stage==='conversion'&&$expiration))return new OperationalInspectionResult('conflict','financial_event_type_conflict');
  if($this->expected_code!==null&&$this->expected_code!==$e->result_code())return new OperationalInspectionResult('conflict','financial_outcome_changed');
  if(!$before_terminal&&$expiration){
   if(!in_array($e->result_code(),['checkout_session_expired','already_expired','sale_already_completed'],true))return new OperationalInspectionResult('conflict','expiration_terminal_outcome_conflict');
   if($e->result_code()!=='sale_already_completed'&&!($this->stage==='processed_replay'&&$o->status()===OrderStatuses::PAID&&$s->status()===PaymentSessionStatuses::COMPLETED)){
	if($o->status()===OrderStatuses::PAID&&$s->status()===PaymentSessionStatuses::COMPLETED)return new OperationalInspectionResult('verification_required','expiration_followed_by_sale');
	return new OperationalInspectionResult($o->status()===OrderStatuses::EXPIRED&&$o->expired_at()!==null&&$s->status()===PaymentSessionStatuses::EXPIRED&&$s->expired_at()!==null&&$s->active_slot()===null&&$o->paid_at()===null&&$s->payment_confirmed_at()===null&&$s->completed_at()===null&&$o->expired_at()===$s->expired_at()?'logically_completed':'conflict','expiration_tuple');
   }
  } elseif(!$before_terminal&&!in_array($e->result_code(),['sale_converted','sale_already_converted'],true))return new OperationalInspectionResult('conflict','sale_terminal_outcome_conflict');
  // An expiration processed after a completed sale still needs the independent paid tuple.
  if(!$expiration&&!self::paid_receipt($e))return new OperationalInspectionResult('conflict','sale_receipt_not_paid');
  $code=self::paid_code($o,$s,$expiration?null:$e);
  if($code!==null)return new OperationalInspectionResult('conflict',$code);
  $db->last_error='';$item_rows=$db->get_results($db->prepare('SELECT * FROM '.$tables->order_items().' WHERE order_id=%d ORDER BY id',$o->id()));
  if($db->last_error!==''||$item_rows===null)return new OperationalInspectionResult('database_error','financial_items_read_failed');
  $items=array_map(static fn(object $r):OrderItem=>OrderItem::from_row($r),$item_rows);
  if(!self::items_match($operation,$o,$items))return new OperationalInspectionResult('conflict','sale_items_absent');
  $ids=array_map(static fn(OrderItem $i):int=>$i->id(),$items);$marks=implode(',',array_fill(0,count($ids),'%d'));
  $db->last_error='';$reservation_rows=$db->get_results($db->prepare('SELECT * FROM '.$tables->reservations()." WHERE order_id=%d OR order_item_id IN ($marks) ORDER BY id",$o->id(),...$ids));
  if($db->last_error!==''||$reservation_rows===null)return new OperationalInspectionResult('database_error','financial_reservations_read_failed');
  foreach($reservation_rows as $r)if((int)$r->epoch_id!==$operation->claim->epoch_id()||$r->converted_at!==$o->paid_at())return new OperationalInspectionResult('conflict','financial_reservation_epoch_conflict');
  $reservations=array_map(static fn(object $r):ReservationRecord=>ReservationRecord::from_row($r),$reservation_rows);
  if(!(new ConversionReservationSetValidator())->validate_converted($o->id(),$items,$reservations)->is_valid())return new OperationalInspectionResult('conflict','sale_reservations_inconsistent');
  // The completing Event may differ from this duplicate or expiration Event. Prove it exactly.
  $db->last_error='';$original=$db->get_results($db->prepare('SELECT * FROM '.$tables->payment_events().' FORCE INDEX(provider_event) WHERE provider=%s AND provider_event_id=%s LIMIT 2',$s->provider(),$s->provider_event_id()));
  if($db->last_error!==''||$original===null)return new OperationalInspectionResult('database_error','sale_source_event_read_failed');
  if(count($original)!==1)return new OperationalInspectionResult($original===[]?'verification_required':'conflict','sale_source_event_absent_or_ambiguous');
  $source=PaymentEventRecord::from_row($original[0]);
  if($source->payment_session_id()!==$s->id()||$source->epoch_id()!==$operation->claim->epoch_id()||$source->ownership_state()!=='bound'||$source->processing_status()!=='processed'||$source->result_code()!=='sale_converted'||$source->processed_at()!==$o->paid_at()||$source->attempt_count()<1||$source->last_attempt_at()===null||$source->error_code()!==null||$source->error_detail()!==null||!self::paid_receipt($source)||self::paid_code($o,$s,$source)!==null)return new OperationalInspectionResult('conflict','sale_source_event_inconsistent');
  $payload=json_decode($source->normalized_payload()??'{}',true);
  if(($payload['installation_id']??null)!==$operation->claim->installation_id()||($payload['epoch_identity']??null)!==$operation->claim->epoch_identity()||($payload['integration']??null)!=='cpt2collection')return new OperationalInspectionResult('conflict','sale_source_scope_conflict');
  if(!PaymentEventContinuation::identities($operation,$source,$registry,false))return new OperationalInspectionResult($db->last_error!==''?'database_error':'conflict','sale_source_identity_unproved');
  return new OperationalInspectionResult('logically_completed',$expiration?'sale_completed_before_expiration':'coherent_converted_sale');
 }
 /** The immutable provider money/quantity snapshot must still describe the item set being sold. */
 public static function items_match(CommittedOperation $op,OrderRecord $o,array $items):bool {
  $request=PaymentProviderSessionRequest::from_snapshot($op->claim->request_snapshot());$lines=$request->line_items();
  if($items===[]||count($items)!==count($lines)||$request->currency()!==$o->currency()||$request->amount_minor()!==$o->total_minor())return false;
  $total=0;
  foreach(array_values($items) as $n=>$item){
   if(!$item instanceof OrderItem||$item->order_id()!==$o->id()||$item->currency()!==$o->currency()||$item->unit_price_minor()!==$lines[$n]->unit_amount_minor()||$item->quantity()!==$lines[$n]->quantity()||($item->unit_price_minor()>0&&$item->quantity()>intdiv(PHP_INT_MAX,$item->unit_price_minor())))return false;
   $line=$item->unit_price_minor()*$item->quantity();if($line!==$item->line_total_minor()||$line!==$item->line_subtotal_minor()||$line>PHP_INT_MAX-$total)return false;$total+=$line;
  }
  return $total===$o->total_minor()&&$total===$o->subtotal_minor();
 }

 public static function expiration_receipt(PaymentEventRecord $e):bool {
  $payload=json_decode($e->normalized_payload()??'{}',true);
  return $e->event_type()==='checkout.session.expired'&&$e->session_status()==='expired'&&$e->payment_status()!=='paid'&&($payload['mode']??null)==='payment';
 }
 public static function paid_receipt(PaymentEventRecord $e):bool {
  $payload=json_decode($e->normalized_payload()??'{}',true);
  return ($payload['mode']??null)==='payment'&&($payload['object']??null)==='checkout.session'&&empty($payload['identity_invalid'])&& in_array($e->event_type(),['checkout.session.completed','checkout.session.async_payment_succeeded'],true)&&$e->payment_status()==='paid'&&$e->session_status()==='complete'&&$e->provider_payment_id()!==null;
 }
 public static function paid_code(OrderRecord $o,PaymentSessionRecord $s,?PaymentEventRecord $e):?string {
  if($o->status()!==OrderStatuses::PAID||$o->paid_at()===null||$s->status()!==PaymentSessionStatuses::COMPLETED||$s->active_slot()!==null||$s->payment_confirmed_at()===null||$s->completed_at()===null||$s->provider_event_id()===null||$s->provider_payment_id()===null)return 'inconsistent_conversion_state';
  if($o->paid_at()!==$s->payment_confirmed_at()||$o->paid_at()!==$s->completed_at())return 'inconsistent_conversion_state';
  if($o->currency()!==$s->currency()||$s->confirmed_currency()!==$o->currency())return 'payment_currency_mismatch';
  if($o->total_minor()!==$s->amount_minor()||$s->confirmed_amount_minor()!==$o->total_minor())return 'payment_amount_mismatch';
  if($e!==null&&($e->provider_payment_id()!==$s->provider_payment_id()||$e->provider_session_id()!==$s->provider_session_id()||$e->local_reference()!==$s->reference()||$e->provider()!==$s->provider()))return 'payment_identity_conflict';
  if($e!==null&&$e->currency()!==$s->confirmed_currency())return 'payment_currency_mismatch';
  if($e!==null&&$e->amount_minor()!==$s->confirmed_amount_minor())return 'payment_amount_mismatch';
  return null;
 }
}
