<?php
/** Fresh forked connections, observed server waits, both maintenance/evidence orderings. */
declare( strict_types=1 );
require __DIR__ . '/support/historical-evidence.php';
use RodolfoRizzo\Cpt2Collection\{TransactionManager,TableNames,ProviderRegistry,CommerceResetRepository,CartRepository,CommerceEvidenceContext};
if(!function_exists('pcntl_fork')){throw new RuntimeException('pcntl required');}
$prefix='c2c_p42brace_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$expected=evidence_fixture($db);$before=evidence_preserved_snapshot($db);$db->close();
function evidence_race(string $prefix,CommerceEvidenceContext $expected,string $mode):void {
	$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if(-1===$pid){throw new RuntimeException('fork failed');}
	if(0===$pid){
		fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$db=new wpdb($prefix);sql($db,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$t=new TableNames($db);$tx=new TransactionManager($db,$t);
		try{
			fwrite($pair[1],$db->dbh->thread_id."\n");fflush($pair[1]);
			$ok='evidence_first'===$mode?$tx->begin_reset_maintenance($expected->operation()):$tx->begin_historical_evidence($expected);
			if($ok){
				check(null===$tx->commerce_context()&&!TransactionManager::admitted_on($db)&&false===(new CartRepository($db,$t))->touch(1,'2026-01-02','2026-01-02','2026-01-02'),'waiter never receives normal authority');
				if('evidence_first'===$mode){check(null===$tx->evidence_context()&&(new CommerceResetRepository($db,$t))->find_for_maintenance($tx->reset_context())->equals($expected->operation()),'maintenance waiter stays maintenance-only');}
				else{check(null===$tx->reset_context()&&'bound'===(new ProviderRegistry($db,$t))->bind_historical($tx->evidence_context(),'stripe_checkout','session','cs_race_'.$mode,'2026-01-02 00:00:00','test'),'evidence waiter durably binds without maintenance authority');}
				check($tx->commit(),'waiter commits');
			}
			fwrite($pair[1],json_encode([$ok,$tx->is_active()])."\n");fflush($pair[1]);$db->close();exit(0);
		}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");$db->close();exit(2);}
	}
	fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new wpdb($prefix);sql($db,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$t=new TableNames($db);$tx=new TransactionManager($db,$t);$observer=new wpdb($prefix);
	try{
		check('evidence_first'===$mode||'shared'===$mode?$tx->begin_historical_evidence($expected):$tx->begin_reset_maintenance($expected->operation()),$mode.': holder admitted');
		// Evidence serializes on the exclusive reset before reaching the claim.
		fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$waiting=false;$deadline=microtime(true)+5;
		$pattern='shared'===$mode?'SELECT * FROM '.$t->commerce_resets().'%':'SELECT * FROM '.$t->commerce_control().'%';
		do{$waiting=(int)$observer->get_var($observer->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=%d AND INFO LIKE %s',$id,$pattern))>0;if(!$waiting){usleep(20000);}}while(!$waiting&&microtime(true)<$deadline);
		$read=[$pair[0]];$write=[];$except=[];check($waiting&&0===stream_select($read,$write,$except,0,200000),$mode.': real independent waiter observed at expected lock');
		if('stale_revision'===$mode){sql($db,"UPDATE {$t->commerce_resets()} SET revision=1 WHERE id=1");}
		if('stale_operation'===$mode){sql($db,"UPDATE {$t->commerce_resets()} SET operation_identity=REPEAT('e',32) WHERE id=1");}
		if('stale_source'===$mode){sql($db,"UPDATE {$t->commerce_epochs()} SET identity=REPEAT('e',32) WHERE id=1");}
		if('stale_claim'===$mode){sql($db,"UPDATE {$t->provider_claims()} SET provider='other' WHERE id=1");}
		check($tx->commit(),$mode.': holder released');$result=json_decode(trim(fgets($pair[0])),true,512,JSON_THROW_ON_ERROR);
		check($result===[!str_starts_with($mode,'stale_'),false],$mode.': waiter revalidates boundary/claim after wait');
		pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&0===pcntl_wexitstatus($status),$mode.': worker success');
		check(!$tx->begin_commerce_shared()&&!$tx->begin_commerce_exclusive(),$mode.': normal remains fenced');
		if('stale_revision'===$mode){sql($db,"UPDATE {$t->commerce_resets()} SET revision=0 WHERE id=1");}
		if('stale_operation'===$mode){sql($db,"UPDATE {$t->commerce_resets()} SET operation_identity=REPEAT('c',32) WHERE id=1");}
		if('stale_source'===$mode){sql($db,"UPDATE {$t->commerce_epochs()} SET identity=REPEAT('b',32) WHERE id=1");}
		if('stale_claim'===$mode){sql($db,"UPDATE {$t->provider_claims()} SET provider='stripe_checkout' WHERE id=1");}
	}finally{$tx->rollback();$db->close();$observer->close();fclose($pair[0]);}
}
try{
	foreach(['maintenance_first','evidence_first','shared','stale_revision','stale_operation','stale_source','stale_claim'] as $mode){$probe=new wpdb($prefix);$expected=evidence_context($probe);$probe->close();evidence_race($prefix,$expected,$mode);}
	$db=new wpdb($prefix);check($before===evidence_preserved_snapshot($db),'all concurrency orderings preserve operational/stock/reset/claim state');
	check(2===(int)$db->get_var("SELECT COUNT(*) FROM {$prefix}c2c_provider_identities WHERE identity_value LIKE 'cs_race_%'"),'only successful evidence waiters append durable identities');$db->close();
}finally{maintenance_cleanup(new wpdb($prefix));}
echo "All historical evidence independent-connection concurrency checks passed.\n";
