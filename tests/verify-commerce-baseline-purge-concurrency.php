<?php
declare(strict_types=1);
require __DIR__.'/support/baseline-purge-concurrency.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselinePurgeRecord as Record,CommerceResetService,CommerceResetRepository,TableNames,TransactionManager,UtcClock};
class HoldingPurgeDb extends BaselineWriteProbeDb{
 public $socket=null;
 public function query(string $q):int|false{$r=parent::query($q);if($r!==false&&$this->socket!==null&&str_starts_with($q,'UPDATE')&&str_contains($q,'baseline_purge')){send_signal($this->socket,'HELD');if(signal($this->socket)!=='RELEASE')throw new RuntimeException('barrier');$this->socket=null;}return $r;}
}
foreach(['two_workers','normal','reset','provider','final_waiter'] as $race){
 $prefix='c2c_purgerace_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$b=purge_fixture($db);$svc=purge_service($db);$r=$svc->step($b,null,1);
 if($race==='final_waiter')while($r->operation()->data()['stage']!==count(Record::STAGES))$r=$svc->step($b,$r->operation(),1);
 $expected=$r->operation();$db->close();
 $holder=worker($prefix,function(HoldingPurgeDb $c,$s)use($b,$expected):string{$c->socket=$s;$r=purge_service($c)->step($b,$expected,1);return $r->code();},HoldingPurgeDb::class);
 check(signal($holder[1])==='HELD','production batch/finalization holds control before commit');
 $waiter=worker($prefix,function(wpdb $c)use($race,$b,$expected):string{
  $t=new TableNames($c);$tx=new TransactionManager($c,$t);$writes=$c->attempted_writes;
  if(in_array($race,['two_workers','final_waiter'],true))$result=purge_service($c)->step($b,$expected,1)->code();
  elseif($race==='normal'){$result=$tx->begin_commerce_shared()?'incorrect_authority':'denied';$tx->rollback();}
  elseif($race==='reset'){$reset=new CommerceResetService(new CommerceResetRepository($c,$t),$tx,new UtcClock());$result=$reset->start(str_repeat('8',32),$expected->data()['source_epoch_identity'])->code();}
  else{$p=modern_payload(str_repeat('c',32),'evt_race_during_purge');$result=webhook_for($c)->handle($p,hash('sha256',json_encode($p)))->code();}
  check($c->attempted_writes===$writes,'waiting competing writer attempts zero DML');return $result;
 });$db=new wpdb($prefix);
 try{observed_wait($db,$waiter[2]);send_signal($holder[1],'RELEASE');check(joined($holder)===($race==='final_waiter'?'completed':'progressed'),'holder commits exact bounded transition');$result=joined($waiter);$want=['two_workers'=>'stale','normal'=>'denied','reset'=>'inconsistent_state','provider'=>'pre_baseline','final_waiter'=>'already_completed'][$race];check($result===$want,$race.': waiter rereads authoritative state: '.$result);$durable=purge_service($db)->inspect($b);check($durable->operation()->data()['revision']===$expected->data()['revision']+1,'two workers/final waiter advance exactly once');}
 finally{baseline_cleanup($db);}
}
