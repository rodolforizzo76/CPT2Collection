<?php
/** Phase E: signed delivery committed gaps and successive real resets; no provider HTTP. */
declare(strict_types=1);
require __DIR__.'/support/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,ProviderEvidenceRouter,ProviderDiscoveryCandidate,CommittedOperationAuthority};
class EDeliveryDb extends D4Db {
 public bool $armed=false;public int $commits=0;public int $stop_at=0;public $gap=null;public bool $pending=false;
 public function query(string $q):int|false {
  if($this->pending&&str_starts_with($q,'START TRANSACTION WITH CONSISTENT SNAPSHOT')){
   $this->pending=false;check(!TransactionManager::admitted_on($this),'E gap has no reusable normal authority');$f=$this->gap;$this->gap=null;$f();
  }
  $normal=$q==='COMMIT'&&TransactionManager::admitted_on($this);$out=parent::query($q);
  if($this->armed&&$normal&&$out!==false&&++$this->commits===$this->stop_at)$this->pending=true;
  return $out;
 }
}
function e_no_dml(array $queries,string $label):void {foreach($queries as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),$label.' zero attempted operational DML');}
function e_history(wpdb $db):string {return serialize($db->get_results('SELECT * FROM '.(new TableNames($db))->commerce_resets()." WHERE status='completed' ORDER BY id"));}
$count=0;
foreach([1=>'receipt',2=>'association',3=>'sale'] as $boundary=>$name)foreach(['reset','delete'] as $destruction){
 $prefix='c2c_e_gap_'.bin2hex(random_bytes(4)).'_';$db=new EDeliveryDb($prefix);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db,1);webhook_activate($db,$f);$payload=webhook_payload($f,'evt_e_gap');$db->close();
  $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();check($pid!==-1,'E independent gap actor forked');
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);try{check(trim((string)fgets($pair[1]))==='go','E actor receives committed-stage barrier');$child=new D4Db($prefix);if($destruction==='reset')discovery_finish($child,validation_start($child));else d1_delete($child,$f);$child->close();fwrite($pair[1],"done\n");exit(0);}catch(Throwable $x){fwrite($pair[1],'FAIL:'.$x->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],30);$db=new EDeliveryDb($prefix);$db->armed=true;$db->stop_at=$boundary;$after=[];
  $db->gap=function()use($db,$pair,&$pid,&$after){fwrite($pair[0],"go\n");fflush($pair[0]);$message=trim((string)fgets($pair[0]));pcntl_waitpid($pid,$status);$pid=null;fclose($pair[0]);check($message==='done'&&pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent erasure completes strictly in committed-stage gap');$after=['snapshot'=>baseline_snapshot($db),'start'=>count($db->captured)];};
  $connections=[];$db->capture=true;$http=webhook_deliver(webhook_controller($db,$connections),$payload);check($db->gap===null&&$after!==[],'full SDK-authenticated chain reaches '.$name.' COMMIT gap');
  e_no_dml(array_slice($db->captured,$after['start']),'after '.$name.'/'.$destruction);check(baseline_snapshot($db)===$after['snapshot'],'erased source continuation changes no durable state');
  check(d3_stock($db)===[($boundary===3?19:20)],'physical stock reflects only acknowledged sale before erasure');
  check(in_array($http->get_status(),[200,500],true),'typed historical delivery ends without business resurrection');
  $db->captured=[];$more=[];check(webhook_deliver(webhook_controller($db,$more),$payload)->get_status()===200,'signed replay after committed erasure acknowledged');e_no_dml($db->captured,'erased replay');check(baseline_snapshot($db)===$after['snapshot'],'erased replay has no second effect');
  echo 'E_SCENARIO '.json_encode(['boundary'=>$name,'destruction'=>$destruction,'http'=>$http->get_status(),'stock'=>d3_stock($db)])."\n";++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}d3_cleanup($db);}
}
echo "E independent-process committed-gap matrix passed ($count scenarios).\n";
// Keep both original live-row erasure and genuine deletion through two complete resets.
$db=new D4Db('c2c_e_history_'.bin2hex(random_bytes(4)).'_');
try{
 validation_schema($db);$old=validation_order($db,1);$deleted=validation_order($db,1);$old_capture=response_captured($db,$old);$deleted_capture=response_captured($db,$deleted);d1_delete($db,$deleted);$r1=discovery_finish($db,validation_start($db));$history=e_history($db);
 $target=validation_order($db,1);webhook_activate($db,$target);$target_payload=webhook_payload($target,'evt_e_target_2');$connections=[];
 check(webhook_deliver(webhook_controller($db,$connections),$target_payload)->get_status()===200,'E2 complete response/signed sale/cart pipeline');check(d3_stock($db)===[19],'E2 sale decrements exactly once');$snapshot=baseline_snapshot($db);check(webhook_deliver(webhook_controller($db,$connections),$target_payload)->get_status()===200&&baseline_snapshot($db)===$snapshot,'E2 complete signed replay is idempotent');
 $r2=validation_start($db);$g=$r2->evidence_generation();
 foreach([[$old,$old_capture],[$deleted,$deleted_capture]] as [$f,$capture]){
  $before=response_business($db);$db->capture=true;$db->captured=[];$evidence=[];$p=new ModernBoundaryProvider();$result=response_resume(response_service($db,$p,$evidence),$capture);check(!$result->is_success(),'old response cannot reactivate erased original Session');e_no_dml($db->captured,'old response in R2');response_no_operational($evidence);check(response_business($db)===$before,'old response preserves all business and stock');
  $payload=webhook_payload($f,'evt_e_old_'.$f['claim']);$db->captured=[];$connections=[];check(webhook_deliver(webhook_controller($db,$connections),$payload)->get_status()===200,'late signed old/deleted Event retained in R2');e_no_dml($db->captured,'old signed ingress in R2');response_no_operational($connections);
  $t=new TableNames($db);check($db->get_var($db->prepare('SELECT disposition FROM '.$t->provider_identities().' WHERE identity_kind=%s AND identity_value=%s','event',$payload['id']))==='pending','new historical evidence stays pending, never operational terminal authority');check(e_history($db)===$history,'R1 completed history remains byte-identical during R2 evidence');
 }
 $r2=validation_service($db)->resume($r2->operation_identity())->operation();check($r2->evidence_generation()===$g+6,'only active R2 receives six actual changed-key units');$r2=discovery_finish($db,$r2);$history=e_history($db);
 $target3=validation_order($db,1);webhook_activate($db,$target3);$payload3=webhook_payload($target3,'evt_e_target_3');$connections=[];check(webhook_deliver(webhook_controller($db,$connections),$payload3)->get_status()===200,'E3 normal commerce survives two source resets');check(d3_stock($db)===[18],'two legitimate target sales, exactly two physical decrements');
 foreach([[$old,$old_capture],[$deleted,$deleted_capture],[$target,null]] as [$f,$capture]){
  $business=response_business($db);$db->captured=[];$connections=[];$p=webhook_payload($f,'evt_e_after_two_'.$f['claim']);check(webhook_deliver(webhook_controller($db,$connections),$p)->get_status()===200,'late old/deleted/previous-target signed evidence after two resets');e_no_dml($db->captured,'E3 historical ingress');check(response_business($db)===$business&&e_history($db)===$history,'late history never changes target commerce or either completed certificate');
  if($capture!==null){$evidence=[];$provider=new ModernBoundaryProvider();$provider->exception=true;$db->captured=[];check(!response_resume(response_service($db,$provider,$evidence),$capture)->is_success(),'late identity-bearing provider exception stays nonoperational');e_no_dml($db->captured,'late provider exception');}
 }
 $t=new TableNames($db);check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders()." WHERE status='paid' AND cart_reconciliation_status='completed'")===1,'only current E3 paid/reconciled Order exists');check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims())===4,'all four exact native claims survive without recreation');
 echo 'E_HISTORY '.json_encode(['resets'=>2,'stock'=>d3_stock($db),'claims'=>4,'current_paid_reconciled_orders'=>1])."\n";
}finally{d3_cleanup($db);}
echo "Phase E successive-reset integrated lifecycle passed.\n";
