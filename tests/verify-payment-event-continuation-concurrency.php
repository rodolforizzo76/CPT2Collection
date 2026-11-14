<?php
/** D2 independent production processes, deterministic commit barriers and real lock waits. */
declare(strict_types=1);
require __DIR__.'/support/payment-event-continuation.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
class D2RaceDb extends D2Db {
 public $at_commit=null;
 public function query(string $q):int|false {if($q==='COMMIT'&&$this->at_commit!==null){$f=$this->at_commit;$this->at_commit=null;$f();}return parent::query($q);}
}
function d2_wait($socket,wpdb $observer,string $table):void {
 $id=(int)trim(fgets($socket));$deadline=microtime(true)+10;
 do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE '%FROM $table %'")>0;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
 $r=[$socket];$w=[];$e=[];check($waiting&&stream_select($r,$w,$e,0,200000)===0,'independent D2 actor visibly waits >=200ms before acquiring '.$table);
}
function d2_join(int $pid,$socket):string {$out=trim(fgets($socket));pcntl_waitpid($pid,$exit);check(pcntl_wifexited($exit)&&pcntl_wexitstatus($exit)===0,'independent D2 actor exits cleanly: '.$out);fclose($socket);return $out;}
function d2_prepare(D2Db $db,string $stage):array {
 [$f,$e,$op]=d2_fixture($db);if($stage!=='association')$e=d2_associate($db,$e,$op);
 if($stage==='quarantine_processing')$e=d2_processing($db,$e);
 if($stage==='integrity_conflict'){[, $o]=d2_services($db);check($o->ignore($e,'original_terminal',$op)->is_accepted(),'terminal receipt before concurrent alarm');$e=d2_event($db,$e);}
 return [$f,$e,$op];
}
function d2_run(D2Db $db,string $stage,$e,$op):string {[$a,$o]=d2_services($db);$out=$stage==='association'?$a->associate($e,$op):d2_outcome($o,$stage,$e,$op);return $stage==='association'?$out->outcome():$out->code();}
foreach(['association','ignored','attention','quarantine_processing','integrity_conflict'] as $stage)foreach(['reset','delete'] as $mode)foreach(['continuation','destruction'] as $first){
 $prefix='c2c_d2_race_'.bin2hex(random_bytes(4)).'_';$db=new D2RaceDb($prefix);$pid=null;
 try{
  [$f,$event,$op]=d2_prepare($db,$stage);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new D2Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='destruction'){$child->capture=true;$child->captured=[];$code=d2_run($child,$stage,$event,$op);d2_no_dml($child);}
   elseif($mode==='delete'){d1_delete($child,$f);$code='deleted';}
   else $code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $ex){fwrite($pair[1],'FAIL:'.$ex->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D2RaceDb($prefix);$t=new TableNames($db);$observer=new D2Db($prefix);
  $db->at_commit=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d2_wait($pair[0],$observer,$t->commerce_control());};
  if($first==='continuation'){$code=d2_run($db,$stage,$event,$op);check(!in_array($code,['transaction_outcome_unknown','continuation_verification_required'],true),'D2 continuation commits before destruction');}
  elseif($mode==='delete')d1_delete($db,$f);else validation_start($db);
  $code=d2_join($pid,$pair[0]);$pid=null;
  check($first==='continuation'?$code===($mode==='delete'?'deleted':'started'):!in_array($code,['associated','already_associated','d2_ignored','d2_attention','anomalous_processing','duplicate_integrity_conflict'],true),'both D2/destruction orderings preserve fencing');
  if($mode==='delete')check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events())===0,'deletion leaves no operational receipt');
  $observer->close();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
}
foreach(['association','ignored','attention','quarantine_processing','integrity_conflict','conflicting_outcomes'] as $stage){
 $prefix='c2c_d2_processors_'.bin2hex(random_bytes(4)).'_';$db=new D2RaceDb($prefix);$pid=null;
 try{
  $parent_stage=$stage==='conflicting_outcomes'?'ignored':$stage;[$f,$event,$op]=d2_prepare($db,$parent_stage);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D2Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{$code=d2_run($child,$stage==='conflicting_outcomes'?'attention':$stage,$event,$op);fwrite($pair[1],$code."\n");$child->close();exit(0);}catch(Throwable $ex){fwrite($pair[1],'FAIL:'.$ex->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D2RaceDb($prefix);$t=new TableNames($db);$observer=new D2Db($prefix);
  $db->at_commit=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d2_wait($pair[0],$observer,$t->orders());};
  d2_run($db,$parent_stage,$event,$op);$snapshot=baseline_snapshot($db);$code=d2_join($pid,$pair[0]);$pid=null;
  check(in_array($code,['already_associated','database_failure','database_error','event_state_conflict','d2_ignored','d2_attention','anomalous_processing','duplicate_integrity_conflict'],true)&&baseline_snapshot($db)===$snapshot,'concurrent duplicate processor performs no second terminal effect: '.$stage);$observer->close();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
}
echo "D2 independent-process Event races passed (26 scenarios).\n";
