<?php
/** Actual independent REST-controller callers with socket barriers and observed SQL lock waits. */
declare(strict_types=1);
require __DIR__.'/support/reset-rest-database.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceResetRepository};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
function rest_worker(string $prefix,Closure $task):array {
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
 if($pid===0){fclose($pair[0]);try{$db=new ResetRestDb($prefix);fwrite($pair[1],$db->dbh->thread_id."\n");$out=$task($db,$pair[1]);fwrite($pair[1],json_encode($out)."\n");$db->close();exit(0);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}}
 fclose($pair[1]);stream_set_timeout($pair[0],15);$thread=(int)trim(fgets($pair[0]));return [$pid,$pair[0],$thread];
}
function rest_signal(array $worker):string{$s=fgets($worker[1]);if($s===false)throw new RuntimeException('worker socket timeout');return trim($s);}
function rest_join(array $worker):void{pcntl_waitpid($worker[0],$s);check(pcntl_wifexited($s)&&pcntl_wexitstatus($s)===0,'independent controller worker exits successfully');fclose($worker[1]);}
$scenarios=['same_start','different_start','status_start','same_resume','status_batch','retire_batch','delete_batch','final_resume','status_final','blocked_resume','lock_timeout'];
foreach($scenarios as $mode){
 [$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$t=new TableNames($db);$c=reset_rest_controller($db);$revision='0';
 if(!in_array($mode,['same_start','different_start','status_start','lock_timeout'],true)){
  $c->start(rest_request('start',$id,$epoch));$r=validation_service($db)->resume($id)->operation();
  if($mode==='retire_batch')$r=validation_pass($db,$r,100);
  if(in_array($mode,['delete_batch','final_resume','status_final'],true)){
   $r=deletion_retained($db,retirement_pass($db,validation_pass($db,$r),100),100);
   if($mode!=='delete_batch')$r=deletion_pass($db,$r,100);
  }
  if($mode==='blocked_resume'){
   $r=validation_pass($db,$r,100,'v_order_items');sql($db,'UPDATE '.$t->order_items().' SET line_total_minor=99');
  }
  $revision=(string)$r->revision();
 }
 $stock=deletion_stock($db);$db->close();
 $winnerAction=in_array($mode,['same_start','different_start','status_start','lock_timeout'],true)?'start':'resume';
 $winner=rest_worker($prefix,function($db,$socket)use($winnerAction,$id,$epoch,$revision){
  $held=false;$target=$winnerAction==='start'?0:1;
  $db->before_commit=static function($db)use($socket,$target,&$held){if(!$held&&$db->commits===$target){$held=true;fwrite($socket,"HELD\n");check(trim(fgets($socket))==='RELEASE','winning REST request released at transaction boundary');}};
  $response=reset_rest_controller($db)->$winnerAction(rest_request($winnerAction,$id,$epoch,$revision));return [$response->get_status(),$response->get_data()];
 });
 check(rest_signal($winner)==='HELD',$mode.' production REST winner holds exact transaction before COMMIT');
 $waiterAction=str_starts_with($mode,'status_')?'status':$winnerAction;$waiterId=$mode==='different_start'?bin2hex(random_bytes(16)):$id;
 $waiter=rest_worker($prefix,function($db,$socket)use($mode,$waiterAction,$waiterId,$epoch,$revision){
  if($mode==='lock_timeout')sql($db,'SET SESSION innodb_lock_wait_timeout=1');
  $response=reset_rest_controller($db)->$waiterAction(rest_request($waiterAction,$waiterId,$epoch,$revision));return [$response->get_status(),$response->get_data()];
 });
 $observer=new wpdb($prefix);$deadline=microtime(true)+5;$waiting=false;
 do{$waiting=(int)$observer->get_var('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID='.$waiter[2]." AND INFO LIKE 'SELECT %commerce_control%'")>0;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
 check($waiting,$mode.' independent REST waiter observed on existing control lock');
 $read=[$waiter[1]];$w=[];$e=[];check(stream_select($read,$w,$e,0,50000)===0,$mode.' waiter cannot return before transaction outcome');
 if($mode==='lock_timeout'){$waitingResult=json_decode(rest_signal($waiter),true);check($waitingResult[0]===503&&$waitingResult[1]['code']==='temporarily_unavailable'&&!$waitingResult[1]['verify_state'],'known lock timeout differs from authorization failure and unknown outcome');}
 fwrite($winner[1],"RELEASE\n");$winningResult=json_decode(rest_signal($winner),true);
 if($mode!=='lock_timeout')$waitingResult=json_decode(rest_signal($waiter),true);
 $expected=match($mode){'same_start'=>[200,'existing'],'different_start'=>[409,'conflict'],'same_resume','retire_batch','delete_batch'=>[409,'stale'],'final_resume','status_final'=>[200,'completed'],'blocked_resume'=>[409,'blocked'],'lock_timeout'=>[503,'temporarily_unavailable'],default=>[202,'running']};
 check($waitingResult[0]===$expected[0]&&$waitingResult[1]['code']===$expected[1],$mode.' waiter HTTP/domain mapping '.$expected[1]);
 check($winningResult[0]===($mode==='blocked_resume'?409:(in_array($mode,['final_resume','status_final'],true)?200:202)),$mode.' winning controller returns authoritative status');
 rest_join($winner);rest_join($waiter);
 try {
  $db=new ResetRestDb($prefix);$t=new TableNames($db);$p=reset_rest_controller($db)->status(rest_request('status',$id))->get_data()['operation'];
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->commerce_resets())===1,$mode.' exactly one durable reset identity');
  check(deletion_stock($db)===$stock,$mode.' physical stock preserved');
  check($p['revision']===(string)((int)$revision+($winnerAction==='start'?0:1)),$mode.' no duplicate revision/retirement/deletion/final switch');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->commerce_epochs())===(in_array($mode,['final_resume','status_final'],true)?2:1),$mode.' no duplicate epoch allocation');
  if($mode==='retire_batch')check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims()." WHERE disposition='retired'")===1,'concurrent REST retirement terminalizes one original claim');
  if($mode==='delete_batch')check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events())===0,'concurrent deletion batch erases selected rows once');
  $db->close();
 }finally{$observer->close();rest_cleanup($prefix);}
 echo 'SCENARIO: '.$mode."\n";
}
echo 'All '.count($scenarios)." independent-process reset REST scenarios passed.\n";

