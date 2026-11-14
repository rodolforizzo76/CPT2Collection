<?php
/** D4 actual cart/marker atomicity, unknown termination and read-only fresh inspection. */
declare(strict_types=1);
require __DIR__.'/support/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
foreach(['commit_before','commit_after','rollback_before','rollback_after','confirmed_rollback','no_factory'] as $fault){
 $prefix='c2c_d4_fault_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);
 try{
  [$f,$e,$op]=d4_fixture($db);$before=baseline_snapshot($db);$stock=d3_stock($db);$fresh=[];
  $db->write_number=0;$db->wrote=false;$db->faulted=false;
  if(str_starts_with($fault,'commit')||$fault==='no_factory'){$db->fault='COMMIT';$db->after=$fault!=='commit_before';}else {$db->fail_write=2;if($fault!=='confirmed_rollback'){$db->fault='ROLLBACK';$db->after=$fault==='rollback_after';}}
  $service=$fault==='no_factory'?d4_service($db):d4_service($db,$fresh);$out=$service->reconcile($f['order'],$op);
  if($fault==='confirmed_rollback'){
   check($out->code()==='database_error'&&$out->has_confirmed_rollback(),'confirmed rollback distinguished from uncertain rollback');check(baseline_snapshot($db)===$before,'failed intermediate source write rolls back first source');
  }else{
   check($out->code()==='transaction_outcome_unknown'&&!$out->has_confirmed_rollback(),'lost cart termination remains uncertain '.$fault);
   check($out->inspection?->status===($fault==='commit_after'?'logically_completed':'verification_required'),'fresh exact cart inspection '.$fault.': '.($out->inspection?->status??'absent'));
   check($out->inspection->transaction_provenance==='not_provable'&&$db->closed&&$db->poison_sql===0,'discard revokes prior connection without poisoned SQL or invented provenance');
   foreach($fresh as $n){d2_no_dml($n);check($n->closed,'fresh cart inspector disposed');}
   check(d4_service($db)->reconcile($f['order'],$op)->code()==='transaction_outcome_unknown'&&$db->poison_sql===0,'new service cannot query or restore a discarded connection');
   $db=new D4Db($prefix);check(d3_stock($db)===$stock,'cart uncertainty never changes committed physical sale');
   if(!in_array($fault,['commit_after','no_factory'],true))check(baseline_snapshot($db)===$before,'unexecuted cart COMMIT wholly absent');
   else {$snapshot=baseline_snapshot($db);$db->capture=true;$db->captured=[];check(d4_service($db)->reconcile($f['order'],$op)->is_already_terminal(),'fresh duplicate validates terminal cart marker after physically committed loss');d2_no_dml($db);check(baseline_snapshot($db)===$snapshot,'no unsafe second subtraction after lost acknowledgment');}
  }
 }finally{d3_cleanup($db);}
}
for($write=1;$write<=4;++$write){
 $db=new D4Db('c2c_d4_atomic_'.bin2hex(random_bytes(4)).'_');
 try{[$f,$e,$op]=d4_fixture($db);$before=baseline_snapshot($db);$db->write_number=0;$db->fail_write=$write;$out=d4_service($db)->reconcile($f['order'],$op);check(!$out->is_completed()&&$out->has_confirmed_rollback(),'every cart/marker write fails through confirmed rollback '.$write);check(baseline_snapshot($db)===$before,'all source rows, revision and marker atomic at write '.$write);}finally{d3_cleanup($db);}
}
foreach(['delete','reset','erase_cart','erase_source','cart_edit','marker_forged','same_quantity_without_marker'] as $change)foreach([false,true] as $executed){
 $prefix='c2c_d4_erased_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);
 try{
  [$f,$e,$op]=d4_fixture($db);d4_edit($db,$f,4);$stock=d3_stock($db);$db->fault='COMMIT';$db->after=$executed;$db->faulted=false;$fresh=[];
  $db->after_close=function()use($prefix,$f,$change,$executed){
   $other=new D4Db($prefix);$t=new TableNames($other);
   if($change==='delete')d1_delete($other,$f);
   elseif($change==='reset')discovery_finish($other,validation_start($other));
   elseif($change==='erase_cart')sql($other,'DELETE FROM '.$t->carts());
   elseif($change==='erase_source')sql($other,'DELETE FROM '.$t->cart_items());
   elseif($change==='cart_edit')d4_edit($other,$f,7);
   elseif($change==='marker_forged')sql($other,'UPDATE '.$t->orders()." SET cart_reconciliation_code='other'");
   elseif(!$executed){sql($other,'UPDATE '.$t->cart_items().' SET quantity=3,content_version=2 WHERE post_id=77');sql($other,'UPDATE '.$t->carts().' SET content_revision=2');}
   $other->close();
  };
  $out=d4_service($db,$fresh)->reconcile($f['order'],$op);$expected=match($change){'delete'=>'permanently_deleted','reset'=>'fenced',default=>($change==='same_quantity_without_marker'&&$executed?'logically_completed':'verification_required')};
  check($out->code()==='transaction_outcome_unknown'&&$out->inspection?->status===$expected,'recovery evidence '.$change.'/'.(int)$executed.' => '.($out->inspection?->status??'absent'));
  foreach($fresh as $n)d2_no_dml($n);$db=new D4Db($prefix);check(d3_stock($db)===$stock,'destructive/recovery races preserve financial stock');
 }finally{d3_cleanup($db);}
}
foreach(['read','begin','rollback'] as $fault){
 $db=new D4Db('c2c_d4_inspect_fault_'.bin2hex(random_bytes(4)).'_');
 try{[$f,$e,$op]=d4_fixture($db);$db->fault='COMMIT';$db->after=true;$db->faulted=false;$fresh=[];
  $out=d4_service($db,$fresh,function($n)use($fault){if($fault==='read')$n->read_fault='FROM '.$n->prefix.'c2c_carts FORCE INDEX(PRIMARY)';else $n->bookkeeping_failure=$fault==='begin'?'START TRANSACTION':'ROLLBACK';})->reconcile($f['order'],$op);
  check($out->code()==='transaction_outcome_unknown'&&in_array($out->inspection?->status,['database_error','transaction_outcome_unknown'],true),'fresh cart inspection failure remains nonauthorizing '.$fault);foreach($fresh as $n)d2_no_dml($n);
 }finally{d3_cleanup($db);}
}
foreach([false,true] as $after){
 $db=new D4Db('c2c_d4_begin_'.bin2hex(random_bytes(4)).'_');
 try{[$f,$e,$op]=d4_fixture($db);$before=baseline_snapshot($db);$db->wrote=false;$db->fault='START TRANSACTION';$db->after=$after;$db->capture=true;$db->captured=[];$out=d4_service($db)->reconcile($f['order'],$op);check($out->code()==='transaction_outcome_unknown'&&$out->inspection===null,'lost cart BEGIN has no invented stage');d2_no_dml($db);check($db->closed&&$db->poison_sql===0,'unknown admission discards authority');$db=new D4Db($db->prefix);check(baseline_snapshot($db)===$before,'lost BEGIN causes no cart mutation');}finally{d3_cleanup($db);}
}
echo "D4 cart transaction recovery and atomicity checks passed.\n";
