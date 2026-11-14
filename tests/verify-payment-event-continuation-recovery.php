<?php
/** D2 real COMMIT/ROLLBACK uncertainty, discard and fresh stage-specific inspection. */
declare(strict_types=1);
require __DIR__.'/support/payment-event-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
foreach(['association','ignored','attention','quarantine_processing','integrity_conflict'] as $stage){
 foreach(['begin_before','begin_after','commit_before','commit_after','rollback_before','rollback_after','confirmed_rollback'] as $fault){
  $prefix='c2c_d2_recover_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);try{
   [$f,$e,$op]=d2_fixture($db);
   if($stage!=='association')$e=d2_associate($db,$e,$op);
   if($stage==='quarantine_processing')$e=d2_processing($db,$e);
   if($stage==='integrity_conflict'){[, $outcomes]=d2_services($db);check($outcomes->ignore($e,'original_terminal',$op)->is_accepted(),'terminal Event before uncertain integrity alarm');$e=d2_event($db,$e);}
   $before=baseline_snapshot($db);$fresh=[];[$a,$o,$tx]=d2_services($db,$fresh);
   $db->wrote=false;$db->write_number=0;$db->capture=true;$db->captured=[];
   if($fault==='confirmed_rollback')$db->fail_write=2;
   else {$db->fault=str_starts_with($fault,'begin')?'START TRANSACTION':(str_starts_with($fault,'commit')?'COMMIT':'ROLLBACK');$db->after=str_ends_with($fault,'after');if(str_starts_with($fault,'rollback'))$db->fail_write=2;}
   $out=$stage==='association'?$a->associate($e,$op):d2_outcome($o,$stage,$e,$op);
   $inspection=$out->inspection;$code=$stage==='association'?$out->outcome():$out->code();
   if($fault==='confirmed_rollback'){
	check($code!=='transaction_outcome_unknown'&&!$tx->is_outcome_uncertain()&&!$tx->is_active(),'confirmed rollback is distinct for '.$stage);
	check($before===baseline_snapshot($db),'confirmed rollback removes all partial stage effects');
   } else {
	check($code==='transaction_outcome_unknown'&&$tx->is_outcome_uncertain()&&TransactionManager::context_on($db)===null,'uncertain '.$fault.' revokes authority: '.$stage);
	check($db->closed&&$db->poison_sql===0,'prior connection irreversibly discarded without SQL');
	if(!str_starts_with($fault,'begin')){
	 check($inspection!==null&&$inspection->status===($fault==='commit_after'?'logically_completed':'reentry_eligible'),'fresh complete '.$stage.' facts classify '.$fault.' as '.($inspection?->status??'missing'));
	 check($inspection->transaction_provenance==='not_provable','semantic completion never proves transaction causation');
	 check(count($fresh)===1&&$fresh[0]->closed,'one genuinely fresh inspection connection is closed');d2_no_dml($fresh[0]);
	}else check($inspection===null&&$fresh===[],'pre-write lost BEGIN fabricates no stage postcondition');
	$db=new D2Db($prefix);
	if($fault!=='commit_after')check($before===baseline_snapshot($db),'physical rollback/discard preserves complete before state');
   }
  }finally{if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
 }
}
foreach(['association','ignored','attention','quarantine_processing','integrity_conflict'] as $stage){foreach(['reset','delete'] as $erase){foreach([false,true] as $physical){
 $prefix='c2c_d2_erase_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);try{
  [$f,$e,$op]=d2_fixture($db);if($stage!=='association')$e=d2_associate($db,$e,$op);
  if($stage==='quarantine_processing')$e=d2_processing($db,$e);
  if($stage==='integrity_conflict'){[, $o]=d2_services($db);check($o->ignore($e,'original_terminal',$op)->is_accepted(),'terminal before erasure');$e=d2_event($db,$e);}
  $fresh=[];[$a,$o]=d2_services($db,$fresh);$db->fault='COMMIT';$db->after=$physical;$db->write_number=0;$db->wrote=false;
  $db->after_close=function()use($prefix,$erase,$f){$actor=new D2Db($prefix);if($erase==='delete')d1_delete($actor,$f);else discovery_finish($actor,validation_start($actor));$actor->close();};
  $out=$stage==='association'?$a->associate($e,$op):d2_outcome($o,$stage,$e,$op);
  check($out->inspection?->status===($erase==='delete'?'permanently_deleted':'fenced'),'fresh inspection gives destruction precedence over '.$stage.' '.($physical?'committed':'uncommitted').' state');
  check($out->inspection->transaction_provenance==='not_provable','erased stage COMMIT provenance remains unknown');foreach($fresh as $n)d2_no_dml($n);
 }finally{if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
}}}
// A competing legitimate outcome after physical rollback cannot prove the lost stage.
$prefix='c2c_d2_competing_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);try{
 [$f,$e,$op]=d2_fixture($db);$e=d2_associate($db,$e,$op);$fresh=[];[, $o]=d2_services($db,$fresh);$db->fault='COMMIT';$db->after=false;$db->write_number=0;$db->wrote=false;
 $db->after_close=function()use($prefix,$e,$op){$other=new D2Db($prefix);[, $o]=d2_services($other);check($o->ignore($e,'competing_ignored',$op)->is_accepted(),'independent legitimate competing outcome commits before recovery');$other->close();};
 $out=$o->attention($e,'lost_attention',operation:$op);check($out->inspection?->status==='conflict'&&$out->inspection->transaction_provenance==='not_provable','different terminal outcome cannot prove attention COMMIT or permit replay');foreach($fresh as $c)d2_no_dml($c);
}finally{if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
foreach(['attention','processing_decision'] as $decision)foreach(['current','reset','deleted'] as $boundary){
 $db=new D2Db('c2c_d2_k13_'.bin2hex(random_bytes(4)).'_');try{
  [$f,$e,$op]=d2_fixture($db);$e=d2_associate($db,$e,$op);if($decision==='processing_decision')$e=d2_processing($db,$e);
  $tx=new TransactionManager($db,new TableNames($db));check($tx->begin_commerce_shared()&&$tx->rollback(),'K13 actual confirmed rollback before separate decision');
  if($boundary==='reset')validation_start($db);elseif($boundary==='deleted')d1_delete($db,$f);
  [, $o]=d2_services($db);$db->capture=true;$db->captured=[];$out=$o->after_confirmed_rollback($e,'confirmed_stage_failure',$op,$decision==='processing_decision');check($out->is_accepted(),'K13 exact locked decision: '.$decision.'/'.$boundary);if($boundary!=='current')d2_no_dml($db);$db->capture=false;
 }finally{baseline_cleanup($db);}
}


foreach(['association','ignored','attention','quarantine_processing','integrity_conflict'] as $stage)foreach(['read','begin','rollback'] as $failure){
 $prefix='c2c_d2_inspect_fault_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);try{
  [$f,$e,$op]=d2_fixture($db);if($stage!=='association')$e=d2_associate($db,$e,$op);if($stage==='quarantine_processing')$e=d2_processing($db,$e);
  if($stage==='integrity_conflict'){[, $o]=d2_services($db);check($o->ignore($e,'original_terminal',$op)->is_accepted(),'terminal before inspection fault');$e=d2_event($db,$e);}
  $fresh=[];[$a,$o]=d2_services($db,$fresh,function(D2Db $n)use($failure,$prefix){if($failure==='read')$n->read_failure='FROM '.$prefix.'c2c_payment_events FORCE INDEX(PRIMARY)';elseif($failure==='begin'){$n->fault='START TRANSACTION';$n->after=true;}else $n->bookkeeping_failure='ROLLBACK';});
  $db->fault='COMMIT';$db->after=true;$db->write_number=0;$db->wrote=false;$out=$stage==='association'?$a->associate($e,$op):d2_outcome($o,$stage,$e,$op);
  check($out->inspection?->status===($failure==='read'?'database_error':'transaction_outcome_unknown'),'D2 fresh '.$failure.' failure does not guess stage outcome');
  foreach($fresh as $c){d2_no_dml($c);check($c->closed,'failed inspection closes its fresh connection');}check($db->closed&&$db->poison_sql===0,'failed inspection never queries discarded prior connection');
 }finally{if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
}
echo "D2 Event recovery checks passed.\n";
