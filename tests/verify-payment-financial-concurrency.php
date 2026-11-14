<?php
/** D3 independent connections/processes at deterministic financial/control barriers. */
declare(strict_types=1);
require __DIR__.'/support/payment-financial-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
class D3RaceDb extends D2Db {
 public $at_end=null;
 public function query(string $q):int|false {
  if($this->at_end!==null&&($q==='COMMIT'||($q==='ROLLBACK'&&TransactionManager::admitted_on($this)))){$f=$this->at_end;$this->at_end=null;$f();}
  return parent::query($q);
 }
}
function d3_wait($socket,wpdb $observer,string $table):void {
 $id=(int)trim(fgets($socket));$deadline=microtime(true)+10;
 do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE '%FROM $table %'")>0;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
 $r=[$socket];$w=[];$e=[];check($waiting&&stream_select($r,$w,$e,0,200000)===0,'independent financial actor visibly waits >=200ms at '.$table);
}
function d3_join(int $pid,$socket):string {$out=trim(fgets($socket));pcntl_waitpid($pid,$exit);check(pcntl_wifexited($exit)&&pcntl_wexitstatus($exit)===0,'independent financial actor exits cleanly: '.$out);fclose($socket);return $out;}
$count=0;
foreach(['conversion','expiration','processed_replay'] as $stage)foreach(['reset','delete'] as $mode)foreach(['continuation','destruction'] as $first){
 $prefix='c2c_d3_race_'.bin2hex(random_bytes(4)).'_';$db=new D3RaceDb($prefix);$pid=null;
 try{
  [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');
  if($stage==='processed_replay'){check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'sale before read-only probe race');$e=d2_event($db,$e);}
  $before=d3_stock($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new D2Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='destruction'){$child->capture=true;$child->captured=[];$code=d3_run($child,$stage,$f,$e,$op)->code();d2_no_dml($child);}
   elseif($mode==='delete'){d1_delete($child,$f);$code='deleted';}else $code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $ex){fwrite($pair[1],'FAIL:'.$ex->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D3RaceDb($prefix);$t=new TableNames($db);$observer=new D2Db($prefix);
  $db->at_end=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d3_wait($pair[0],$observer,$t->commerce_control());};
  if($first==='continuation'){$code=d3_run($db,$stage,$f,$e,$op)->code();check(in_array($code,['sale_converted','expiration_processed','sale_already_converted'],true),'financial stage finishes under its source authority');}
  elseif($mode==='delete')d1_delete($db,$f);else validation_start($db);
  $code=d3_join($pid,$pair[0]);$pid=null;check($first==='continuation'?$code===($mode==='delete'?'deleted':'started'):in_array($code,['order_deleted','commerce_reset_fenced','continuation_verification_required'],true),'destructive and financial orderings preserve source fence');
  check(d3_stock($db)===($first==='continuation'&&$stage==='conversion'?array_map(static fn($n)=>$n-1,$before):$before),'race never restores or repeats physical decrement');$observer->close();++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}d3_cleanup($db);}
}
foreach(['conversion_expiration','expiration_conversion','conversion_conversion','expiration_expiration'] as $race){
 $prefix='c2c_d3_compete_'.bin2hex(random_bytes(4)).'_';$db=new D3RaceDb($prefix);$pid=null;
 try{
  [$f,$paid,$paid_op]=d3_fixture($db);[$expired,$expired_op]=d3_event($db,$f,'checkout.session.expired','evt_d3_expired');
  [$first,$second]=explode('_',$race);$pe=$first==='conversion'?$paid:$expired;$po=$first==='conversion'?$paid_op:$expired_op;$ce=$second==='conversion'?$paid:$expired;$co=$second==='conversion'?$paid_op:$expired_op;
  $before=d3_stock($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D2Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{$out=d3_run($child,$second,$f,$ce,$co);fwrite($pair[1],$out->code()."\n");$child->close();exit(0);}catch(Throwable $ex){fwrite($pair[1],'FAIL:'.$ex->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D3RaceDb($prefix);$t=new TableNames($db);$observer=new D2Db($prefix);
  $db->at_end=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d3_wait($pair[0],$observer,$t->orders());};
  check(in_array(d3_run($db,$first,$f,$pe,$po)->code(),['sale_converted','expiration_processed'],true),'first competing financial stage commits');
  $code=d3_join($pid,$pair[0]);$pid=null;
  check(in_array($code,['sale_converted','sale_already_converted','expiration_processed','coherent_idempotent_delivery','database_error','continuation_verification_required','payment_identity_conflict','expiration_verification_required','inconsistent_conversion_state'],true),'competing stage either verifies current tuple or refuses stale snapshot: '.$code);
  $stock=d3_stock($db);check($stock===$before||$stock===array_map(static fn($n)=>$n-1,$before),'competing stages decrement at most once');
  $o=$db->get_row('SELECT * FROM '.$t->orders().' WHERE id='.$f['order']);$s=$db->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id='.$f['session']);
  check(($o->status==='paid'&&$s->status==='completed'&&$stock===array_map(static fn($n)=>$n-1,$before))||($o->status==='expired'&&$s->status==='expired'&&$stock===$before),'expiration never reverses a completed atomic sale');$observer->close();++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}d3_cleanup($db);}
}
echo "D3 independent-process financial races passed ($count scenarios).\n";
// The actual D3 read predicate is protected by the shared inspector's exclusive control fence.
foreach(['reset','delete'] as $mode)foreach(['inspection','destruction'] as $first){
 $prefix='c2c_d3_inspect_race_'.bin2hex(random_bytes(4)).'_';$db=new D3RaceDb($prefix);$pid=null;
 try{
  [$f,$e,$op]=d3_fixture($db);check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'completed sale before D3 inspection/control race');$stock=d3_stock($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D2Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='inspection'){
	if($mode==='delete'){d1_delete($child,$f);$code='deleted';}else $code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();
   }else{
	// Notify the actual fresh inspection connection ID, not the discarded prior one.
	$fresh=[];$factory=function()use($prefix,&$fresh,$pair){$n=new D3Db($prefix);$n->capture=true;$fresh[]=$n;fwrite($pair[1],'fresh:'.$n->dbh->thread_id."\n");fflush($pair[1]);return $n;};
	$out=(new \RodolfoRizzo\Cpt2Collection\OperationalRecoveryInspector($factory))->inspect($op,new \RodolfoRizzo\Cpt2Collection\PaymentFinancialStageInspection($e->id(),'conversion'),$child);$code=$out->status;foreach($fresh as $n)d2_no_dml($n);
   }
   fwrite($pair[1],$code."\n");if(!$child->closed)$child->close();exit(0);
  }catch(Throwable $ex){fwrite($pair[1],'FAIL:'.$ex->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D3RaceDb($prefix);$observer=new D2Db($prefix);$t=new TableNames($db);
  if($first==='inspection'){
   $fresh=[];$factory=function()use($prefix,&$fresh,$pair,$observer,$t){$n=new D3Db($prefix);$n->capture=true;$n->at_financial_read=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d3_wait($pair[0],$observer,$t->commerce_control());};$fresh[]=$n;return $n;};
   $out=(new \RodolfoRizzo\Cpt2Collection\OperationalRecoveryInspector($factory))->inspect($op,new \RodolfoRizzo\Cpt2Collection\PaymentFinancialStageInspection($e->id(),'conversion'),$db);check($out->status==='logically_completed','D3 inspection first sees intact coherent sale');foreach($fresh as $n)d2_no_dml($n);$db=new D3RaceDb($prefix);
  }else{
   $db->at_end=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);fgets($pair[0]);$line=trim(fgets($pair[0]));check(str_starts_with($line,'fresh:'),'independent inspector opens a distinct physical connection');$id=(int)substr($line,6);$deadline=microtime(true)+10;do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE '%FROM {$t->commerce_control()} %'")>0;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);$r=[$pair[0]];$w=[];$x=[];check($waiting&&stream_select($r,$w,$x,0,200000)===0,'fresh D3 inspector waits >=200ms for concurrent control owner');};
   if($mode==='delete')d1_delete($db,$f);else validation_start($db);
  }
  $code=d3_join($pid,$pair[0]);$pid=null;check($first==='inspection'?in_array($code,['deleted','started'],true):$code===($mode==='delete'?'permanently_deleted':'fenced'),'fresh inspection/control orderings return evidence-based outcomes');check(d3_stock($db)===$stock,'inspection/control race never mutates physical stock');$observer->close();++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}d3_cleanup($db);}
}
echo "D3 complete independent-process matrix passed ($count scenarios).\n";
