<?php
/** Independent processes actually wait at foundation fence in both race orderings. */
declare(strict_types=1);
require __DIR__.'/support/reset-final-switch.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRepository,TransactionManager,TableNames,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry,CommerceContext};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['finalizer','historical','normal','retained','terminal','deletion','evidence_first','retained_first','deletion_first','blocked_first'] as $mode){
 $prefix='c2c_final_race_'.bin2hex(random_bytes(5)).'_';$db=new FinalSwitchDb($prefix);$pid=null;
 try {
  [$r,$f]=final_setup($db);$t=new TableNames($db);$claim=ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f[0]['claim']));$stock=deletion_stock($db);
  $stale=get_object_vars($db->get_row('SELECT * FROM '.$t->commerce_resets()));$stale['checkpoint']='z1_x_0_done';$stale['revision']--;$stale=\RodolfoRizzo\Cpt2Collection\CommerceResetRecord::from_row((object)$stale);
  $db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$child=new FinalSwitchDb($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   $tx=new TransactionManager($child,new TableNames($child));
   $code=match($mode){
	'historical'=>$tx->begin_historical_evidence(new CommerceEvidenceContext($r,$claim))?'unexpected':$tx->admission_error(),
	'normal'=>$tx->begin_commerce_shared(new CommerceContext($r->installation_id(),$r->source_epoch_id(),$r->source_epoch_identity()))?'unexpected':$tx->admission_error(),
	'retained','terminal'=>validation_service($child)->certify_retained($r)->code(),
	'deletion'=>validation_service($child)->delete_operational($stale)->code(),
	default=>validation_service($child)->finalize($r)->code()
   };
   fwrite($pair[1],$code."\n");fflush($pair[1]);$child->close();exit(0);
  }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new FinalSwitchDb($prefix);$observer=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
  if($mode==='evidence_first'){
   $e=new CommerceEvidenceContext($r,$claim);check($tx->begin_historical_evidence($e),'evidence-first holder owns shared control/source and exclusive reset');check((new ProviderRegistry($db,$t))->bind_historical($e,'stripe_checkout','event','evt_final_evidence',gmdate('Y-m-d H:i:s'),'test',hash('sha256','final'),'checkout.session.completed')==='bound','real evidence-first mutation reserves generation');
  }elseif(in_array($mode,['retained_first','deletion_first','blocked_first'],true)){
   check($mode==='deletion_first'?$tx->begin_reset_deletion($r):$tx->begin_reset_maintenance($r),'worker-first holder owns exclusive foundation');
   $written=match($mode){'deletion_first'=>$repo->delete_operational($r,1,$r->updated_at()),'retained_first'=>$repo->certify_retained($r,1,$r->updated_at()),'blocked_first'=>$repo->progress($r,$r->phase(),$r->checkpoint(),0,'final_test_block',$r->updated_at())};check($written!==false,'worker performs owned bounded/no-op/block step');
  }else{
   check($tx->begin_reset_finalization($r),'finalizer-first owns exclusive control/source/reset');$done=$repo->finalize($r,$r->updated_at());check($done!==false,'holder prepares whole final switch');
  }
  fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+10;$waiting=false;
  do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT * FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(30000);}while(!$waiting&&microtime(true)<$deadline);
  $read=[$pair[0]];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,100000)===0,'independent '.$mode.' actually waits at control fence');
  check($tx->commit(),'holder commits one owned transaction');$code=trim(fgets($pair[0]));
  $expected=match($mode){'finalizer','retained_first','deletion_first'=>'completed','evidence_first','blocked_first'=>'stale','historical'=>'commerce_not_ready','normal'=>'commerce_context_mismatch',default=>'inconsistent_state'};
  check($code===$expected,'waiting '.$mode.' rechecks durable state: '.$code);pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent process exits cleanly');fclose($pair[0]);$observer->close();
  $fresh=validation_service($db)->resume($r->operation_identity())->operation();
  if($mode==='evidence_first'){
   check($fresh->phase()==='deleting'&&$fresh->target_epoch_id()===null&&$fresh->evidence_generation()===$r->evidence_generation()+1&&$fresh->validation_generation()===$r->validation_generation(),'evidence first leaves exact frontier stale with no epoch mutation');
   check(validation_service($db)->finalize($fresh)->code()==='stale','stale retained certification cannot allocate');$fresh=deletion_retained($db,$fresh,1);check(validation_service($db)->finalize($fresh)->code()==='completed','bounded retained-only recertification permits fresh final retry');
  }elseif($mode==='blocked_first')check($fresh->status()==='blocked'&&$fresh->target_epoch_id()===null,'blocked worker wins without reopening');
  else{
   check($fresh->completion_of($r),'race commits exactly one canonical completion');check(count($db->get_results('SELECT id FROM '.$t->commerce_epochs()))===2,'exactly one numeric target, no duplicate allocation');
   check(!$tx->begin_historical_evidence(new CommerceEvidenceContext($fresh,$claim)),'fresh completed history does not grant historical primitive');
  }
  check(deletion_stock($db)===$stock,'all race orderings preserve stock');
 }finally{if($pid!==null&&$pid>0)pcntl_waitpid($pid,$status);baseline_cleanup($db);}
}
echo "All final-switch independent-process races passed.\n";
