<?php
/** Independent workers observed waiting at the real exclusive control fence. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceResetRepository,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['claim','revalidation','claim_final','identity','event_final','evidence_before_final','final_before_evidence'] as $mode){
 $prefix='c2c_b_race_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);$pid=null;
 try{
  validation_schema($db);$f=validation_order($db);$r=validation_start($db);
  $r=validation_pass($db,$r);$svc=validation_service($db);
  if(in_array($mode,['revalidation','claim_final'],true)){$r=$svc->retire($r,100)->operation();if($mode==='revalidation')$r=validation_bind($db,$r,$f['claim'],'session','cs_pre_revalidation');}
  elseif(in_array($mode,['identity','event_final','evidence_before_final','final_before_evidence'],true)){$r=retirement_pass($db,$r,1,'b_i');if($mode!=='identity'){while($r->cursor()<(int)$db->get_var('SELECT COALESCE(MAX(id),0) FROM '.$t->provider_identities()))$r=$svc->retire($r,1)->operation();}}
  $before=retirement_preserved($db);$claim=ProviderClaimRecord::from_row($db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id={$f['claim']}"));$db->close();
  $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$child=new wpdb($prefix);$ct=new TableNames($child);$tx=new TransactionManager($child,$ct);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
	if($mode==='final_before_evidence'){$code=$tx->begin_historical_evidence(new CommerceEvidenceContext($r,$claim))?'unexpected':'stale_evidence';}
	else{$code=validation_service($child)->retire($r,1)->code();}
	if($tx->is_active())$tx->rollback();fwrite($pair[1],$code."\n");fflush($pair[1]);$child->close();exit(0);
   }catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new wpdb($prefix);$observer=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
  if($mode==='evidence_before_final'){$e=new CommerceEvidenceContext($r,$claim);check($tx->begin_historical_evidence($e),'historical holder owns pre-final boundary');check((new ProviderRegistry($db,$t))->bind_historical($e,'stripe_checkout','session','cs_concurrent',gmdate('Y-m-d H:i:s'),'test')==='bound','holder changes evidence before final admission');}
  else{check($tx->begin_reset_maintenance($r),'validator holder owns exact snapshot');$written=$repo->retire($r,1,gmdate('Y-m-d H:i:s'));check($written!==false,'validator writes one batch before waiter');}
  fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+5;$waiting=false;
  do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT * FROM {$t->commerce_control()}%'")>0;if(!$waiting)usleep(20000);}while(!$waiting&&microtime(true)<$deadline);
  $read=[$pair[0]];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,100000)===0,$mode.': independent process actually waits at control');check($tx->commit(),'holder outcome is known');$code=trim(fgets($pair[0]));check($code===($mode==='final_before_evidence'?'stale_evidence':'stale'),$mode.': old worker cannot overwrite committed state');pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent worker exits successfully');fclose($pair[0]);$observer->close();
  $fresh=validation_service($db)->resume($r->operation_identity())->operation();
  if($mode==='evidence_before_final'){check($fresh->phase()==='terminalizing_events'&&$fresh->evidence_generation()===$r->evidence_generation()+1&&$fresh->validation_generation()===$r->validation_generation(),'concurrent pre-final evidence invalidates certification');$fresh=validation_service($db)->retire($fresh,1)->operation();check(str_starts_with($fresh->checkpoint(),'r_i_')&&$fresh->cursor()===0,'fresh worker revalidates without stale deleting transition');}
  else{check($fresh->equals($written),'fresh worker recovers exact winning B revision');}
  $final=retirement_pass($db,$fresh);check($final->phase()==='deleting'&&$final->validation_generation()===$final->evidence_generation(),'B stabilizes at one generation-fresh deleting boundary');check(validation_service($db)->retire($r,1)->code()==='stale','stale B snapshot cannot duplicate transition');check($before===retirement_preserved($db),'B workers preserve operational commerce, stock, products and epochs');
 }finally{if($pid!==null&&$pid>0){pcntl_waitpid($pid,$status);}try{$db->close();}catch(Throwable $ignored){}baseline_cleanup(new wpdb($prefix));}
}
echo "All B independent-connection concurrency checks passed.\n";
