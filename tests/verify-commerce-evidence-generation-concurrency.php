<?php
/** Independent processes prove v14 reset-row serialization, stale fencing and generation accounting. */
declare(strict_types=1);
require __DIR__.'/support/historical-evidence.php';
use RodolfoRizzo\Cpt2Collection\{CommerceEvidenceContext,CommerceResetRecord,ProviderClaimRecord,ProviderRegistry,TransactionManager,TableNames,CommerceResetRepository};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
function context_for(wpdb $db,int $id):CommerceEvidenceContext{$t=new TableNames($db);return new CommerceEvidenceContext(CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets())),ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$id)));}
foreach(['different_claims','evidence_first','maintenance_first','blocked'] as $mode){
 $prefix='c2c_a0race_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);evidence_fixture($db);$t=new TableNames($db);
 sql($db,"INSERT INTO {$t->provider_claims()} (installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,request_snapshot,environment,evidence_state,disposition,created_at) SELECT installation_id,epoch_id,epoch_identity,provider,REPEAT('e',32),2,2,REPEAT('e',64),CONCAT('c2c_ps_',REPEAT('e',32)),2,NULL,environment,'native','open',created_at FROM {$t->provider_claims()} WHERE id=1");native_provider_claim_fixture($db,2);
 if($mode==='blocked')sql($db,"UPDATE {$t->commerce_resets()} SET status='blocked',error_code='fixture',checkpoint='kept',`cursor`=42");
 $expected=context_for($db,1);$other=context_for($db,2);$before=evidence_preserved_snapshot($db);$db->close();
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
 if($pid===0){
  fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$db=new wpdb($prefix);sql($db,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$t=new TableNames($db);$tx=new TransactionManager($db,$t);
  try{
   fwrite($pair[1],$db->dbh->thread_id."\n");fflush($pair[1]);
   $maintenance=$mode==='evidence_first';$ok=$maintenance?$tx->begin_reset_maintenance($expected->operation()):$tx->begin_historical_evidence($other);
   check(!$ok && $tx->admission_error()==='reset_snapshot_stale',$mode.': waiting old snapshot rejected after lock (admitted='.(int)$ok.', error='.$tx->admission_error().', sql='.$db->last_error.')');
   $fresh=context_for($db,2);
   check($maintenance?$tx->begin_reset_maintenance($fresh->operation()):$tx->begin_historical_evidence($fresh),$mode.': fresh snapshot admits');
   if($maintenance){check(false!==(new CommerceResetRepository($db,$t))->progress($fresh->operation(),'validating','fixture',1,null,'2026-01-02 00:00:00'),'maintenance retry checkpoints only');}
   else{check('bound'===(new ProviderRegistry($db,$t))->bind_historical($fresh,'stripe_checkout','session','cs_child','2026-01-02 00:00:00','test'),'fresh evidence retry binds once');}
   check($tx->commit(),'child commits');fwrite($pair[1],"done\n");fflush($pair[1]);$db->close();exit(0);
  }catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}
 }
 fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new wpdb($prefix);$observer=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);
 try{
  check($mode==='maintenance_first'?$tx->begin_reset_maintenance($expected->operation()):$tx->begin_historical_evidence($expected),$mode.': holder admitted');
  if($mode==='maintenance_first'){check(false!==(new CommerceResetRepository($db,$t))->progress($expected->operation(),'validating','fixture',1,null,'2026-01-02 00:00:00'),'maintenance holder changes revision');}
  else{check('bound'===(new ProviderRegistry($db,$t))->bind_historical($expected,'stripe_checkout','session','cs_parent','2026-01-02 00:00:00','test'),'evidence holder changes generation');}
  fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+5;$waiting=false;
  $pattern=in_array($mode,['different_claims','blocked'],true)?'SELECT * FROM '.$t->commerce_resets().'%':'SELECT * FROM '.$t->commerce_control().'%';
  do{$waiting=(int)$observer->get_var($observer->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=%d AND INFO LIKE %s',$id,$pattern))>0;if(!$waiting)usleep(20000);}while(!$waiting && microtime(true)<$deadline);
  $read=[$pair[0]];$write=[];$except=[];check($waiting && stream_select($read,$write,$except,0,200000)===0,$mode.': actual server wait observed at canonical boundary');
  check($tx->commit(),'parent commits');check(rtrim((string)fgets($pair[0]))==='done','child completed after release');pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent child successful');
  $record=context_for($db,1)->operation();$count=in_array($mode,['different_claims','blocked'],true)?2:1;
  check($record->evidence_generation()===$count && (int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE identity_value IN ('cs_parent','cs_child')")===$count,$mode.': no lost increments; exactly committed mutations counted');
  check(!$tx->begin_commerce_shared() && !$tx->begin_commerce_exclusive(),'normal commerce stays fenced');
  if($mode==='blocked'){check($record->status()==='blocked' && $record->error_code()==='fixture' && $record->cursor()===42 && $record->revision()===0,'blocked metadata never cleared');}
  if(in_array($mode,['different_claims','blocked'],true))check($before===evidence_preserved_snapshot($db),'concurrent evidence preserves all other durable state');
 }finally{$tx->rollback();$db->close();$observer->close();fclose($pair[0]);maintenance_cleanup(new wpdb($prefix));}
}
echo "All evidence-generation independent-process checks passed.\n";
