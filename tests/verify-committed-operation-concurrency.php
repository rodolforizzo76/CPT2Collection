<?php
/** D1 independent processes; deterministic barriers and observed foundation waits. */
declare(strict_types=1);
require __DIR__.'/support/committed-operation.php';
use RodolfoRizzo\Cpt2Collection\{CommittedOperationAuthority as A,TransactionManager,TableNames,CommerceResetRepository,OperationalRecoveryInspector,ProviderEvidenceRouter,ProviderDiscoveryCandidate,CommittedOperation};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
function d1_wait($channel,wpdb $observer,TableNames $t):void {
 $id=(int)trim(fgets($channel));$deadline=microtime(true)+10;$waiting=false;
 do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT %FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(20000);}while(!$waiting&&microtime(true)<$deadline);
 $read=[$channel];$w=[];$e=[];check($waiting&&stream_select($read,$w,$e,0,200000)===0,'independent process actually waits >=200ms at control before downstream locks');
}
function d1_join(int $pid,$channel):string {$out=trim(fgets($channel));pcntl_waitpid($pid,$exit);check(pcntl_wifexited($exit)&&pcntl_wexitstatus($exit)===0,'independent D1 actor exits cleanly '.$out);fclose($channel);return $out;}
class D1RaceDb extends D1Db {
 public $at_commit=null;public $at_snapshot=null;public $at_control=null;
 public function query(string $q):int|false {
  if($q==='COMMIT'&&$this->at_commit){$f=$this->at_commit;$this->at_commit=null;$f();}
  $out=parent::query($q);
  if(str_starts_with($q,'START TRANSACTION WITH CONSISTENT SNAPSHOT')&&$this->at_snapshot){$f=$this->at_snapshot;$this->at_snapshot=null;$f();}
  return $out;
 }
 public function get_results(string $q):?array {
  $out=parent::get_results($q);
  if(str_contains($q,'c2c_commerce_control')&&str_ends_with($q,'FOR UPDATE')&&$this->at_control){$f=$this->at_control;$this->at_control=null;$f();}
  return $out;
 }
}
foreach(['reset','delete'] as $mode)foreach(['admission','destructive'] as $first){
 $prefix='c2c_d1_race_'.bin2hex(random_bytes(5)).'_';$db=new D1RaceDb($prefix);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new D1Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='destructive'){$tx=new TransactionManager($child,new TableNames($child));$result=(new A($child,new TableNames($child)))->begin($op,$tx);$code=$result->status;if($result->status==='attributed')$code.=':'.$result->owner->lifecycle;check(TransactionManager::context_on($child)===null,'historical result holds no operational authority');$tx->rollback();}
   elseif($mode==='reset')$code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();
   else{d1_delete($child,$f);$code='deleted';}
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D1RaceDb($prefix);$t=new TableNames($db);$observer=new wpdb($prefix);
  $barrier=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d1_wait($pair[0],$observer,$t);};
  if($first==='admission'){$tx=new TransactionManager($db,$t);check((new A($db,$t))->begin($op,$tx)->status==='admitted','D1 exact admission holds original source fence');$barrier();check($tx->commit(),'normal holder commits before destructive action');}
  else {$db->at_commit=$barrier;if($mode==='reset')check(validation_service($db)->start(bin2hex(random_bytes(16)),$f['epoch'])->code()==='started','reset holder commits before waiting D1 admission');else d1_delete($db,$f);}
  $out=d1_join($pid,$pair[0]);$pid=null;check($out===($first==='destructive'?($mode==='delete'?'attributed:permanently_deleted':'admission_denied'):($mode==='reset'?'started':'deleted')),'both admission/destruction orderings fail closed or serialize');$observer->close();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}baseline_cleanup($db);}
}
// Classifiers are MVCC DATA: writers finish without waiting for a retained snapshot.
foreach(['final','later','conflict','ambiguity'] as $mode)foreach(['reader','writer'] as $first){
 $prefix='c2c_d1_snapshot_'.bin2hex(random_bytes(5)).'_';$db=new D1RaceDb($prefix);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$r=null;
  if($mode==='final'||$mode==='later'){$r=retirement_pass($db,validation_pass($db,validation_start($db),100),100);$r=deletion_pass($db,deletion_retained($db,$r,100),100);if($mode==='later')$r=validation_service($db)->finalize($r)->operation();}
  if($mode==='conflict'){$op=CommittedOperation::authenticated_receipt(discovery_claim($db,$f),d1_receipt($f),gmdate('Y-m-d H:i:s'));check((new ProviderEvidenceRouter($db,new TableNames($db)))->retain($op->candidate)->status==='retained','original Event evidence before conflict race');}
  $db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new D1Db($prefix);try{
   if($mode==='final')check(validation_service($child)->finalize($r)->code()==='completed','independent final switch');
   elseif($mode==='later')validation_start($child);
   elseif($mode==='conflict'){$bad=CommittedOperation::authenticated_receipt(discovery_claim($child,$f),d1_receipt($f,'different'),gmdate('Y-m-d H:i:s'));check((new ProviderEvidenceRouter($child,new TableNames($child)))->retain($bad->candidate)->status==='conflict','independent immutable conflict quarantines once');}
   else {$t=new TableNames($child);$row=get_object_vars($child->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']));$row['id']=99;$row['reference']=md5('duplicate');$row['idempotency_key']='c2c_ps_'.$row['reference'];$row['creation_key_hash']=hash('sha256','duplicate');check($child->insert($t->provider_claims(),$row,array_fill(0,count($row),'%s'))===1,'independent deliberately corrupt Session ambiguity');}
   fwrite($pair[1],"committed\n");$child->close();exit(0);
  }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D1RaceDb($prefix);
  $run=function()use($pair){fwrite($pair[0],"go\n");fflush($pair[0]);check(trim(fgets($pair[0]))==='committed','independent writer commits while classifier snapshot open, without pretending A0 locks reset');};
  if($first==='reader')$db->at_snapshot=$run;else $run();
  $out=(new A($db,new TableNames($db)))->classify($op);$fresh=(new A($db,new TableNames($db)))->classify($op);
  if($first==='reader')check($out->status==='attributed'&&$out->fence===match($mode){'final'=>'active_reset_source','later'=>'completed_retired',default=>'current_ready'},'classification preserves coherent earlier snapshot');
  check(match($mode){'final'=>$fresh->fence==='completed_retired','later'=>$fresh->fence==='older_retired_during_active_reset','conflict'=>$fresh->status==='conflict',default=>$fresh->status==='ambiguous'},'fresh exact classification sees whole committed transition');
  pcntl_waitpid($pid,$exit);$pid=null;check(pcntl_wexitstatus($exit)===0,'snapshot actor clean exit');fclose($pair[0]);
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}baseline_cleanup($db);}
}
foreach(['recovery','erasure'] as $first){
 $prefix='c2c_d1_erase_race_'.bin2hex(random_bytes(5)).'_';$db=new D1RaceDb($prefix);$pid=null;
 try{
  [$r,$fs]=deletion_setup($db,1);$f=$fs[0];$op=d1_operation($db,$f);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new D1Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='recovery')$code=validation_service($child)->delete_operational($r,1)->code();
   else {$prior=new D1Db($prefix);$code=(new OperationalRecoveryInspector(fn()=>$child))->inspect($op,new D1SyntheticStage('a','b'),$prior)->status;}
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D1RaceDb($prefix);$t=new TableNames($db);$observer=new wpdb($prefix);$barrier=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d1_wait($pair[0],$observer,$t);};
  if($first==='recovery'){$fresh=new D1RaceDb($prefix);$fresh->at_control=$barrier;$stage=new D1SyntheticStage('a','b');$out=(new OperationalRecoveryInspector(fn()=>$fresh))->inspect($op,$stage,new D1Db($prefix));check($out->status==='fenced'&&$stage->calls===0,'recovery holds read-only control fence, never reconstructs erased rows');}
  else{$db->at_commit=$barrier;check(validation_service($db)->delete_operational($r,1)->code()==='progressed','production destructive batch commits before recovery');}
  $code=d1_join($pid,$pair[0]);$pid=null;check($code===($first==='recovery'?'progressed':'fenced'),'inspection/erasure serialize in both orders');$observer->close();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}baseline_cleanup($db);}
}
foreach(['before','after'] as $loss){
 $prefix='c2c_d1_resolve_'.bin2hex(random_bytes(5)).'_';$db=new D1RaceDb($prefix);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new D1Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);$code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();fwrite($pair[1],$code."\n");$child->close();exit(0);}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D1RaceDb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);check((new A($db,$t))->begin($op,$tx)->status==='admitted','uncertain resolution predecessor exact admission');sql($db,'UPDATE '.$t->orders()." SET updated_at='2030-01-01 00:00:00' WHERE id=".$f['order']);$db->fault=$loss;check(!$tx->commit(),'operational commit acknowledgement lost');
  fwrite($pair[0],"go\n");fflush($pair[0]);$observer=new wpdb($prefix);
  if($loss==='before')d1_wait($pair[0],$observer,$t);else fgets($pair[0]);$db->close();check(d1_join($pid,$pair[0])==='started','reset progresses after prior transaction resolution');$pid=null;$db=new D1RaceDb($prefix);
  [$out]=d1_inspect(new D1Db($prefix),$op,new D1SyntheticStage('a','b'));check($out->status==='fenced'&&$out->transaction_provenance==='not_provable','resolution/reset ordering never chooses financial provenance/replay');$observer->close();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}if($db->closed)$db=new D1RaceDb($prefix);baseline_cleanup($db);}
}
foreach([false,true] as $reverse){
 $prefix='c2c_d1_attempts_'.bin2hex(random_bytes(5)).'_';$db=new D1Db($prefix);$pid=null;
 try{validation_schema($db);$f=validation_order($db);$g=d1_second_attempt($db,$f);$one=d1_operation($db,$reverse?$g:$f);$two=d1_operation($db,$reverse?$f:$g);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
 if($pid===0){fclose($pair[0]);fgets($pair[1]);$c=new D1Db($prefix);$tx=new TransactionManager($c,new TableNames($c));$out=(new A($c,new TableNames($c)))->begin($two,$tx);fwrite($pair[1],$out->status.':'.$out->owner?->claim_id."\n");$tx->rollback();$c->close();exit(0);}
 fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D1Db($prefix);$tx=new TransactionManager($db,new TableNames($db));check((new A($db,new TableNames($db)))->begin($one,$tx)->status==='admitted','one exact attempt independently admitted');fwrite($pair[0],"go\n");fflush($pair[0]);check(d1_join($pid,$pair[0])==='admitted:'.$two->claim->id(),'distinct attempts on same order share normal foundation without owner substitution');$pid=null;$tx->rollback();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}baseline_cleanup($db);}
}
echo "All D1 independent-process concurrency checks passed.\n";
