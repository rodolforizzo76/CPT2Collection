<?php
/** Independent connections with observed control-lock waits; production reset mutations. */
declare( strict_types=1 );
require __DIR__ . '/support/historical-evidence.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetService,CommerceResetRepository,CommerceResetRecord,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry,TransactionManager,TableNames,UtcClock};
if(!function_exists('pcntl_fork')){throw new RuntimeException('pcntl required');}
function reset_service(wpdb $db):CommerceResetService{$t=new TableNames($db);return new CommerceResetService(new CommerceResetRepository($db,$t),new TransactionManager($db,$t),new UtcClock());}
$prefix='c2c_p43race_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);evidence_fixture($db);$t=new TableNames($db);
$original=unserialize(maintenance_snapshot($db));unset($original['commerce_control'],$original['commerce_resets'],$original['provider_identities']);$db->close();
function lifecycle_race(string $prefix,string $mode):void{
	// Prepare fixture and close every connection BEFORE forking.
	$db=new wpdb($prefix);$t=new TableNames($db);sql($db,"DELETE FROM {$t->commerce_resets()}");sql($db,"UPDATE {$t->commerce_control()} SET state='ready'");
	$source=(string)$db->get_var("SELECT identity FROM {$t->commerce_epochs()} WHERE id=1");$identity=str_repeat('7',32);$record=null;
	if(in_array($mode,['progress','block','evidence_first','progress_first'],true)){$record=reset_service($db)->start($identity,$source)->operation();}
	$claim=ProviderClaimRecord::from_row($db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id=1"));$db->close();
	$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if(-1===$pid){throw new RuntimeException('fork failed');}
	if(0===$pid){
		fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);$db=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$svc=reset_service($db);
		try{
			fwrite($pair[1],$db->dbh->thread_id."\n");fflush($pair[1]);
			if('normal_waiter'===$mode){$code=$tx->begin_commerce_shared()?'unexpected':'fenced';}
			elseif('progress_first'===$mode){$code=$tx->begin_historical_evidence(new CommerceEvidenceContext($record,$claim))?'unexpected':'stale_evidence';}
			elseif(in_array($mode,['progress','block','evidence_first'],true)){$code=$svc->checkpoint($record,'batch',2)->code();}
			elseif('rollback_recovery'===$mode){$code=$svc->resume($identity)->code();}
			else{$code=$svc->start('different_start'===$mode?str_repeat('8',32):$identity,$source)->code();}
			if($tx->is_active()){$tx->rollback();}
			fwrite($pair[1],$code."\n");fflush($pair[1]);$db->close();exit(0);
		}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}
	}
	fclose($pair[1]);stream_set_timeout($pair[0],15);$db=new wpdb($prefix);$observer=new wpdb($prefix);$t=new TableNames($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
	try{
		if('normal_holder'===$mode){check($tx->begin_commerce_shared(),'prior normal holder admitted');}
		elseif('evidence_first'===$mode){
			$evidence=new CommerceEvidenceContext($record,$claim);check($tx->begin_historical_evidence($evidence),'evidence holds shared boundary');
			check('bound'===(new ProviderRegistry($db,$t))->bind_historical($evidence,'stripe_checkout','session','cs_race_late',gmdate('Y-m-d H:i:s'),'test'),'independent historical evidence observed before progression');
		}elseif(in_array($mode,['progress','block','progress_first'],true)){
			check($tx->begin_reset_maintenance($record),'maintenance holder admitted');
			check(false!==$repo->progress($record,$record->phase(),null,'block'===$mode?0:1,'block'===$mode?'integrity_conflict':null,gmdate('Y-m-d H:i:s')),'holder writes revision before waiter');
		}else{
			check($tx->begin_reset_control(),'start holder owns exclusive control');
			$record=$repo->start($identity,$source,null,gmdate('Y-m-d H:i:s'));check($record instanceof CommerceResetRecord,'holder inserts intent and fence atomically');
		}
		fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+5;$waiting=false;
		// Baseline admission now locks the full control attestation before the
		// older foundation projection; both are the same control-first boundary.
		do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND (INFO LIKE 'SELECT id, installation_id%' OR INFO LIKE 'SELECT * FROM {$t->commerce_control()}%')")>0;if(!$waiting){usleep(20000);}}while(!$waiting&&microtime(true)<$deadline);
		$read=[$pair[0]];$write=[];$except=[];check($waiting&&0===stream_select($read,$write,$except,0,200000),$mode.': independent waiter observed blocked at control');
		check('rollback_recovery'===$mode?$tx->rollback():$tx->commit(),$mode.': holder terminates with known outcome');
		$code=trim(fgets($pair[0]));$expected=match($mode){'same_start'=>'existing','different_start'=>'conflict','normal_waiter'=>'fenced','progress','block'=>'stale','evidence_first'=>'stale','progress_first'=>'stale_evidence','rollback_recovery'=>'not_found',default=>'started'};
		check($code===$expected,$mode.': waiter result '.$expected);pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&0===pcntl_wexitstatus($status),$mode.': child exits successfully');
		$svc=reset_service($db);$durable=$svc->resume($identity)->operation();
		if('rollback_recovery'===$mode){check(null===$durable,'locked recovery confirms rolled-back intent absent');}
		else{
			check('1'===(string)$db->get_var("SELECT COUNT(*) FROM {$t->commerce_resets()}"),$mode.': exactly one active operation/reservation');
			check(!$tx->begin_commerce_shared()&&!$tx->begin_commerce_exclusive(),$mode.': fresh normal commerce rejects');
			if(in_array($mode,['same_start','different_start','normal_waiter'],true)){check($record->equals($durable),$mode.': exact winning target and snapshot retained');}
			if(in_array($mode,['progress','block','evidence_first','progress_first'],true)){check($durable->revision()===('evidence_first'===$mode?0:1),$mode.': only one progression commits');}
			if('block'===$mode){check('blocked'===$durable->status()&&!$tx->begin_reset_maintenance($durable),'block remains durable and no maintenance continues');}
		}
	}finally{$tx->rollback();$db->close();$observer->close();fclose($pair[0]);}
}
try{
	foreach(['same_start','different_start','normal_holder','normal_waiter','progress','block','evidence_first','progress_first','rollback_recovery'] as $mode){lifecycle_race($prefix,$mode);}
	$db=new wpdb($prefix);$after=unserialize(maintenance_snapshot($db));unset($after['commerce_control'],$after['commerce_resets'],$after['provider_identities']);check(serialize($original)===serialize($after),'all races preserve operational/claim/deletion/stock/epoch rows');
	check('1'===(string)$db->get_var("SELECT COUNT(*) FROM {$prefix}c2c_provider_identities WHERE identity_value='cs_race_late'"),'dedicated evidence-only transaction retains exactly one late identity');$db->close();
}finally{maintenance_cleanup(new wpdb($prefix));}
echo "All Phase 4.3 independent-connection lifecycle races passed.\n";
