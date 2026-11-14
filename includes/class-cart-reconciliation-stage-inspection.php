<?php
/** K11 immutable semantic postconditions, captured before the first cart/marker write. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-operational-stage-inspection.php';

final class CartReconciliationStageInspection implements OperationalStageInspection {
 public function __construct(
  private readonly array $order,
  private readonly array $cart,
  private readonly array $items,
  private readonly array $sources,
  private readonly string $at,
  private readonly string $status,
  private readonly string $code
 ) {}
 public static function order(OrderRecord $r):array {
  $out=[];foreach(['id','epoch_id','cart_id','cart_revision','checkout_generation','checkout_attempt_hash','status','currency','subtotal_minor','total_minor','created_at','updated_at','paid_at','cancelled_at','expired_at','failed_at'] as $k)$out[$k]=$r->$k();return $out;
 }
 public static function cart(CartRecord $r):array {
  $out=[];foreach(['id','epoch_id','token_hash','content_revision','checkout_generation','created_at','updated_at','last_activity_at','expires_at'] as $k)$out[$k]=$r->$k();return $out;
 }
 public static function item(OrderItem $r):array {
  $out=[];foreach(['id','order_id','post_id','source_cart_item_id','source_cart_item_version','quantity','currency','unit_price_minor','line_subtotal_minor','line_total_minor','item_name','created_at'] as $k)$out[$k]=$r->$k();return $out;
 }
 public static function source(CartItem $r):array {
  $out=[];foreach(['id','cart_id','post_id','quantity','content_version'] as $k)$out[$k]=$r->$k();return $out;
 }
 public function inspect(CommittedOperation $operation,\wpdb $db,TableNames $tables):OperationalInspectionResult {
  if(!TransactionManager::continuation_inspection_on($db))return new OperationalInspectionResult('verification_required','cart_inspection_fence_required');
  if(!(new TransactionManager($db,$tables))->supports_cart_reconciliation())return new OperationalInspectionResult('database_error','cart_inspection_storage_unavailable');
  if($operation->receipt===null||$operation->claim->order_id()!==$this->order['id'])return new OperationalInspectionResult('conflict','cart_inspection_original_mismatch');
  // The exclusive inspection control fence excludes every cooperating cart/financial writer.
  $db->last_error='';$events=$db->get_results($db->prepare('SELECT id FROM '.$tables->payment_events().' FORCE INDEX(provider_event) WHERE provider=%s AND provider_event_id=%s LIMIT 2',$operation->receipt->provider(),$operation->receipt->provider_event_id()));
  if($db->last_error!==''||$events===null)return new OperationalInspectionResult('database_error','cart_event_read_failed');
  if(count($events)!==1)return new OperationalInspectionResult('verification_required','cart_event_unavailable');
  $proof=(new PaymentFinancialStageInspection((int)$events[0]->id,'processed_replay'))->inspect($operation,$db,$tables);
  if($proof->status!=='logically_completed')return $proof;
  $db->last_error='';$later=$db->get_row($db->prepare('SELECT id FROM '.$tables->payment_sessions().' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d LIMIT 1',$operation->claim->order_id(),$operation->claim->payment_session_id()));
  if($db->last_error!=='')return new OperationalInspectionResult('database_error','cart_attempt_read_failed');
  if($later!==null)return new OperationalInspectionResult('conflict','cart_attempt_superseded');
  $db->last_error='';$o=$db->get_row($db->prepare('SELECT * FROM '.$tables->orders().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',$this->order['id']));
  if($db->last_error!=='')return new OperationalInspectionResult('database_error','cart_order_read_failed');
  if($o===null)return new OperationalInspectionResult('verification_required','cart_order_absent');
  if(self::order(OrderRecord::from_row($o))!==$this->order)return new OperationalInspectionResult('verification_required','cart_order_advanced');
  // Quantities alone cannot establish completion, nor can financial completion.
  if($o->cart_reconciliation_status!==$this->status||$o->cart_reconciliation_code!==$this->code||$o->cart_reconciliation_updated_at!==$this->at)return new OperationalInspectionResult('verification_required','cart_marker_unproved');
  $db->last_error='';$c=$db->get_row($db->prepare('SELECT * FROM '.$tables->carts().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',$this->cart['id']));
  if($db->last_error!=='')return new OperationalInspectionResult('database_error','cart_read_failed');
  if($c===null||self::cart(CartRecord::from_row($c))!==$this->cart)return new OperationalInspectionResult('verification_required','cart_postcondition_unproved');
  $db->last_error='';$rows=$db->get_results($db->prepare('SELECT * FROM '.$tables->order_items().' WHERE order_id=%d ORDER BY id',$this->order['id']));
  if($db->last_error!==''||$rows===null)return new OperationalInspectionResult('database_error','cart_snapshot_read_failed');
  if(array_map(static fn($r)=>self::item(OrderItem::from_row($r)),$rows)!==$this->items)return new OperationalInspectionResult('conflict','cart_snapshot_changed');
  foreach($this->sources as $id=>$expected){
   $db->last_error='';$r=$db->get_row($db->prepare('SELECT id,cart_id,post_id,quantity,content_version FROM '.$tables->cart_items().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',$id));
   if($db->last_error!=='')return new OperationalInspectionResult('database_error','cart_source_read_failed');
   if(($r===null?null:self::source(CartItem::from_row($r)))!==$expected)return new OperationalInspectionResult('verification_required','cart_source_postcondition_unproved');
  }
  return new OperationalInspectionResult('logically_completed','exact_cart_and_marker_postcondition');
 }
}
