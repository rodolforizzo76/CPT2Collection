<?php
/** Independent processes, observed control waits, both mutation/reset orderings. */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderEvidenceRouter,ProviderRegistry,TransactionManager,TableNames,CommerceResetRepository};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['progress','final','older','same','conflict','start','complete','later'] as $mode)foreach(['a1','other'] as $first){
 $prefix='c2c_a1_race_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);$pid=null;
 try {
  validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f,'cs_race');$a0=discovery_run($db,$input);
  $r=null;$history=null;
  if(in_array($mode,['progress','final','older','complete','later'],true))$r=validation_start($db);
  if(in_array($mode,['final','complete'],true)){$r=retirement_pass($db,validation_pass($db,$r,100),100);$r=deletion_pass($db,deletion_retained($db,$r,100),100);}
  if(in_array($mode,['older','later'],true)){$done=discovery_finish($db,$r);$history=serialize($db->get_row('SELECT * FROM '.(new TableNames($db))->commerce_resets().' WHERE id='.$done->id()));$r=validation_start($db);}
  if($mode==='conflict'){$input=discovery_webhook($f,event:'evt_race',session:'cs_race');$different=discovery_webhook($f,event:'evt_race',session:'cs_race',hash:'changed');}else $different=$input;
  $db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new DiscoveryDb($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='other'||in_array($mode,['same','conflict'],true))$code=(new ProviderEvidenceRouter($child,new TableNames($child)))->retain($first==='a1'?$different:$input)->status;
   elseif(in_array($mode,['final','complete'],true))$code=validation_service($child)->finalize($r)->code();
   elseif($mode==='start')$code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();
   else $code=validation_service($child)->block($r,'race')->code();
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new DiscoveryDb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);$observer=new wpdb($prefix);
  if($first==='a1'||in_array($mode,['same','conflict'],true)){
   check($tx->begin_retained_evidence($first==='other'?$different:$input)!==null,'holder fresh A1 admission');$cap=TransactionManager::retained_context_on($db);check($cap!==null,'holder narrow capability');check((new ProviderRegistry($db,$t))->bind_retained($cap)['status']==='retained','holder evidence mutation');
  }elseif(in_array($mode,['final','complete'],true)){
   check($tx->begin_reset_finalization($r),'final switch holder');check($repo->finalize($r,$r->updated_at())!==false,'prepared atomic final switch');
  }elseif($mode==='start'){
   check($tx->begin_reset_control(),'reset start holder');check($repo->start(bin2hex(random_bytes(16)),$f['epoch'],null,gmdate('Y-m-d H:i:s'))!==false,'prepared reset start');
  }else {check($tx->begin_reset_maintenance($r),'maintenance holder');check($repo->progress($r,$r->phase(),$r->checkpoint(),$r->cursor(),'race',gmdate('Y-m-d H:i:s'))!==false,'prepared blocked maintenance');}
  fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$waiting=false;$deadline=microtime(true)+10;
  do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT %FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(30000);}while(!$waiting&&microtime(true)<$deadline);
  $read=[$pair[0]];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,100000)===0,"$mode $first independent waiter blocked at control before epoch/reset/provider locks");
  check($tx->commit(),'holder commits');$code=trim(fgets($pair[0]));pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'child clean exit '.$code);
  if($first==='other')check($code===($mode==='same'?'duplicate':($mode==='conflict'?'conflict':'retained')),'fresh revalidation after winning other actor');
  elseif($mode==='same')check($code==='duplicate','same identity serial duplicate');
  elseif($mode==='conflict')check($code==='conflict','conflicting observation serial quarantine');
  elseif($mode==='start')check($code==='started','reset starts after evidence');
  else check($code==='stale','generation/snapshot fence rejects stale maintenance/finalizer');
  if($r!==null){$fresh=validation_service($db)->resume($r->operation_identity())->operation();$n=$first==='other'&&in_array($mode,['final','complete'],true)?0:1;check($fresh->evidence_generation()===$r->evidence_generation()+$n,'one charge per actual identity change');}

  if(in_array($mode,['final','complete'],true)&&$first==='a1'){$cert=deletion_retained($db,$fresh,1);check(validation_service($db)->finalize($cert)->code()==='completed','A1 invalidation requires retained recertification before final switch');}
  if($mode==='conflict'){$event=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_kind='event' AND identity_value='evt_race'");check($event->binding_state==='quarantined'&&$event->payload_sha256===hash('sha256',$first==='a1'?'body':'changed'),'concurrent conflict preserves first immutable hash and quarantines');}
  if($history!==null)check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'original completed history frozen during later-reset race');
  $observer->close();fclose($pair[0]);
 }finally{if($pid!==null&&$pid>0)pcntl_waitpid($pid,$status);baseline_cleanup($db);}
}

foreach(['before','after'] as $fault){
 $prefix='c2c_a1_commit_race_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$r=validation_start($db);$i=discovery_input($db,$f,'cs_commit_race');$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);$ticket=unserialize(base64_decode(trim(fgets($pair[1]))));$child=new DiscoveryDb($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);$out=(new ProviderEvidenceRouter($child,new TableNames($child)))->recover($ticket);fwrite($pair[1],$out->status."\n");$child->close();exit(0);}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new DiscoveryDb($prefix);$t=new TableNames($db);$db->fault=$fault;$out=(new ProviderEvidenceRouter($db,$t))->retain($i);check($out->status==='transaction_outcome_unknown','independent recovery uncertain');
  fwrite($pair[0],base64_encode(serialize($out->recovery))."\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));
  if($fault==='before'){
   $observer=new wpdb($prefix);$waiting=false;$deadline=microtime(true)+10;
   do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT %FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(30000);}while(!$waiting&&microtime(true)<$deadline);
   check($waiting,'fresh independent recovery waits for unknown old owner to drain');$observer->close();
  }
  $db->close();$db=new DiscoveryDb($prefix);$status=trim(fgets($pair[0]));check($status===($fault==='before'?'reentry_required':'already_present'),'fresh independent recovery whole outcome');pcntl_waitpid($pid,$exit);$pid=null;check(pcntl_wifexited($exit)&&pcntl_wexitstatus($exit)===0,'recovery process clean exit');fclose($pair[0]);
 }finally{if($pid!==null&&$pid>0)pcntl_waitpid($pid,$exit);baseline_cleanup($db);}
}
echo "All A1 independent-process race orderings passed.\n";
