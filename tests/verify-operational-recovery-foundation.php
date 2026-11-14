<?php
/** D1 uncertainty: synthetic postconditions only, no automatic business replay. */
declare(strict_types=1);
require __DIR__.'/support/committed-operation.php';
use RodolfoRizzo\Cpt2Collection\{CommittedOperationAuthority as A,TransactionManager,TableNames,OperationalRecoveryInspector,OperationalInspectionResult};
foreach(['begin','commit_before','commit_after','rollback_before','rollback_after','poison'] as $mode){
 $prefix='c2c_d1_unknown_'.bin2hex(random_bytes(5)).'_';$db=new D1Db($prefix);
 try {
  validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$t=new TableNames($db);$before=$db->get_var('SELECT updated_at FROM '.$t->orders().' WHERE id='.$f['order']);$after='2030-01-01 00:00:00';$stage=new D1SyntheticStage($before,$after);$tx=new TransactionManager($db,$t);
  if($mode==='begin'){$db->bookkeeping_failure='START TRANSACTION';$db->bookkeeping_after=true;check((new A($db,$t))->begin($op,$tx)->status==='transaction_outcome_unknown','lost BEGIN cannot confer authority');}
  else {
   check((new A($db,$t))->begin($op,$tx)->status==='admitted','exact native synthetic transaction admitted');
   sql($db,'UPDATE '.$t->orders()." SET updated_at='$after' WHERE id=".$f['order']);
   if($mode==='poison'){$db->poison_transaction();}
   elseif(str_starts_with($mode,'commit')){$db->fault=$mode==='commit_after'?'after':'before';check(!$tx->commit()&&$tx->is_outcome_uncertain(),'lost COMMIT poisons and revokes authority');}
   else {$db->bookkeeping_failure='ROLLBACK';$db->bookkeeping_after=$mode==='rollback_after';check(!$tx->rollback()&&$tx->is_outcome_uncertain(),'lost ROLLBACK poisons and revokes authority');}
  }
  check(TransactionManager::context_on($db)===null&&!$db->transaction_connection_usable(),'uncertain/poisoned transaction has no usable normal context');
  [$out,$connections]=d1_inspect($db,$op,$stage);$db=new D1Db($prefix);
  check($out->status===($mode==='commit_after'?'logically_completed':'reentry_eligible'),'fresh synthetic postcondition selects safe next action for '.$mode);
  check($stage->calls===1&&$db->get_var('SELECT updated_at FROM '.$t->orders().' WHERE id='.$f['order'])===($mode==='commit_after'?$after:$before),'inspection never repeats synthetic write');
  [$again]=d1_inspect(new D1Db($prefix),$op,$stage);check($again->status===$out->status,'same exact operation inspection is idempotent DATA');
 }finally{if($db->closed)$db=new D1Db($prefix);baseline_cleanup($db);}
}
foreach(['fenced','completed','deleted','deleted_reset','conflict','stale','missing_tuple','missing_owner','inspection_begin','inspection_rollback','inspection_read'] as $mode){
 $prefix='c2c_d1_inspect_'.bin2hex(random_bytes(5)).'_';$db=new D1Db($prefix);
 try {
  validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$t=new TableNames($db);$before=$db->get_var('SELECT updated_at FROM '.$t->orders().' WHERE id='.$f['order']);$stage=new D1SyntheticStage($before,'2030-01-01 00:00:00');
  if($mode==='fenced')validation_start($db);
  if($mode==='completed')discovery_finish($db,validation_start($db));
  if(in_array($mode,['deleted','deleted_reset'],true)){d1_delete($db,$f);if($mode==='deleted_reset')discovery_finish($db,validation_start($db));}
  if($mode==='conflict'||$mode==='stale')sql($db,'UPDATE '.$t->orders()." SET updated_at='2029-01-01 00:00:00' WHERE id=".$f['order']);
  if($mode==='missing_tuple')sql($db,'DELETE FROM '.$t->orders().' WHERE id='.$f['order']);
  if($mode==='missing_owner')sql($db,'DELETE FROM '.$t->provider_claims().' WHERE id='.$f['claim']);
  $state=baseline_snapshot($db);
  [$out]=d1_inspect(new D1Db($prefix),$op,$stage,function(D1Db $fresh)use($mode){if($mode==='inspection_begin')$fresh->bookkeeping_failure='START TRANSACTION';if($mode==='inspection_rollback')$fresh->bookkeeping_failure='ROLLBACK';if($mode==='inspection_read')$fresh->read_failure='c2c_orders';});
  $expected=match($mode){'fenced','completed'=>'fenced','deleted','deleted_reset'=>'permanently_deleted','conflict','stale'=>'conflict','missing_tuple','missing_owner'=>'verification_required','inspection_begin','inspection_rollback'=>'transaction_outcome_unknown',default=>'database_error'};
  check($out->status===$expected,'explicit operational inspection '.$mode.' => '.$expected);
  check(baseline_snapshot($db)===$state,'inspection/result classification mutates no durable state');
  if(in_array($mode,['fenced','completed','deleted','deleted_reset','missing_owner'],true))check($stage->calls===0,'non-operational/unverifiable owner never invokes stage predicate');
 }finally{baseline_cleanup($db);}
}
// Same retained owner after erasure cannot distinguish committed/rolled-back financial history.
$observed=[];
foreach([false,true] as $committed){
 $prefix='c2c_d1_erasure_'.bin2hex(random_bytes(5)).'_';$db=new D1Db($prefix);
 try {
  validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$t=new TableNames($db);$stage=new D1SyntheticStage('2026-01-01 00:00:00','2030-01-01 00:00:00');
  // Synthetic lost operational effect; D1 deliberately has no sale tuple algorithm.
  $tx=new TransactionManager($db,$t);check((new A($db,$t))->begin($op,$tx)->status==='admitted','erasure predecessor exact normal admission');
  sql($db,'UPDATE '.$t->orders()." SET updated_at='2030-01-01 00:00:00' WHERE id=".$f['order']);$db->fault=$committed?'after':'before';check(!$tx->commit(),'erasure predecessor acknowledgement lost');$db->close();$db=new D1Db($prefix);
  discovery_finish($db,validation_start($db));$stock=deletion_stock($db);[$out]=d1_inspect(new D1Db($prefix),$op,$stage);
  check($out->status==='fenced'&&$stage->calls===0&&deletion_stock($db)===$stock,'erased tuple cannot prove rollback/sale or replay stock');$observed[]=[$out->status,$out->transaction_provenance,$out->owner->fence,$out->owner->owner->lifecycle];
 }finally{baseline_cleanup($db);}
}
check($observed[0]===$observed[1],'indistinguishable erased transaction histories have identical non-operational/unknown provenance outcomes');
// Reject poisoned/reused factories without SQL on the prior connection.
$db=new D1Db('c2c_d1_factory_'.bin2hex(random_bytes(5)).'_');try{validation_schema($db);$op=d1_operation($db,validation_order($db));$stage=new D1SyntheticStage('a','b');$out=(new OperationalRecoveryInspector(fn()=>$db))->inspect($op,$stage,$db);check($out->status==='verification_required'&&$db->poison_sql===0,'same uncertain connection cannot be recovery connection');$prefix=$db->prefix;$db=new D1Db($prefix);}finally{baseline_cleanup($db);}
echo "All D1 operational uncertainty/inspection checks passed.\n";
// Real sale COMMIT ambiguity followed by genuine deletion: durable neutralization
// erases the processed-versus-pending discriminator in both financial histories.
require_once __DIR__.'/support/provider-webhook.php';
class D1FinancialDb extends D1Db {
 public bool $armed=false;public bool $sale_write=false;public bool $executed=false;
 public function query(string $q):int|false {
  if($this->armed&&str_starts_with($q,'UPDATE')&&str_contains($q,'c2c_orders')&&preg_match("/status[^,]*'paid'/",$q))$this->sale_write=true;
  if($this->armed&&$this->sale_write&&$q==='COMMIT'){if($this->executed)parent::query($q);$this->last_error='lost financial COMMIT acknowledgement';return false;}
  return parent::query($q);
 }
}
$financial=[];
foreach([false,true] as $executed){
 $prefix='c2c_d1_financial_'.bin2hex(random_bytes(5)).'_';$db=new D1FinancialDb($prefix);
 try {
  validation_schema($db);$f=validation_order($db,1);webhook_activate($db,$f);$op=d1_operation($db,$f);$connections=[];$t=new TableNames($db);$stock0=deletion_stock($db);$db->armed=true;$db->executed=$executed;
  check(webhook_deliver(webhook_controller($db,$connections),webhook_payload($f))->get_status()===500,'real financial COMMIT unknown stops webhook continuation');
  check($db->sale_write&&!$db->transaction_connection_usable()&&$db->poison_sql===0,'real financial uncertainty poisons authority without follow-up SQL');$db->close();$db=new D1Db($prefix);
  check((int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77")===($executed?19:20),'fixture establishes distinct real committed/rolled-back stock histories');
  d1_delete($db,$f);$stock=deletion_stock($db);$stage=new D1SyntheticStage('a','b');[$out]=d1_inspect(new D1Db($prefix),$op,$stage);
  check($out->status==='permanently_deleted'&&$out->transaction_provenance==='not_provable'&&$stage->calls===0&&deletion_stock($db)===$stock,'real erased sale never reconstructed/replayed or guessed rolled back');
  $event=$db->get_row("SELECT disposition FROM {$t->provider_identities()} WHERE identity_kind='event'");check($event->disposition==='neutralized_order_deleted','genuine deletion neutralizes retained Event in either sale history');
  $financial[]=[$out->status,$out->transaction_provenance,$out->owner->owner->lifecycle,$event->disposition];
 }finally{if($db->closed)$db=new D1Db($prefix);baseline_cleanup($db);}
}
check($financial[0]===$financial[1],'real distinct financial histories yield indistinguishable retained recovery classification after erasure');
echo "All D1 real financial erasure limitations passed.\n";