// Hold delivery outside the handler. Exact GET can return before a delayed request arrives.
[$db, $prefix, $epoch] = rest_fixture();
$id = bin2hex(random_bytes(16));
try {
 check(reset_rest_controller($db)->start(rest_request('start', $id, $epoch))->get_status() === 202, 'delayed delivery fixture starts one reset');
 $before = baseline_snapshot($db);
 $db->close();
 $db = null;
 $late = rest_worker($prefix, static function ($db, $socket) use ($id, $epoch) {
  fwrite($socket, "IN_TRANSIT\n");
  if (trim(fgets($socket)) !== 'DELIVER') throw new RuntimeException('Delayed delivery barrier failed');
  $reply = reset_rest_controller($db)->resume(rest_request('resume', $id, $epoch, '0'));
  return [$reply->get_status(), $reply->get_data()['code']];
 });
 $delivered = false;
 try {
  check(rest_signal($late) === 'IN_TRANSIT', 'original POST is delayed before entering the REST handler');
  $db = new ResetRestDb($prefix);
  $controller = reset_rest_controller($db);
  $status = $controller->status(rest_request('status', $id));
  check($status->get_data()['operation']['revision'] === '0' && baseline_snapshot($db) === $before, 'exact GET before delayed delivery leaves durable state unchanged');
  check($controller->resume(rest_request('resume', $id, $epoch, '0'))->get_status() === 202, 'independent client commits the inspected revision once');
  $after = baseline_snapshot($db);
  fwrite($late[1], "DELIVER\n");
  $delivered = true;
  check(json_decode(rest_signal($late), true) === [409, 'stale'], 'delayed original POST is fenced after independent commit');
  check($controller->status(rest_request('status', $id))->get_data()['operation']['revision'] === '1' &&
   baseline_snapshot($db) === $after, 'late POST cannot duplicate effects or advance revision');
 } finally {
  if (!$delivered) fwrite($late[1], "DELIVER\n");
  rest_join($late);
 }
} finally {
 $db?->close();
 rest_cleanup($prefix);
}
