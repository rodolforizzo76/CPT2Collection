<?php
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration as Migration,CommerceBaseline,CommerceControlRepository,CommerceContext,PaymentSessionRecord,PaymentProviderSessionRequest,PaymentProviderLineItem,TransactionManager,TableNames};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
function send_signal($s,string $v):void{fwrite($s,$v."\n");fflush($s);}
function signal($s):string{stream_set_timeout($s,15);$v=fgets($s);if($v===false)throw new RuntimeException('worker timeout');return trim($v);}
/** Called only with all database connections closed before fork. */
function worker(string $prefix,Closure $job):array{
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$pid=pcntl_fork();
 if($pid===0){fclose($pair[0]);try{$db=new BaselineWriteProbeDb($prefix);send_signal($pair[1],(string)$db->dbh->thread_id);$r=$job($db,$pair[1]);$db->close();send_signal($pair[1],'OK:'.$r);exit(0);}catch(Throwable $e){send_signal($pair[1],'FAIL:'.$e->getMessage());exit(1);}}
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
foreach(['success','exception'] as $kind){
 $prefix='c2c_basenet_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);maintenance_schema($db);
 // Model a native request already committed by v14, then paused at the external call.
 $source=new CommerceContext(str_repeat('a',32),1,str_repeat('b',32));
 $stripe=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider(new ModernBoundaryProvider(),new \RodolfoRizzo\Cpt2Collection\UtcClock());
 $request=new PaymentProviderSessionRequest(str_repeat('c',32),'c2c_ps_'.str_repeat('c',32),'EUR',100,[new PaymentProviderLineItem('Captured request',100,1)],gmdate('Y-m-d H:i:s',time()+7200),$stripe->request_configuration($source));
 sql($db,$db->prepare('UPDATE '.$t->provider_claims()." SET request_version=2,request_snapshot=%s,evidence_state='native',environment='test' WHERE id=1",$request->snapshot()));
 sql($db,"UPDATE {$t->payment_sessions()} SET status='creating',active_slot=1,provider_session_id=NULL,provider_payment_id=NULL,provider_event_id=NULL,completed_at=NULL WHERE id=1");
 sql($db,"UPDATE {$t->orders()} SET status='pending',paid_at=NULL WHERE id=1");
 $db->close();
 $w=worker($prefix,function(wpdb $c,$socket)use($kind):string{
  $t=new TableNames($c);$session=PaymentSessionRecord::from_row($c->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id=1'));
  $source=new CommerceContext(str_repeat('a',32),1,str_repeat('b',32));
  $provider=new ModernBoundaryProvider();$provider->exception=$kind==='exception';
  $provider->during_call=function()use($socket){send_signal($socket,'NETWORK');if(signal($socket)!=='RETURN')throw new RuntimeException('network barrier');};
  [$svc,$tx]=payment_service($c,$provider);
  $request=PaymentProviderSessionRequest::from_snapshot($c->get_var('SELECT request_snapshot FROM '.$t->provider_claims().' WHERE id=1'));
  // Resume the production network stage with a durable session captured before upgrade.
  $result=(new ReflectionMethod($svc,'finish_provider_request'))->invoke($svc,1,$session,$source,$request);
  check(!$tx->is_active()&&$c->attempted_writes===0,'network continuation attempts zero DML/DDL and releases all authority');return $result->get_code();
 });
 $phase=signal($w[1]);check($phase==='NETWORK','request started before baseline, outside locks: '.$phase);$db=new wpdb($prefix);
 try{check(Migration::run($db),'baseline prepares while provider request is in flight');$snapshot=baseline_snapshot($db);send_signal($w[1],'RETURN');check(joined($w)==='pre_baseline',$kind.': response explicitly excluded');check(baseline_snapshot($db)===$snapshot,$kind.': every provider/operational/stock/generation row unchanged');}
 finally{baseline_cleanup($db);}
}
foreach(['normal_holder','reset_holder'] as $kind){
 $prefix='c2c_basewait_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);maintenance_schema($db);$db->close();
 $holder=worker($prefix,function(wpdb $c,$s)use($kind):string{
  $t=new TableNames($c);$tx=new TransactionManager($c,$t);check($tx->begin(),'pre-upgrade holder begins');
  // Model the already-loaded v14 admission, which predates the new runtime gate.
  $r=new CommerceControlRepository($c,$t);check(($kind==='normal_holder'?$r->read('LOCK IN SHARE MODE'):$r->read_reset_control())!==null,'pre-upgrade admission owns original fence');
  send_signal($s,'HELD');signal($s);
  if($kind==='reset_holder'){maintenance_fixture($c);sql($c,"UPDATE {$t->commerce_control()} SET state='resetting'");}
  check($tx->commit(),'pre-upgrade holder commits');return 'released';
 });check(signal($holder[1])==='HELD','holder ready');
 $migrate=worker($prefix,function(wpdb $c):string{$ok=Migration::run($c);if(!$ok&&$c->last_error!=='')fwrite(STDERR,'MIGRATION: '.$c->last_error.PHP_EOL);return $ok?'prepared':'rejected';});$db=new wpdb($prefix);
 try{observed_wait($db,$migrate[2]);send_signal($holder[1],'RELEASE');joined($holder);check(joined($migrate)===($kind==='normal_holder'?'prepared':'rejected'),'preparation serializes against '.$kind);$tx=new TransactionManager($db,new TableNames($db));check(!$tx->begin_commerce_shared(),'fresh normal caller denied after fence');if($kind==='reset_holder')check($db->get_var('SELECT state FROM '.(new TableNames($db))->commerce_control())==='resetting','reset winner never overwritten');}
 finally{baseline_cleanup($db);}
}
// A waiter queued before the authoritative write must see the committed contract.
$prefix='c2c_basestale_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);maintenance_schema($db);check(Migration::run($db),'stale waiter fixture prepared');$t=new TableNames($db);$prepared=$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control());$prepared_snapshot=baseline_snapshot($db);sql($db,"UPDATE {$t->commerce_control()} SET baseline_contract=NULL,state='migrating_v15'");$db->close();
$holder=worker($prefix,function(wpdb $c,$s)use($prepared):string{$tx=new TransactionManager($c,new TableNames($c));$tx->begin();$c->get_row('SELECT * FROM '.(new TableNames($c))->commerce_control().' WHERE id=1 FOR UPDATE');sql($c,$c->prepare('UPDATE '.(new TableNames($c))->commerce_control()." SET baseline_contract=%s,state='baseline_pending' WHERE id=1",$prepared));send_signal($s,'HELD');signal($s);$tx->commit();return 'released';});signal($holder[1]);
$waiter=worker($prefix,function(wpdb $c):string{$payload=modern_payload(str_repeat('c',32),'evt_waiting_before_fence');return webhook_for($c)->handle($payload,hash('sha256',json_encode($payload)))->code();});$db=new wpdb($prefix);
try{observed_wait($db,$waiter[2]);send_signal($holder[1],'RELEASE');joined($holder);check(joined($waiter)==='pre_baseline','waiting webhook reads locked authoritative boundary');check(baseline_snapshot($db)===$prepared_snapshot,'stale webhook waiter creates no receipt identity or other write');}finally{baseline_cleanup($db);}
