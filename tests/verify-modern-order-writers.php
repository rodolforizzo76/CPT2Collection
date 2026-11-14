<?php
/** Modern repository write contract; no legacy fixture escape hatch. */
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CartRepository,OrderRepository,OrderDeletionRegistry,OrderDeletionRepository,OrderDeletionService,CartReconciliationService,TransactionManager,TableNames,UtcClock};
$db=new BaselineWriteProbeDb('c2c_writers_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);$tx=new TransactionManager($db,$t);$now=gmdate('Y-m-d H:i:s');
try {
 baseline_schema($db);check(CommerceBaselineMigration::run($db)&&CommerceBaselinePurgeMigration::run($db),'modern writer fixture completes pristine v16 baseline');
 // Existing v19 deletion lookup prerequisite; no source-shape assertion changes.
 sql($db,'ALTER TABLE '.$t->order_deletions().' ADD KEY original_order_id(original_order_id)');
 check($tx->begin_commerce_shared(),'modern writer admitted');$c=new CartRepository($db,$t);$o=new OrderRepository($db,$t);
 $cart=$c->create(hash('sha256','writer cart'),$now,$now,$now,'2030-01-01 00:00:00');
 foreach([[null,0,0,hash('sha256','a')],[$cart->id(),null,0,hash('sha256','a')],[$cart->id(),0,null,hash('sha256','a')],[$cart->id(),0,0,null]] as [$cid,$rev,$gen,$attempt]) {
  $w=$db->attempted_writes;$count=$db->get_var('SELECT COUNT(*) FROM '.$t->orders());
  check(false===$o->create($cid,'pending','EUR',100,100,$now,$now,$attempt,$rev,$gen),'missing modern order ownership rejected');
  check($db->attempted_writes===$w&&$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===$count,'invalid order produces no attempted insert or partial row');
 }
 $order=$o->create($cart->id(),'pending','EUR',100,100,$now,$now,hash('sha256','valid'),0,0);
 check($order!==false&&$order->cart_revision()===0&&$order->checkout_generation()===0&&$order->checkout_attempt_hash()===hash('sha256','valid'),'modern ownership durably persisted');
 sql($db,"INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,content_version,created_at,updated_at) VALUES({$cart->id()},77,1,0,'$now','$now')");$source=$db->insert_id;
 foreach([[null,null],[$source,null],[null,0]] as [$sid,$version]) {
  $w=$db->attempted_writes;
  check(false===$o->insert_item($order->id(),77,'Item',100,1,100,100,'EUR',$now,$sid,$version),'missing immutable source identity rejected');
  check($w===$db->attempted_writes&&$db->get_var('SELECT COUNT(*) FROM '.$t->order_items())==='0','invalid snapshot produces no attempted insert or partial item');
 }
 $item=$o->insert_item($order->id(),77,'Item',100,1,100,100,'EUR',$now,$source,0);
 check($item!==false&&$item->source_cart_item_id()===$source&&$item->source_cart_item_version()===0,'immutable source persisted');
 check($o->update_status($order->id(),'pending','paid',$now,$now)&&$tx->commit(),'shape fixture marks sale committed without claiming provider/stock coverage');
 $reconcile=new CartReconciliationService($c,$o,$tx,new UtcClock());$out=$reconcile->reconcile($order->id());
 check($out->is_retryable()&&$out->code()==='continuation_verification_required','modern source shape without exact original operation cannot authorize reconciliation');
 check($reconcile->reconcile($order->id())->is_retryable()&&$db->get_var('SELECT cart_reconciliation_status FROM '.$t->orders())==='pending','repeated shape-only call leaves marker pending');
 $del=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),$tx,new UtcClock());$stock=$db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77');
 check($del->delete($order->id())->code()==='deleted','modern order deletes');
 check($db->get_var('SELECT checkout_disposition FROM '.$t->order_deletions())==='revision','modern deletion uses revision ownership, never legacy_revision');
 check($del->delete($order->id())->code()==='already_deleted'&&$stock===$db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77'),'deletion replay and stock non-restoration preserved');
} finally {if($tx->is_active())$tx->rollback();baseline_cleanup($db);}
