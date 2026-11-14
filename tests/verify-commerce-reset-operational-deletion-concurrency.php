<?php
/** Independent processes: exclusive control/source/reset vs evidence and validator workers. */
declare(strict_types=1);
require __DIR__.'/support/reset-deletion.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetDeletion as D,CommerceResetRepository,TransactionManager,TableNames,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['same_batch','transition','empty_certificate','retained_waiter','evidence_waiter','evidence_holder','evidence_partial_holder','evidence_empty_holder','validator_holder','blocked_holder','lost_commit_holder'] as $mode){
 $prefix='c2c_erase_race_'.bin2hex(random_bytes(5)).'_';$db=new DeletionDb($prefix);$t=new TableNames($db);$pid=null;
 try{[$r,$f]=deletion_setup($db,2);if($mode==='transition')$r=deletion_pass($db,$r,100,'e',true);if($mode==='empty_certificate')$r=deletion_pass($db,$r,100,'x');
 if($mode==='evidence_partial_holder')$r=deletion_pass($db,$r,100,'o');if($mode==='evidence_empty_holder')$r=deletion_pass($db,$r,100);
 if($mode==='validator_holder')$r=validation_bind($db,$r,$f[0]['claim'],'event','evt_waiter_catchup',hash('sha256','waiter'),'checkout.session.completed');
 $claim=ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f[0]['claim']));$stock=deletion_stock($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
 if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$child=new DeletionDb($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
  if($mode==='evidence_waiter'){$e=new CommerceEvidenceContext($r,$claim);$tx=new TransactionManager($child,new TableNames($child));$code=$tx->begin_historical_evidence($e)?'unexpected':$tx->admission_error();}
  else $code=$mode==='retained_waiter'?validation_service($child)->certify_retained($r)->code():validation_service($child)->delete_operational($r,1)->code();
  fwrite($pair[1],$code."\n");fflush($pair[1]);$child->close();exit(0);
 }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
 fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new DeletionDb($prefix);$observer=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
 if(str_starts_with($mode,'evidence_')&&$mode!=='evidence_waiter'){$e=new CommerceEvidenceContext($r,$claim);check($tx->begin_historical_evidence($e),'shared control/source, exclusive reset evidence holder');check((new ProviderRegistry($db,$t))->bind_historical($e,'stripe_checkout','event','evt_during_erase',gmdate('Y-m-d H:i:s'),'test',hash('sha256','during'),'checkout.session.completed')==='bound','historical holder changes retained evidence');}
 else{check($tx->begin_reset_deletion($r),'exclusive control/source/reset holder');$written=$mode==='blocked_holder'?$repo->progress($r,$r->phase(),$r->checkpoint(),$r->cursor(),'diagnostic_block',gmdate('Y-m-d H:i:s')):($mode==='validator_holder'?$repo->certify_retained($r,1,gmdate('Y-m-d H:i:s')):$repo->delete_operational($r,1,gmdate('Y-m-d H:i:s')));check($written!==false,'holder writes exact batch/transition/certification');}
 fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+10;$waiting=false;
 do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT * FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(30000);}while(!$waiting&&microtime(true)<$deadline);
 $read=[$pair[0]];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,100000)===0,'independent '.$mode.' worker actually waits at authority lock: '.json_encode($observer->get_results('SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID='.$id)));
 if($mode==='lost_commit_holder'){$db->fault='after';check(!$tx->commit()&&!$db->transaction_connection_usable(),'holder loses COMMIT acknowledgment and connection authority');$db->close();$db=new DeletionDb($prefix);}else check($tx->commit(),'holder commits known transaction');
 $code=trim(fgets($pair[0]));$expected=$mode==='evidence_waiter'?'reset_snapshot_stale':'stale';check($code===$expected,'stale '.$mode.' worker cannot overwrite winning state');pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent process clean exit');fclose($pair[0]);$observer->close();
 $fresh=validation_service($db)->resume($r->operation_identity())->operation();
 if(str_starts_with($mode,'evidence_')&&$mode!=='evidence_waiter'){check(D::position($fresh)===D::position($r)&&$fresh->evidence_generation()===$r->evidence_generation()+1,'historical evidence preserves committed physical frontier');$fresh=deletion_pass($db,$fresh);}
 elseif($mode==='blocked_holder'){check($fresh->status()==='blocked'&&D::position($fresh)===D::position($r),'blocked winner preserves seal/frontier');check(validation_service($db)->delete_operational($fresh)->code()==='conflict','fresh deletion worker cannot enter blocked state');$fresh=validation_bind($db,$fresh,$f[0]['claim'],'session','cs_blocked_process');check($fresh->status()==='blocked','historical evidence remains admitted after block');}
 else{check($fresh->equals($written),'fresh recovery inspects exact winning durable revision');$fresh=deletion_pass($db,$fresh);}
 check(deletion_stock($db)===$stock,'concurrent independent workers never mutate physical stock');if($mode!=='blocked_holder')check(D::certified_empty($fresh),'independent race converges to operational empty certificate');
 }finally{if($pid!==null&&$pid>0){pcntl_waitpid($pid,$status);}baseline_cleanup($db);}
}
echo "All operational erasure independent-process races passed.\n";
