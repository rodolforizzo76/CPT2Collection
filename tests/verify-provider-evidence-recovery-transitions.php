<?php
/** A1.1 independent fresh-recovery waits across reset/history and observer changes. */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderEvidenceRouter,ProviderRegistry,TransactionManager,TableNames,CommerceResetRepository};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['progress','final','later','older','duplicate','conflict'] as $mode)foreach(['before','after'] as $fault){
 $prefix='c2c_a11_race_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$t=new TableNames($db);$frozen=[];
  if($mode==='older'){$done=discovery_finish($db,validation_start($db));$frozen[$done->id()]=serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));}
  $r=validation_start($db);
  $input=in_array($mode,['duplicate','conflict'],true)?discovery_webhook($f,event:'evt_a11_race',session:'cs_a11_race'):discovery_input($db,$f,'cs_a11_race');
  $db->fault=$fault;$out=(new ProviderEvidenceRouter($db,$t))->retain($input);$ticket=$out->recovery;
  check($out->status==='transaction_outcome_unknown'&&$out->mutation_possible&&$ticket!==null,'transition begins with immutable descriptor '.$mode.' '.$fault);
  check($ticket->domain->operation_identity()===$r->operation_identity(),'original active domain captured independently of owner');
  $db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);
  if(in_array($mode,['later','older'],true)){
   $done=discovery_finish($db,validation_service($db)->resume($r->operation_identity())->operation());$frozen[$done->id()]=serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));
   $r=validation_start($db);
  }
  if($mode==='final'){
   $r=retirement_pass($db,validation_pass($db,validation_service($db)->resume($r->operation_identity())->operation(),100),100);
   $r=deletion_pass($db,deletion_retained($db,$r,100),100);
  }else $r=validation_service($db)->resume($r->operation_identity())->operation();
  $db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){
   fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);$child=new DiscoveryDb($prefix);$child->capture=true;
   fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);
   try{$recovered=(new ProviderEvidenceRouter($child,new TableNames($child)))->recover($ticket);$writes=count(array_filter($child->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)));fwrite($pair[1],$recovered->status.'|'.$writes."\n");$child->close();exit(0);}
   catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}
  }
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new DiscoveryDb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
  if($mode==='final'){
   check($tx->begin_reset_finalization($r),'completion holder admits');check($repo->finalize($r,$r->updated_at())!==false,'completion holder prepares atomic switch');
  }elseif(in_array($mode,['duplicate','conflict'],true)){
   $other=$mode==='conflict'?discovery_webhook($f,event:'evt_a11_race',session:'cs_a11_race',hash:'changed'):$input;
   check($tx->begin_retained_evidence($other)!==null,'observer holder admits');$cap=TransactionManager::retained_context_on($db);
   check($cap!==null&&in_array((new ProviderRegistry($db,$t))->bind_retained($cap)['status'],['retained','conflict'],true),'observer applies original immutable policy');
  }else{
   check($tx->begin_reset_maintenance($r),'progression holder admits');check($repo->progress($r,$r->phase(),$r->checkpoint(),$r->cursor(),'a11_race',gmdate('Y-m-d H:i:s'))!==false,'progression holder prepares block without reassignment');
  }
  fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$observer=new wpdb($prefix);$waiting=false;$deadline=microtime(true)+10;
  do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT %FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(30000);}while(!$waiting&&microtime(true)<$deadline);
  $read=[$pair[0]];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,100000)===0,'independent recovery waits at control '.$mode.' '.$fault);
  check($tx->commit(),'holder commits');$snapshot=baseline_snapshot($db);$result=trim(fgets($pair[0]));pcntl_waitpid($pid,$exit);$pid=null;
  check(pcntl_wifexited($exit)&&pcntl_wexitstatus($exit)===0,'independent recovery exits cleanly');
  $expected=$mode==='conflict'?'verification_required':($mode==='duplicate'||$fault==='after'?'already_present':'reentry_required');
  check($result===$expected.'|0','fresh recovery outcome and zero replay '.$mode.' '.$fault);
  check($snapshot===baseline_snapshot($db),'fresh recovery changes no evidence/generation/business state');
  foreach($frozen as $id=>$bytes)check($bytes===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$id)),'completed original/domain history remains byte-identical');
  if($mode==='final')check($db->get_var('SELECT status FROM '.$t->commerce_resets().' WHERE id='.$r->id())==='completed','completion remains canonical after recovery');
  $observer->close();fclose($pair[0]);
 }finally{if($pid!==null&&$pid>0)pcntl_waitpid($pid,$exit);baseline_cleanup($db);}
}
echo "All A1.1 independent recovery transition scenarios passed.\n";
