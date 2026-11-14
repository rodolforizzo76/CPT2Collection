<?php
/** Real independent-connection lock contention, without inherited live connections. */
declare( strict_types=1 );
require __DIR__ . '/support/baseline-purge.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
if (!function_exists('pcntl_fork')) { throw new RuntimeException('pcntl required'); }
$prefix='c2c_gate_race_'.bin2hex(random_bytes(5)).'_'; $db=new wpdb($prefix); baseline_schema($db);check(\RodolfoRizzo\Cpt2Collection\CommerceBaselineMigration::run($db)&&\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db),'independent admission fixture has pristine modern v16 baseline'); sql($db,'CREATE TABLE '.$prefix.'deadlock_probe(id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB'); sql($db,'INSERT INTO '.$prefix.'deadlock_probe VALUES(1,0),(2,0)'); $db->close(); unset($db);
function gate_race(string $prefix,bool $exclusive,bool $commit,bool $deny=false):void {
	$s=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0); $pid=pcntl_fork();
	if(0===$pid) {
		fclose($s[0]); fgets($s[1]); $d=new wpdb($prefix); $tx=new TransactionManager($d,new TableNames($d));
		fwrite($s[1],$d->dbh->thread_id."\n"); fflush($s[1]);
		$ok=$exclusive?$tx->begin_commerce_shared():$tx->begin_commerce_exclusive();
		if($ok)$tx->commit(); fwrite($s[1],($ok?'admitted':'denied')."\n"); fflush($s[1]); $d->close(); exit(0);
	}
	fclose($s[1]); stream_set_timeout($s[0],10); $d=new wpdb($prefix); $tx=new TransactionManager($d,new TableNames($d));
	try {
	check($exclusive?$tx->begin_commerce_exclusive():$tx->begin_commerce_shared(),'parent admitted');
	fwrite($s[0],"go\n"); fflush($s[0]); $id=(int)trim(fgets($s[0]));
	$observer=new wpdb($prefix); $deadline=microtime(true)+5; $waiting=false;
	do { $waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE 'SELECT%{$prefix}c2c_commerce_control%'")>0; if(!$waiting)usleep(20000); } while(!$waiting && microtime(true)<$deadline);
	$read=[$s[0]]; $write=[]; $except=[]; $not_finished=0===stream_select($read,$write,$except,0,200000);
	check($waiting && $not_finished,$exclusive?'shared waits for exclusive':'exclusive waits for shared');
	if($deny)sql($d,"UPDATE {$prefix}c2c_commerce_control SET state='resetting' WHERE id=1");
	check($commit?$tx->commit():$tx->rollback(),'parent releases gate');
	check(trim(fgets($s[0]))===($deny?'denied':'admitted'),$deny?'waiter revalidates newly committed state':'waiter admits after release');
	pcntl_waitpid($pid,$status); check(pcntl_wexitstatus($status)===0,'independent worker succeeds');
	if($deny)sql($d,"UPDATE {$prefix}c2c_commerce_control SET state='ready' WHERE id=1");
	$observer->close();
	} finally { $tx->rollback(); $d->close(); fclose($s[0]); }
}
function downstream_deadlock(string $prefix):void {
	$children=[];
	foreach([1,2] as $id){
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
		if($pid===0){
			fclose($pair[0]);$db=new wpdb($prefix);$tx=new TransactionManager($db,new TableNames($db));
			if(!$tx->begin_commerce_shared())exit(2);
			sql($db,"UPDATE {$prefix}deadlock_probe SET value=value+1 WHERE id=$id");
			fwrite($pair[1],"ready\n");fflush($pair[1]);fgets($pair[1]);
			$other=3-$id;$ok=false!==$db->query("UPDATE {$prefix}deadlock_probe SET value=value+1 WHERE id=$other");$errno=$db->last_errno;
			$terminated=$ok?$tx->commit():$tx->rollback();
			fwrite($pair[1],json_encode([$ok,$errno,$terminated,$tx->is_active()])."\n");fflush($pair[1]);$db->close();exit(0);
		}
		fclose($pair[1]);stream_set_timeout($pair[0],10);$children[]=[$pid,$pair[0]];
	}
	foreach($children as [$pid,$socket])check(trim(fgets($socket))==='ready','deadlock participant holds shared admission and first row');
	foreach($children as [$pid,$socket]){fwrite($socket,"go\n");fflush($socket);}
	$results=[];
	foreach($children as [$pid,$socket]){$results[]=json_decode(trim(fgets($socket)),true,512,JSON_THROW_ON_ERROR);pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'deadlock worker terminates');fclose($socket);}
	$victims=array_filter($results,fn($r)=>!$r[0]&&$r[1]===1213&&$r[2]&&!$r[3]);$winners=array_filter($results,fn($r)=>$r[0]&&$r[2]&&!$r[3]);
	check(count($victims)===1&&count($winners)===1,'deadlock victim rolls back, survivor commits, neither retains admission');
	$db=new wpdb($prefix);$tx=new TransactionManager($db,new TableNames($db));check($tx->begin_commerce_exclusive()&&$tx->commit(),'exclusive admission available after deadlock resolution');
	check((int)$db->get_var('SELECT SUM(value) FROM '.$prefix.'deadlock_probe')===2,'deadlock leaves only one complete transaction');$db->close();
}
try { gate_race($prefix,false,true); gate_race($prefix,true,false); gate_race($prefix,true,true,true); downstream_deadlock($prefix); }
finally { $db=new wpdb($prefix);sql($db,'DROP TABLE IF EXISTS '.$prefix.'deadlock_probe');baseline_cleanup($db); }
