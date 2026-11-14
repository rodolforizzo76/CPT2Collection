<?php
/** D4 exact operation, cart semantics, independent paid proof and K14 owner edges. */
declare(strict_types=1);
require __DIR__.'/support/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommittedOperation};
foreach(['normal','edited','removed','readded','unrelated','new_event','processed_replay','idempotent_sale'] as $case){
 $db=new D4Db('c2c_d4_ok_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d4_fixture($db);$t=new TableNames($db);$stock=d3_stock($db);$before=(int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1');
  if($case==='edited')d4_edit($db,$f,4);
  if(in_array($case,['removed','readded'],true)){
   $tx=new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t);$c=new \RodolfoRizzo\Cpt2Collection\CartRepository($db,$t);check($tx->begin_commerce_shared(),'source removal admitted');$cart=$c->find_for_update(1);check($c->delete_item_locked(1,77)===1&&$c->advance_content_revision(1,$cart->content_revision(),gmdate('Y-m-d H:i:s')),'source removal advances revision');
   if($case==='readded')check($c->add_item_quantity_locked(1,77,5)!==false,'readded product gets new source identity');check($tx->commit(),'source edit commits');
  }
  if($case==='unrelated')sql($db,'INSERT INTO '.$t->cart_items()." (cart_id,post_id,quantity,content_version,created_at,updated_at) VALUES(1,999,3,0,NOW(),NOW())");
  if($case==='new_event'){[$e,$op]=d3_event($db,$f,'checkout.session.async_payment_succeeded','evt_d4_second');check(d3_run($db,'conversion',$f,$e,$op)->code()==='sale_already_converted','second authenticated Event validates same legitimate claim');}
  if($case==='processed_replay')check(d3_run($db,'processed_replay',$f,$e,$op)->is_success(),'K12 returns DATA after confirmed rollback');
  if($case==='idempotent_sale')check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'idempotent financial completion returns DATA');
  $before=(int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1');$service=d4_service($db);$db->capture=true;$db->captured=[];$r=$service->reconcile($f['order'],$op);
  if($case==='normal')d4_lock_order($db);
  check($r->is_completed()&&$r->code()==='cart_reconciled','D4 matching owner reconciles '.$case);check(d3_stock($db)===$stock,'K11 never changes physical stock');
  check((int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1')===$before+1,'one cart revision per reconciliation');
  if($case==='edited')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=77')===3,'subtract only original ordered quantity from edited source');
  if($case==='readded')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=77')===5,'new source row survives old sale');
  if($case==='unrelated')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=999')===3,'unrelated content untouched');
  $snapshot=baseline_snapshot($db);$db->captured=[];$r=$service->reconcile($f['order'],$op);check($r->is_completed()&&$r->is_already_terminal(),'duplicate independently validates completed reconciliation');d2_no_dml($db);check(baseline_snapshot($db)===$snapshot,'second delivery never subtracts twice');
 }finally{d3_cleanup($db);}
}
foreach(['missing_operation','native_request_only','wrong_claim','superseded','missing_cart','missing_source','source_quantity','source_version','source_product','source_cart','snapshot_missing','snapshot_duplicate','snapshot_quantity','cart_revision','source_revision','marker_completed','marker_residual','marker_code','marker_pending_code','money','missing_reservations','missing_event','provider_identity','environment','ambiguous','reset','retired','retired_later','deleted','deleted_retired'] as $case){
 $db=new D4Db('c2c_d4_no_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d4_fixture($db);$t=new TableNames($db);$supplied=$op;
  if($case==='missing_operation')$supplied=null;
  elseif($case==='native_request_only')$supplied=CommittedOperation::native_request(discovery_claim($db,$f),gmdate('Y-m-d H:i:s'));
  elseif($case==='wrong_claim'){$g=validation_order($db);webhook_activate($db,$g);[, $supplied]=d3_event($db,$g,'checkout.session.completed','evt_wrong');}
  elseif($case==='superseded')d4_later($db,$f);
  elseif($case==='missing_cart')sql($db,'DELETE FROM '.$t->carts());
  elseif($case==='missing_source')sql($db,'DELETE FROM '.$t->cart_items());
  elseif($case==='source_quantity')sql($db,'UPDATE '.$t->cart_items().' SET quantity=5 WHERE post_id=77');
  elseif($case==='source_version')sql($db,'UPDATE '.$t->order_items().' SET source_cart_item_version=10 WHERE post_id=77');
  elseif($case==='source_product')sql($db,'UPDATE '.$t->cart_items().' SET post_id=999 WHERE post_id=77');
  elseif($case==='source_cart')sql($db,'UPDATE '.$t->cart_items().' SET cart_id=999 WHERE post_id=77');
  elseif($case==='snapshot_missing')sql($db,'UPDATE '.$t->order_items().' SET source_cart_item_id=NULL,source_cart_item_version=NULL WHERE post_id=77');
  elseif($case==='snapshot_duplicate')sql($db,'UPDATE '.$t->order_items().' SET source_cart_item_id=1');
  elseif($case==='snapshot_quantity')sql($db,'UPDATE '.$t->order_items().' SET quantity=2,line_total_minor=200,line_subtotal_minor=200 WHERE post_id=77');
  elseif($case==='cart_revision')sql($db,'UPDATE '.$t->orders().' SET cart_revision=1');
  elseif($case==='source_revision')sql($db,'UPDATE '.$t->cart_items().' SET content_version=1,quantity=3 WHERE post_id=77');
  elseif($case==='marker_completed')sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_status='completed',cart_reconciliation_code='cart_reconciled',cart_reconciliation_updated_at=paid_at");
  elseif($case==='marker_residual'){sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_status='completed',cart_reconciliation_code='cart_reconciled',cart_reconciliation_updated_at=paid_at");sql($db,'UPDATE '.$t->cart_items().' SET content_version=1');sql($db,'UPDATE '.$t->carts().' SET content_revision=1');}
  elseif($case==='marker_code')sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_status='completed',cart_reconciliation_code='wrong',cart_reconciliation_updated_at=paid_at");
  elseif($case==='marker_pending_code')sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_code='cart_reconciled'");
  elseif($case==='money')sql($db,'UPDATE '.$t->payment_sessions().' SET confirmed_amount_minor=900');
  elseif($case==='missing_reservations')sql($db,'DELETE FROM '.$t->reservations());
  elseif($case==='missing_event')sql($db,'DELETE FROM '.$t->payment_events());
  elseif($case==='provider_identity')sql($db,'UPDATE '.$t->provider_identities()." SET binding_state='quarantined',conflict_code='identity_conflict' WHERE identity_kind='payment'");
  elseif($case==='environment')sql($db,'UPDATE '.$t->provider_identities()." SET environment='live' WHERE identity_kind='event'");
  elseif($case==='ambiguous'){ $r=get_object_vars($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']));unset($r['id']);$r['reference']=bin2hex(random_bytes(16));$r['creation_key_hash']=hash('sha256',random_bytes(16));$r['idempotency_key']='c2c_ps_'.$r['reference'];$db->insert($t->provider_claims(),$r,array_fill(0,count($r),'%s')); }
  elseif($case==='reset')validation_start($db);
  elseif($case==='retired')discovery_finish($db,validation_start($db));
  elseif($case==='retired_later'){discovery_finish($db,validation_start($db));validation_start($db);}
  elseif($case==='deleted_retired'){d1_delete($db,$f);discovery_finish($db,validation_start($db));}
  elseif($case==='deleted')d1_delete($db,$f);
  $before=baseline_snapshot($db);$service=d4_service($db);$db->capture=true;$db->captured=[];$r=$service->reconcile($f['order'],$supplied);
  check(in_array($case,['reset','retired','retired_later','deleted','deleted_retired'],true)?$r->code()===(in_array($case,['deleted','deleted_retired'],true)?'order_deleted':'commerce_reset_fenced'):!$r->is_completed(),'exact K11 refuses or positively fences '.$case.' ('.$r->code().')');d2_no_dml($db);check(baseline_snapshot($db)===$before,'zero cart/marker/financial DML on '.$case);
 }finally{d3_cleanup($db);}
}
echo "D4 exact original-operation and cart integrity checks passed.\n";
// A later legitimate attempt owns the same Order without adopting the earlier claim.
$db=new D4Db('c2c_d4_attempts_'.bin2hex(random_bytes(4)).'_');
try{
 validation_schema($db);sql($db,"INSERT INTO {$db->posts} VALUES(78,'collection_item')");sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(78,'_cpt2collection_stock_quantity','20')");$f=validation_order($db);$second=d1_second_attempt($db,$f);webhook_activate($db,$second);[$event,$operation]=d3_event($db,$second);
 check(d3_run($db,'conversion',$second,$event,$operation)->is_success()&&d4_service($db)->reconcile($second['order'],$operation)->is_completed(),'exact second native attempt reconciles a shared Order legitimately');
 [$old,$original]=d3_event($db,$f,'checkout.session.completed','evt_d4_superseded');$before=baseline_snapshot($db);$db->capture=true;$db->captured=[];check(d4_service($db)->reconcile($f['order'],$original)->code()==='continuation_verification_required','old attempt cannot use completed cart marker of the second attempt');d2_no_dml($db);check(baseline_snapshot($db)===$before,'superseded old claim cannot change second-attempt cart completion');
}finally{d3_cleanup($db);}
echo "D4 exact second-attempt reconciliation checks passed.\n";
