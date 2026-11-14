<?php
/** Cart semantics migrated from paid-Order-only fixtures to exact native sale provenance. */
declare(strict_types=1);
require __DIR__.'/support/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CartRepository,OrderRepository,CartReconciliationStageInspection};
foreach(['unchanged','increased','decreased_after_increase','all_removed','remove_readd','unrelated','version_exhausted','revision_exhausted','invalid_quantity','invalid_cart_integer','missing_snapshot','unpaid','attention_terminal','completed_marker_conflict'] as $case){
 $db=new D4Db('c2c_d4_cart_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d4_fixture($db);$t=new TableNames($db);
  if($case==='increased')d4_edit($db,$f,5);
  elseif($case==='decreased_after_increase'){d4_edit($db,$f,5);d4_edit($db,$f,1);}
  elseif(in_array($case,['all_removed','remove_readd'],true)){
   $c=new CartRepository($db,$t);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'cart removal owns normal authority');$cart=$c->find_for_update($f['cart']);check($c->delete_items_locked($f['cart'])===2&&$c->advance_content_revision($f['cart'],$cart->content_revision(),gmdate('Y-m-d H:i:s'))&&$tx->commit(),'user removes original sources and advances revision');
   if($case==='remove_readd')d4_edit($db,$f,5);
  }
  elseif($case==='unrelated'){$c=new CartRepository($db,$t);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'unrelated addition admitted');$cart=$c->find_for_update($f['cart']);check($c->add_item_quantity_locked($f['cart'],999,7)!==false&&$c->advance_content_revision($f['cart'],$cart->content_revision(),gmdate('Y-m-d H:i:s'))&&$tx->commit(),'unrelated addition advances cart revision');}
  elseif($case==='version_exhausted'){d4_edit($db,$f,5);sql($db,'UPDATE '.$t->cart_items().' SET content_version='.PHP_INT_MAX.' WHERE post_id=77');sql($db,'UPDATE '.$t->carts().' SET content_revision='.PHP_INT_MAX);}
  elseif($case==='revision_exhausted')sql($db,'UPDATE '.$t->carts().' SET content_revision='.PHP_INT_MAX);
  elseif($case==='invalid_quantity')sql($db,'UPDATE '.$t->cart_items().' SET quantity=0 WHERE post_id=77');
  elseif($case==='invalid_cart_integer')sql($db,'UPDATE '.$t->carts().' SET content_revision=18446744073709551615');
  elseif($case==='missing_snapshot')sql($db,'DELETE FROM '.$t->order_items().' WHERE post_id=77');
  elseif($case==='unpaid')sql($db,'UPDATE '.$t->orders()." SET status='pending',paid_at=NULL");
  elseif($case==='attention_terminal')sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_status='attention',cart_reconciliation_code='invalid_source_snapshot',cart_reconciliation_updated_at=paid_at");
  elseif($case==='completed_marker_conflict')sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_status='completed',cart_reconciliation_code='cart_reconciled',cart_reconciliation_updated_at=paid_at");
  $before=baseline_snapshot($db);$revision=(int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1');$db->capture=true;$db->captured=[];$out=d4_service($db)->reconcile($f['order'],$op);
  if(in_array($case,['version_exhausted','revision_exhausted','invalid_quantity','invalid_cart_integer','missing_snapshot','unpaid','completed_marker_conflict'],true)){
   check($out->is_retryable()&&!$out->is_completed(),'inconsistent cart/sale stays nonmutating '.$case);if($case==='version_exhausted')check($out->code()==='source_version_exhausted','version-exhaustion guard is exercised after coherent revision validation');d2_no_dml($db);check(baseline_snapshot($db)===$before,'all-or-nothing refusal '.$case);
  }elseif($case==='attention_terminal'){
   check($out->status()==='attention'&&$out->is_already_terminal(),'existing attention remains independently validated terminal DATA');d2_no_dml($db);check(baseline_snapshot($db)===$before,'terminal attention has no cart subtraction');
  }else{
   check($out->is_completed(),'exact native cart semantics '.$case);
   $revision_after=(int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1');
   check($revision_after===$revision+($case==='all_removed'||$case==='remove_readd'?0:1),'exactly one revision for actual source changes, zero for already removed sources');
   if($case==='increased')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=77')===4,'positive remainder advances version once');
   if($case==='remove_readd')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=77')===5,'readded row never selected by product identity');
   if($case==='unrelated')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=999')===7,'unrelated content retained');
   $snapshot=baseline_snapshot($db);$db->captured=[];check(d4_service($db)->reconcile($f['order'],$op)->is_already_terminal(),'independently validated duplicate never subtracts again');d2_no_dml($db);check(baseline_snapshot($db)===$snapshot,'duplicate preserves quantities, revisions and timestamps');
  }
 }finally{d3_cleanup($db);}
}
$db=new D4Db('c2c_d4_guards_'.bin2hex(random_bytes(4)).'_');
try{
 [$f,$e,$op]=d4_fixture($db);$t=new TableNames($db);$c=new CartRepository($db,$t);$o=new OrderRepository($db,$t);$tx=new TransactionManager($db,$t);$before=baseline_snapshot($db);
 check($tx->begin_commerce_shared(),'guard probes own normal transaction');$cart=$c->find_for_update($f['cart']);$o->find_for_update($f['order']);$item=$c->find_source_item_for_update(1);
 sql($db,'UPDATE '.$t->cart_items().' SET quantity=2 WHERE id=1');check(!$c->reconcile_source_item($item,0,gmdate('Y-m-d H:i:s')),'source DELETE CAS refuses a changed locked quantity');check($tx->rollback()&&baseline_snapshot($db)===$before,'stale source guard probe rolls back completely');
 check($tx->begin_commerce_shared(),'marker guard probe owns normal transaction');$c->find_for_update($f['cart']);$o->find_for_update($f['order']);sql($db,'UPDATE '.$t->orders()." SET cart_reconciliation_code='conflict' WHERE id=".$f['order']);check(!$o->finish_cart_reconciliation($f['order'],'completed','cart_reconciled',gmdate('Y-m-d H:i:s')),'marker CAS refuses contradictory pending metadata');check($tx->rollback()&&baseline_snapshot($db)===$before,'marker guard probe rolls back completely');
 check($tx->begin_commerce_shared(),'caller owns unrelated transaction');$nested=new \RodolfoRizzo\Cpt2Collection\CartReconciliationService($c,$o,$tx,new \RodolfoRizzo\Cpt2Collection\UtcClock());$out=$nested->reconcile($f['order'],$op);check($out->code()==='transaction_already_active'&&$tx->is_commerce_admitted(),'K11 refuses nesting without ending caller authority');check($tx->rollback(),'caller independently ends own transaction');
 $db->capture=true;$db->captured=[];check(d4_service($db)->reconcile($f['order'])->code()==='continuation_verification_required','legacy paid-Order-only call cannot mutate');d2_no_dml($db);
}finally{d3_cleanup($db);}
echo "Exact-owner cart semantics and repository guards passed.\n";
