<?php
/** Independent processes/connections, observed SQL wait barriers, fixture-only reset start. */
declare( strict_types=1 );
require __DIR__ . '/support/reset-maintenance.php';
use RodolfoRizzo\Cpt2Collection\{TransactionManager,TableNames,CommerceResetRepository,CartRepository};
if ( ! function_exists( 'pcntl_fork' ) ) { throw new RuntimeException( 'pcntl required' ); }
$prefix='c2c_p42race_'.bin2hex(random_bytes(5)).'_'; $db=new wpdb($prefix);
modern_maintenance_schema($db); $expected=maintenance_fixture($db); sql($db,"UPDATE {$prefix}c2c_commerce_control SET state='resetting'"); $original=maintenance_snapshot($db); $db->close();
function maintenance_race(string $prefix, \RodolfoRizzo\Cpt2Collection\CommerceResetRecord $expected, string $mode):void {
	$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0); $pid=pcntl_fork();
	if(-1===$pid){throw new RuntimeException('fork failed');}
	if(0===$pid){
		fclose($pair[0]); stream_set_timeout($pair[1],15); fgets($pair[1]);
		$db=new wpdb($prefix); $t=new TableNames($db); $tx=new TransactionManager($db,$t);
		try {
			fwrite($pair[1],$db->dbh->thread_id."\n"); fflush($pair[1]);
			$ok=match($mode){'held_normal'=>$tx->begin_commerce_exclusive(),'fence'=>$tx->begin_commerce_shared(),default=>$tx->begin_reset_maintenance($expected)};
			if('held_normal'===$mode && $ok){
				sql($db,"UPDATE {$t->commerce_control()} SET state='resetting'"); check($tx->commit(),'fixture fence commits after normal holder releases');
				$ok=$tx->begin_reset_maintenance($expected);
			}
			$separate=true;
			if($ok && 'fence'!==$mode){
				$separate=null===$tx->commerce_context() && !TransactionManager::admitted_on($db) &&
					false===(new CartRepository($db,$t))->touch(1,'2026-01-01','2026-01-01','2026-01-01') &&
					(new CommerceResetRepository($db,$t))->find_for_maintenance($tx->reset_context())->equals($expected);
			}
			if($ok){$tx->commit();}
			fwrite($pair[1],json_encode([$ok,$separate,$tx->is_active()])."\n"); fflush($pair[1]); $db->close(); exit(0);
		}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");$db->close();exit(2);}
	}
	fclose($pair[1]); stream_set_timeout($pair[0],15); $db=new wpdb($prefix); $t=new TableNames($db); $tx=new TransactionManager($db,$t); $observer=new wpdb($prefix);
	try {
		if(in_array($mode,['held_normal','fence'],true)){
			sql($db,"UPDATE {$t->commerce_control()} SET state='ready'");
			check('held_normal'===$mode?$tx->begin_commerce_shared():$tx->begin_commerce_exclusive(),$mode.': parent holds normal admission');
			if('fence'===$mode){sql($db,"UPDATE {$t->commerce_control()} SET state='resetting'");}
		}else{check($tx->begin_reset_maintenance($expected),$mode.': parent holds maintenance');}
		fwrite($pair[0],"go\n"); fflush($pair[0]); $id=(int)trim(fgets($pair[0]));
		$deadline=microtime(true)+5; $waiting=false;
		do {$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND (INFO LIKE 'SELECT id, installation_id%' OR INFO LIKE 'SELECT * FROM {$t->commerce_control()}%')")>0; if(!$waiting){usleep(20000);}}while(!$waiting&&microtime(true)<$deadline);
		$read=[$pair[0]];$write=[];$except=[];
		check($waiting&&0===stream_select($read,$write,$except,0,200000),$mode.': independent waiter blocks at control before downstream locks');
		if('stale'===$mode){sql($db,"UPDATE {$t->commerce_resets()} SET revision=revision+1 WHERE id=1");}
		check($tx->commit(),$mode.': holder commits');
		$result=json_decode(trim(fgets($pair[0])),true,512,JSON_THROW_ON_ERROR);
		check($result===[!in_array($mode,['fence','stale'],true),true,false],$mode.': waiter revalidates committed state, exact operation and separate authority');
		pcntl_waitpid($pid,$status); check(pcntl_wifexited($status)&&0===pcntl_wexitstatus($status),$mode.': worker exits successfully');
		check(!$tx->begin_commerce_shared()&&!$tx->begin_commerce_exclusive(),$mode.': fresh normal admissions reject committed reset fence');
		if('stale'===$mode){sql($db,"UPDATE {$t->commerce_resets()} SET revision=0 WHERE id=1");}
	}finally{$tx->rollback();$db->close();$observer->close();fclose($pair[0]);}
}
try {
	foreach(['held_normal','fence','maintenance','stale'] as $mode){maintenance_race($prefix,$expected,$mode);}
	$db=new wpdb($prefix);$before=maintenance_snapshot($db);check($original===$before,'all race attempts preserve populated operational, stock and evidence rows');$tx=new TransactionManager($db,new TableNames($db));
	check($tx->begin_reset_maintenance($expected)&&$tx->commit(),'exact maintenance admission remains available after races');
	check($before===maintenance_snapshot($db),'maintenance admission after races changes no durable rows');$db->close();
}finally{maintenance_cleanup(new wpdb($prefix));}
echo "All Phase 4.2A independent-connection concurrency checks passed.\n";
