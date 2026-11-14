<?php
/** Exact-owner post-sale cart closure, independent of the financial transaction. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
use Throwable;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-cart-reconciliation-stage-inspection.php';

final class CartReconciliationService {
 private CartReconciliationBoundary $boundary;
 private ?CommittedOperation $operation=null;
 private ?CartReconciliationStageInspection $stage=null;
 public function __construct(private CartRepository $carts,private OrderRepository $orders,private TransactionManager $transactions,private UtcClock $clock,private ?OrderDeletionRegistry $deletions=null,?CartReconciliationBoundary $boundary=null) {
  $this->boundary=$boundary??$carts->reconciliation_boundary();
 }
 /** Original receipt/native DATA is mandatory; a paid Order ID is not provenance. */
 public function reconcile(int $order_id,?CommittedOperation $operation=null):CartReconciliationResult {
  if($this->transactions->is_active())return new CartReconciliationResult('retryable_failure',$this->transactions->is_outcome_uncertain()?'transaction_outcome_unknown':'transaction_already_active');
  $this->operation=$operation;$this->stage=null;
  if(!$this->boundary->is_usable())return $this->unknown();
  if($operation?->receipt===null||$operation->claim->order_id()!==$order_id)return new CartReconciliationResult('retryable_failure','continuation_verification_required');
  try {
   if(!$this->transactions->supports_cart_reconciliation())return new CartReconciliationResult('retryable_failure','transactions_not_supported');
   $truth=$this->boundary->begin($operation,$this->transactions);
   if($truth->status!=='admitted'){
	$code=PaymentEventContinuation::disposition($truth);
	return new CartReconciliationResult(in_array($code,['order_deleted','commerce_reset_fenced'],true)?'completed':'retryable_failure',$code,true);
   }
   // Routing DATA only; recheck the association after taking Cart -> Order locks.
   $context=$this->orders->discover_reconciliation_cart($order_id);
   if(!is_object($context)||$context->cart_id===null)return $this->failure('continuation_verification_required');
   $cart_id=(int)$context->cart_id;
   $cart=$this->carts->find_for_update($cart_id);
   if(!$cart instanceof CartRecord)return $this->failure($cart===false?'database_error':'cart_missing');
   $row=$this->orders->find_reconciliation_for_update($order_id);
   if(!is_object($row))return $this->failure($row===false?'database_error':'order_not_found');
   $order=OrderRecord::from_row($row);
   if($order->cart_id()!==$cart_id||$order->epoch_id()!==$operation->claim->epoch_id()||$cart->epoch_id()!==$order->epoch_id())return $this->failure('cart_context_changed');
   $proof=$this->boundary->validate($operation,$order);
   if($proof->status!=='logically_completed')return $this->failure($proof->status==='database_error'?'database_error':'continuation_verification_required');
   $status=$order->cart_reconciliation_status();
   $terminal=in_array($status,['completed','attention'],true);
   if($terminal){
	$at=$order->cart_reconciliation_updated_at();
	if($at===null||$at<$order->paid_at()||$at>$this->clock->now_mysql()||$order->cart_reconciliation_code()===null||($status==='completed'&&$order->cart_reconciliation_code()!=='cart_reconciled'))return $this->failure('reconciliation_marker_conflict');
   }elseif(!in_array($status,[null,'pending'],true)||$order->cart_reconciliation_code()!==null||$order->cart_reconciliation_updated_at()!==null)return $this->failure('reconciliation_marker_conflict');
   if($order->cart_revision()===null||$order->checkout_generation()===null||$order->checkout_attempt_hash()===null||$cart->content_revision()<$order->cart_revision()
	||($cart->content_revision()===$order->cart_revision()&&$cart->checkout_generation()<$order->checkout_generation()))return $this->failure('cart_revision_conflict');
   if($status==='attention')return $this->terminal($status,$order->cart_reconciliation_code());
   $items=$this->orders->get_items_for_acquisition($order_id);
   if($items===false)return $this->failure();
   if($items===[])return $this->failure('empty_order_snapshot');
   $sources=[];
   foreach($items as $item){
	$id=$item->source_cart_item_id();
	if($item->order_id()!==$order_id||$id===null||$item->source_cart_item_version()===null||isset($sources[$id]))return $this->failure('invalid_source_snapshot');
	$sources[$id]=$item;
   }
   ksort($sources,SORT_NUMERIC);$changes=[];$after_sources=[];
   foreach($sources as $id=>$snapshot){
	$source=$this->carts->find_source_item_for_update($id);
	if($source===false)return $this->failure();
	if($source===null){
	 // A cooperating removal advances the cart revision. Never infer missing history.
	 if($cart->content_revision()===$order->cart_revision())return $this->failure('source_history_unproved');
	 $after_sources[$id]=null;continue;
	}
	if($source->cart_id()!==$cart_id||$source->post_id()!==$snapshot->post_id()||$source->content_version()<$snapshot->source_cart_item_version())return $this->failure('source_identity_conflict');
	if($source->content_version()===$snapshot->source_cart_item_version()&&$source->quantity()!==$snapshot->quantity())return $this->failure('source_quantity_mismatch');
	if($source->content_version()-$snapshot->source_cart_item_version()>$cart->content_revision()-$order->cart_revision())return $this->failure('source_revision_conflict');
	if($terminal){
	 // A surviving source required a prior quantity edit and then K11's own increment.
	 if($source->content_version()-$snapshot->source_cart_item_version()<2)return $this->failure('reconciliation_marker_conflict');
	 continue;
	}
	$remainder=max($source->quantity()-$snapshot->quantity(),0);
	if($remainder>0&&$source->content_version()===PHP_INT_MAX)return $this->failure('source_version_exhausted');
	$changes[]=[$source,$remainder];
	$after_sources[$id]=$remainder===0?null:array_replace(CartReconciliationStageInspection::source($source),['quantity'=>$remainder,'content_version'=>$source->content_version()+1]);
   }
   if($terminal){
	if($cart->content_revision()<=$order->cart_revision())return $this->failure('reconciliation_marker_conflict');
	return $this->terminal($status,$order->cart_reconciliation_code());
   }
   if($changes!==[]&&$cart->content_revision()===PHP_INT_MAX)return $this->failure('cart_revision_exhausted');
   $now=$this->clock->now_mysql();if($now<$order->paid_at())return $this->failure('reconciliation_time_conflict');$after_cart=CartReconciliationStageInspection::cart($cart);
   if($changes!==[])$after_cart=array_replace($after_cart,['content_revision'=>$cart->content_revision()+1,'checkout_generation'=>0,'updated_at'=>$now]);
   $this->stage=new CartReconciliationStageInspection(CartReconciliationStageInspection::order($order),$after_cart,array_map([CartReconciliationStageInspection::class,'item'],$items),$after_sources,$now,'completed','cart_reconciled');
   foreach($changes as [$source,$remainder])if(!$this->carts->reconcile_source_item($source,$remainder,$now))return $this->failure();
   if($changes!==[]&&!$this->carts->advance_content_revision($cart_id,$cart->content_revision(),$now))return $this->failure();
   if(!$this->orders->finish_cart_reconciliation($order_id,'completed','cart_reconciled',$now))return $this->failure();
   return $this->transactions->commit()?new CartReconciliationResult('completed','cart_reconciled'):$this->unknown();
  }catch(Throwable $error){return $this->failure();}
 }
 private function terminal(string $status,string $code):CartReconciliationResult {
  return $this->transactions->rollback()?new CartReconciliationResult($status,$code,true):$this->unknown();
 }
 private function failure(string $code='database_error'):CartReconciliationResult {
  if(!$this->boundary->is_usable())return $this->unknown();
  if(!$this->transactions->is_active())return new CartReconciliationResult('retryable_failure',$code);
  return $this->transactions->rollback()?new CartReconciliationResult('retryable_failure',$code,false,confirmed_rollback:true):$this->unknown();
 }
 private function unknown():CartReconciliationResult {
  $inspection=null;
  if($this->operation!==null&&$this->stage!==null)$inspection=$this->boundary->inspect($this->operation,$this->stage);
  else $this->boundary->discard();
  // Inspection DATA never changes an uncertain transaction into successful payment/cart work.
  return new CartReconciliationResult('retryable_failure','transaction_outcome_unknown',false,$inspection);
 }
}
