<?php
declare(strict_types=1);
require __DIR__.'/support/baseline-purge.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommerceBaselinePurgeRecord as Record};
class PurgeFaultDb extends wpdb {
 public string $fault='';
 public function query(string $q):int|false{
  if($q==='START TRANSACTION'&&$this->fault==='begin'){parent::query($q);$this->last_error='BEGIN response lost';return false;}
  if($q==='COMMIT'&&in_array($this->fault,['commit','commit_before'],true)){if($this->fault==='commit')parent::query($q);$this->last_error='COMMIT response lost';return false;}
  if($q==='ROLLBACK'&&$this->fault==='rollback'){parent::query($q);$this->last_error='ROLLBACK response lost';return false;}
  if(str_starts_with($q,'DELETE FROM')&&in_array($this->fault,['write','rollback'],true)){parent::query($q);$this->last_error='DELETE response failure';return false;}
  if(str_starts_with($q,'UPDATE')&&str_contains($q,'baseline_purge')&&$this->fault==='progress_write'){parent::query($q);$this->last_error='checkpoint write failure';return false;}
  if(str_starts_with($q,'INSERT INTO')&&str_contains($q,'commerce_epochs')&&$this->fault==='final_write'){$this->last_error='final epoch insert failure';return false;}
  return parent::query($q);
 }
}
foreach(['creation','batch','stage','final'] as $transition){
 foreach(['commit','commit_before',...($transition==='batch'?['begin','write','rollback']:[]),...($transition==='stage'?['progress_write']:[]),...($transition==='final'?['final_write']:[])] as $fault){
  $prefix='c2c_purgeloss_'.bin2hex(random_bytes(5)).'_';$db=new PurgeFaultDb($prefix);$t=new TableNames($db);
  try{
   $b=purge_fixture($db);$preserved=purge_preserved($db);$svc=purge_service($db);$r=$svc->inspect($b);
   if($transition!=='creation'){$r=$svc->step($b,null,1);}
   if($transition==='stage'){while($db->get_var('SELECT id FROM '.$t->payment_events().' LIMIT 1')!==null)$r=$svc->step($b,$r->operation(),1);}
   if($transition==='final'){while($r->operation()->data()['stage']!==count(Record::STAGES))$r=$svc->step($b,$r->operation(),1);}
   $before=baseline_snapshot($db);check(\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db)&&baseline_snapshot($db)===$before,'migration retry preserves exact in-progress purge snapshot');$expected=$r->operation();$db->fault=$fault;$out=$svc->step($b,$expected,1);
   $unknown=in_array($fault,['commit','commit_before','begin','rollback'],true);
   check($out->requires_verification()===$unknown&&$out->code()===($unknown?'transaction_outcome_unknown':'database_error'),$transition.'/'.$fault.': typed result distinguishes uncertainty/known rollback');
   check($db->transaction_connection_usable()!==$unknown,$transition.'/'.$fault.': connection poison matches outcome certainty');
   if($unknown)check($svc->step($b,$expected,1)->requires_verification(),'no retry/probe on poisoned connection');
   $db->close();$db=new PurgeFaultDb($prefix);$t=new TableNames($db);$svc=purge_service($db);$durable=$svc->inspect($b);check($durable->is_success(),'fresh locked inspection recovers authority');
   if($fault==='commit'){
	if($transition==='final')check($durable->code()==='already_completed'&&purge_baseline($db)['status']==='completed','lost final acknowledgement proves committed atomic completion');
	else check($durable->operation()!==null&&($expected===null||$durable->operation()->data()['revision']===$expected->data()['revision']+1),'committed effects and cursor/stage are recovered together');
   }else check(baseline_snapshot($db)===$before,'known/freshly proven rollback restores deletion progress epoch and fence together');
   purge_to_end($db,$b);check(purge_preserved($db)===$preserved,'failure recovery never compensates stock or mutates preserved foundation');
   check(purge_baseline($db)['first_epoch_id']===$b['first_epoch_id']&&purge_baseline($db)['first_epoch_identity']===$b['first_epoch_identity'],'recovery never allocates another boundary');
  }finally{baseline_cleanup($db);}
 }
}
