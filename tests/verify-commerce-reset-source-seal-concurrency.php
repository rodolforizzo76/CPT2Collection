<?php
/** Independent workers observed waiting at the real exclusive control fence. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceResetRepository,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['event_final','evidence_before_final','final_before_evidence','retained_worker','retained_evidence_first','retained_before_evidence'] as $mode){
 $prefix='c2c_b_race_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$r=validation_start($db);
  $r=validation_pass($db,$r);$svc=validation_service($db);
  if(in_array($mode,['revalidation','claim_final'],true)){$r=$svc->retire($r,100)->operation();if($mode==='revalidation')$r=validation_bind($db,$r,$f['claim'],'session','cs_pre_revalidation');}
  elseif(in_array($mode,['identity','event_final','evidence_before_final','final_before_evidence'],true)){$r=retirement_pass($db,$r,1,'s1_done');}
  $post=str_starts_with($mode,'retained_');if($post){$r=retirement_pass($db,$r,1);$r=validation_bind($db,$r,$f['claim'],'event','evt_retained_race',hash('sha256','retained'),'checkout.session.completed');do{$r=$svc->certify_retained($r,1)->operation();}while($r->checkpoint()!=='z1_e_0_i');}
  $before=retirement_preserved($db);$claim=ProviderClaimRecord::from_row($db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id={$f['claim']}"));$db->close();
  $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$child=new wpdb($prefix);$ct=new TableNames($child);$tx=new TransactionManager($child,$ct);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
	if(in_array($mode,['final_before_evidence','retained_before_evidence'],true)){$code=$tx->begin_historical_evidence(new CommerceEvidenceContext($r,$claim))?'unexpected':'stale_evidence';}
	else{$code=$post?validation_service($child)->certify_retained($r,1)->code():validation_service($child)->retire($r,1)->code();}
	if($tx->is_active())$tx->rollback();fwrite($pair[1],$code."\n");fflush($pair[1]);$child->close();exit(0);
   }catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new wpdb($prefix);$observer=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
  if(in_array($mode,['evidence_before_final','retained_evidence_first'],true)){$e=new CommerceEvidenceContext($r,$claim);check($tx->begin_historical_evidence($e),'historical holder owns pre-final boundary');check((new ProviderRegistry($db,$t))->bind_historical($e,'stripe_checkout','session','cs_concurrent',gmdate('Y-m-d H:i:s'),'test')==='bound','holder changes evidence before final admission');}
  else{check($tx->begin_reset_maintenance($r),'validator holder owns exact snapshot');$written=$post?$repo->certify_retained($r,1,gmdate('Y-m-d H:i:s')):$repo->retire($r,1,gmdate('Y-m-d H:i:s'));check($written!==false,'validator writes one batch before waiter');}
  fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+5;$waiting=false;
  do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT * FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(20000);}while(!$waiting&&microtime(true)<$deadline);
  $read=[$pair[0]];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,100000)===0,$mode.': independent process actually waits at control');check($tx->commit(),'holder outcome is known');$code=trim(fgets($pair[0]));check($code===(in_array($mode,['final_before_evidence','retained_before_evidence'],true)?'stale_evidence':'stale'),$mode.': old worker cannot overwrite committed state');pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent worker exits successfully');fclose($pair[0]);$observer->close();
  $fresh=validation_service($db)->resume($r->operation_identity())->operation();
  if(in_array($mode,['evidence_before_final','retained_evidence_first'],true)){check($fresh->phase()===($post?'deleting':'terminalizing_events')&&$fresh->evidence_generation()===$r->evidence_generation()+1&&$fresh->validation_generation()===$r->validation_generation(),'concurrent pre-final evidence invalidates certification');$fresh=$post?validation_service($db)->certify_retained($fresh,1)->operation():validation_service($db)->retire($fresh,1)->operation();check($fresh->checkpoint()===($post?'z1_e_0_c':'s1_s')&&$fresh->cursor()===0,'fresh worker revalidates without stale deleting transition');}
  else{check($fresh->equals($written),'fresh worker recovers exact winning B revision');}
  if(in_array($mode,['final_before_evidence','retained_before_evidence'],true)){$fresh=validation_bind($db,$fresh,$f['claim'],'event','evt_after_seal_retry',hash('sha256','retry'),'checkout.session.completed');check($fresh->phase()==='deleting'&&str_starts_with($fresh->checkpoint(),'z1_'),'fresh retry admits historical evidence after seal');}
  $final=retirement_pass($db,$fresh);if($post||in_array($mode,['final_before_evidence','retained_before_evidence'],true)){do{$final=validation_service($db)->certify_retained($final,1)->operation();}while($final->checkpoint()!=='z1_e_0_done');}check($final->phase()==='deleting'&&$final->validation_generation()===$final->evidence_generation(),'B stabilizes at one generation-fresh deleting boundary');check(($post?validation_service($db)->certify_retained($r,1):validation_service($db)->retire($r,1))->code()==='stale','stale B snapshot cannot duplicate transition');check($before===retirement_preserved($db),'B workers preserve operational commerce, stock, products and epochs');
 }finally{if($pid!==null&&$pid>0){pcntl_waitpid($pid,$status);}try{$db->close();}catch(Throwable $ignored){}baseline_cleanup(new wpdb($prefix));}
}
echo "All strengthened seal independent-process race checks passed.\n";
