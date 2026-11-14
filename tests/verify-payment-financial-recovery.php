<?php
/** D3 real financial COMMIT/ROLLBACK uncertainty, atomicity and erasure limitations. */
declare(strict_types=1);
require __DIR__.'/support/payment-financial-continuation.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
foreach(['conversion','expiration'] as $stage)foreach(['commit_before','commit_after','rollback_before','rollback_after','confirmed_rollback'] as $fault){
 $prefix='c2c_d3_fault_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);
 try{
  [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');$before=baseline_snapshot($db);$stock=d3_stock($db);$fresh=[];
  $db->write_number=0;$db->wrote=false;$db->faulted=false;
  if(str_starts_with($fault,'commit')){$db->fault='COMMIT';$db->after=$fault==='commit_after';}
  else {$db->fail_write=3;if($fault!=='confirmed_rollback'){$db->fault='ROLLBACK';$db->after=$fault==='rollback_after';}}
  $out=d3_run($db,$stage,$f,$e,$op,$fresh);
  if($fault==='confirmed_rollback'){
   check($out->code()==='database_error'&&($stage==='expiration'||$out->has_confirmed_rollback()),'acknowledged rollback is explicitly distinguished');check($before===baseline_snapshot($db),'all intermediate writes roll back atomically');
  }else{
   check($out->code()==='transaction_outcome_unknown','uncertain financial termination stays unknown '.$stage.'/'.$fault);
   check($out->inspection!==null&&$out->inspection->status===($fault==='commit_after'?'logically_completed':'verification_required'),'fresh financial inspection never recommends replay '.$stage.'/'.$fault);
   check($out->inspection->transaction_provenance==='not_provable'&&$db->closed&&$db->poison_sql===0,'discarded manager never continues; logical completion is not COMMIT provenance');
   foreach($fresh as $n){d2_no_dml($n);check($n->closed,'financial inspection disposed');}
   $db=new D2Db($prefix);
   check(d3_stock($db)===($stage==='conversion'&&$fault==='commit_after'?array_map(static fn($n)=>$n-1,$stock):$stock),'physical stock preserves actual server outcome without replay');
   if($fault!=='commit_after')check(baseline_snapshot($db)===$before,'uncommitted financial state is wholly absent');
  }
 }finally{d3_cleanup($db);}
}
// Fail every dispatched write in the atomic sale, including each product and reservation.
for($write=1;$write<=9;++$write){
 $db=new D2Db('c2c_d3_atomic_'.bin2hex(random_bytes(4)).'_');
 try{[$f,$e,$op]=d3_fixture($db);$before=baseline_snapshot($db);$db->write_number=0;$db->fail_write=$write;$out=d3_run($db,'conversion',$f,$e,$op);check(!$out->is_success()&&$out->has_confirmed_rollback(),'financial write '.$write.' fails through confirmed rollback');check(baseline_snapshot($db)===$before,'complete financial tuple and stock atomic at write '.$write);}finally{d3_cleanup($db);}
}
foreach(['conversion','expiration'] as $stage)foreach(['delete','reset'] as $erase){
 $observed=[];
 foreach([false,true] as $executed){
  $prefix='c2c_d3_erased_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);
  try{
   [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');$stock=d3_stock($db);$db->fault='COMMIT';$db->after=$executed;$db->faulted=false;$fresh=[];
   $db->after_close=function()use($prefix,$f,$erase){$other=new D2Db($prefix);if($erase==='delete')d1_delete($other,$f);else discovery_finish($other,validation_start($other));$other->close();};
   $out=d3_run($db,$stage,$f,$e,$op,$fresh);
   check($out->code()==='transaction_outcome_unknown'&&$out->inspection?->status===($erase==='delete'?'permanently_deleted':'fenced'),'erased financial evidence yields positive boundary, never invented completion');
   $observed[]=[$out->inspection->status,$out->inspection->transaction_provenance,$out->inspection->owner->owner->lifecycle];foreach($fresh as $n)d2_no_dml($n);
   $db=new D2Db($prefix);if($erase==='delete')check($db->get_var("SELECT disposition FROM ".(new TableNames($db))->provider_identities()." WHERE identity_kind='event'")==='neutralized_order_deleted','permanent deletion erases processed-versus-pending Event evidence in both physical histories');check(d3_stock($db)===($stage==='conversion'&&$executed?array_map(static fn($n)=>$n-1,$stock):$stock),'erasure preserves distinct physical financial history');
   $db->capture=true;$db->captured=[];d3_run($db,$stage,$f,$e,$op);d2_no_dml($db);
  }finally{d3_cleanup($db);}
 }
 check($observed[0]===$observed[1],'indistinguishable retained '.$stage.'/'.$erase.' histories do not guess which COMMIT ran');
}
foreach(['read','begin','rollback'] as $fault){
 $prefix='c2c_d3_inspect_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);
 try{
  [$f,$e,$op]=d3_fixture($db);$db->fault='COMMIT';$db->after=true;$db->faulted=false;$fresh=[];
  $out=d3_run($db,'conversion',$f,$e,$op,$fresh,function($n)use($fault){if($fault==='read')$n->read_fault='FROM '.$n->prefix.'c2c_orders ';else {$n->bookkeeping_failure=$fault==='begin'?'START TRANSACTION':'ROLLBACK';}});
  check($out->code()==='transaction_outcome_unknown'&&$out->inspection!==null&&$out->inspection->status!=='logically_completed','fresh inspection failure stays nonauthorizing '.$fault);foreach($fresh as $n)d2_no_dml($n);
 }finally{d3_cleanup($db);}
}
echo "D3 financial recovery and atomicity checks passed.\n";
foreach(['conversion','expiration'] as $stage)foreach([false,true] as $after){
 $prefix='c2c_d3_begin_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);
 try{
  [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');$before=baseline_snapshot($db);$db->wrote=false;$db->fault='START TRANSACTION';$db->after=$after;$db->capture=true;$db->captured=[];$out=d3_run($db,$stage,$f,$e,$op);
  check($out->code()==='transaction_outcome_unknown'&&$out->inspection===null,'unknown financial BEGIN invents no stage postcondition');d2_no_dml($db);check($db->closed&&$db->poison_sql===0,'uncertain admission permanently discarded without stale authority');$db=new D2Db($prefix);check(baseline_snapshot($db)===$before,'lost BEGIN has no financial effect');
 }finally{d3_cleanup($db);}
}
echo "D3 pre-write uncertain admission checks passed.\n";
foreach(['conversion','expiration','processed_replay'] as $stage){
 $prefix='c2c_d3_owner_read_'.bin2hex(random_bytes(4)).'_';$db=new D3Db($prefix);
 try{
  [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');
  if($stage==='processed_replay'){check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'sale before locked read failure');$e=d2_event($db,$e);}
  $before=baseline_snapshot($db);$db->read_fault='FROM '.(new TableNames($db))->provider_claims().' WHERE id = '.$f['claim'].' FOR UPDATE';$db->capture=true;$db->captured=[];
  check(d3_run($db,$stage,$f,$e,$op)->code()==='database_error','failed locked owner read is infrastructure failure, never deterministic attention: '.$stage);d2_no_dml($db);$db->read_fault='';check(baseline_snapshot($db)===$before,'owner read failure preserves financial tuple');
 }finally{d3_cleanup($db);}
}
echo "D3 locked ownership read failures stay nonauthorizing.\n";
