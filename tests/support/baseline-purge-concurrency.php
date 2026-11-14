<?php
declare(strict_types=1);
require_once __DIR__.'/baseline-purge.php';
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
function send_signal($s,string $v):void{fwrite($s,$v."\n");fflush($s);}
function signal($s):string{stream_set_timeout($s,15);$v=fgets($s);if($v===false)throw new RuntimeException('worker timeout');return trim($v);}
/** Called only with all database connections closed before fork. */
function worker(string $prefix,Closure $job,string $class=BaselineWriteProbeDb::class):array{
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$pid=pcntl_fork();
 if($pid===0){fclose($pair[0]);try{$db=new $class($prefix);send_signal($pair[1],(string)$db->dbh->thread_id);$r=$job($db,$pair[1]);$db->close();send_signal($pair[1],'OK:'.$r);exit(0);}catch(Throwable $e){send_signal($pair[1],'FAIL:'.$e->getMessage());exit(1);}}
 fclose($pair[1]);return [$pid,$pair[0],(int)signal($pair[0])];
}
function joined(array $w):string{$v=signal($w[1]);pcntl_waitpid($w[0],$status);fclose($w[1]);check(pcntl_wexitstatus($status)===0&&str_starts_with($v,'OK:'),'independent worker completed: '.$v);return substr($v,3);}
function observed_wait(wpdb $db,int $id):void{
 $end=microtime(true)+10;
 do {
  $rows=$db->get_results('SELECT trx_mysql_thread_id,trx_state,trx_query FROM information_schema.INNODB_TRX');
  foreach($rows??[] as $row)if((int)$row->trx_mysql_thread_id===$id&&$row->trx_state==='LOCK WAIT'){check(true,'actual independent SQL lock wait observed');return;}
  usleep(150000);
 }while(microtime(true)<$end);
 throw new RuntimeException('no observed lock wait: '.json_encode($rows).' '.$db->last_error);

}
