<?php
/** Real MariaDB serialization checks for atomic order deletion. */
declare(strict_types=1);
if(!extension_loaded('mysqli')||!function_exists('pcntl_fork')||false===getenv('C2C_TEST_DB_NAME')){echo "SKIP: mysqli, pcntl and C2C_TEST_DB_NAME are required.\n";exit(0);}
function __(string $message,string $domain=''):string{return $message;}
require __DIR__.'/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{CommercePhaseThreeCheckpointOneMigration,OrderDeletionRegistry,OrderDeletionRepository,OrderDeletionResult,OrderDeletionService,TableNames,TransactionManager,UtcClock};
function del_service(wpdb $db):OrderDeletionService{$t=new TableNames($db);return new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock());}
function race_order(wpdb &$db,string $prefix,string $dir,int $order,string $label,Closure $mutation):array{
	$db->close();$pids=[];$jobs=[
		'mutation'=>function()use($prefix,$dir,$order,$mutation){$c=new wpdb($prefix);$t=new TableNames($c);$tx=new TransactionManager($c,$t);if(!$tx->begin_commerce_shared()){throw new RuntimeException('shared admission');}$mutation($c,$t,$order);file_put_contents("$dir/held",'1');usleep(250000);$ok=$tx->commit();$c->close();return ['ok'=>$ok];},
		'delete'=>function()use($prefix,$dir,$order){$end=microtime(true)+5;while(!is_file("$dir/held")&&microtime(true)<$end){usleep(1000);}$c=new wpdb($prefix);$start=microtime(true);$result=del_service($c)->delete($order);$wait=microtime(true)-$start;$c->close();return ['code'=>$result->code(),'wait'=>$wait];}
	];
	foreach($jobs as $name=>$job){$pid=pcntl_fork();if(0===$pid){try{file_put_contents("$dir/$name.json",json_encode(['ok'=>true,'value'=>$job()]));exit(0);}catch(Throwable $e){file_put_contents("$dir/$name.json",json_encode(['ok'=>false,'error'=>$e->getMessage()]));exit(1);}}$pids[]=$pid;}
	foreach($pids as $pid){pcntl_waitpid($pid,$status);}$out=[];foreach(array_keys($jobs) as $name){$row=json_decode(file_get_contents("$dir/$name.json"),true);check($row['ok'],"$label $name worker completed");$out[$name]=$row['value'];unlink("$dir/$name.json");}unlink("$dir/held");$db=new wpdb($prefix);return $out;
}
function concurrency_fixture(wpdb $db,TableNames $t,int $id):void {
 $at='2026-09-28 12:00:00';$item=1000+$id;$session=2000+$id;$ref=str_pad((string)$id,32,'a',STR_PAD_LEFT);$key=hash('sha256','attempt '.$id);$token=hash('sha256','cart '.$id);
 // Explicit complete modern SQL fixture; concurrency effects still use production deletion.
 sql($db,"INSERT INTO {$t->carts()}(epoch_id,id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES(1,$id,'$token','$at','$at','$at','2030-01-01')");
 sql($db,"INSERT INTO {$t->cart_items()}(id,cart_id,post_id,quantity,created_at,updated_at) VALUES($item,$id,77,1,'$at','$at')");
 sql($db,"INSERT INTO {$t->orders()}(epoch_id,id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,$id,$id,0,0,'$key','pending','EUR',100,100,'$at','$at')");
 sql($db,"INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at,source_cart_item_id,source_cart_item_version) VALUES($item,$id,77,'Fixture',100,1,100,100,'EUR','$at',$item,0)");
 sql($db,"INSERT INTO {$t->reservations()}(epoch_id,id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES(1,$item,$id,$item,77,1,'active','$at','$at','2030-01-01')");
 sql($db,"INSERT INTO {$t->payment_sessions()}(epoch_id,id,reference,order_id,provider,creation_key_hash,status,active_slot,currency,amount_minor,created_at,updated_at) VALUES(1,$session,'$ref',$id,'stripe_checkout','$key','creating',1,'EUR',100,'$at','$at')");
 sql($db,"INSERT INTO {$t->provider_claims()}(id,installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,environment,evidence_state,disposition,created_at) VALUES($session,REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout','$ref',$id,$session,'$key','c2c_ps_$ref',2,'test','native','open','$at')");native_provider_claim_fixture($db,$session);
}

$prefix='c2c_delrace_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);$created=phase_two_schema($db);$dir=sys_get_temp_dir().'/'.$prefix;mkdir($dir,0700);
try{
	check(CommercePhaseThreeCheckpointOneMigration::run($db),'checkpoint-one schema available for concurrency');$created[]=$t->order_deletions();attest_modern_runtime_fixture($db);provider_response_schema($db);$created[]=$t->commerce_resets();
	$cases=[
		'checkout/reservation acquisition'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->reservations()} SET updated_at=updated_at WHERE order_id=$o"),
		'payment claim creation'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->provider_claims()} SET request_version=request_version WHERE order_id=$o"),
		'provider evidence recording'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->provider_claims()} SET evidence_state=evidence_state WHERE order_id=$o"),
		'provider activation'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->payment_sessions()} SET updated_at=updated_at WHERE order_id=$o"),
		'webhook receipt/association'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->provider_claims()} SET environment=environment WHERE order_id=$o"),
		'sale conversion'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->orders()} SET status=status WHERE id=$o"),
		'payment expiration'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->payment_sessions()} SET status=status WHERE order_id=$o"),
		'conversion commit/cart reconciliation gap'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->orders()} SET cart_reconciliation_status=cart_reconciliation_status WHERE id=$o"),
		'duplicate webhook delivery'=>fn($c,$t,$o)=>$c->query("UPDATE {$t->provider_claims()} SET disposition=disposition WHERE order_id=$o"),
	];
	$id=10;foreach($cases as $label=>$mutation){concurrency_fixture($db,$t,$id);$out=race_order($db,$prefix,$dir,$id,$label,$mutation);check($out['mutation']['ok']&&OrderDeletionResult::DELETED===$out['delete']['code']&&$out['delete']['wait']>.15,"delete serializes after $label");++$id;}

	// A provider call is deliberately outside admission: deletion completes while the fake network is still blocked.
	concurrency_fixture($db,$t,50);$db->close();$pid=pcntl_fork();if(0===$pid){$c=new wpdb($prefix);$tx=new TransactionManager($c,new TableNames($c));$tx->begin_commerce_shared();$tx->commit();file_put_contents("$dir/network",'blocked');usleep(600000);file_put_contents("$dir/network_done",'done');$c->close();exit(0);}$end=microtime(true)+5;while(!is_file("$dir/network")&&microtime(true)<$end){usleep(1000);}$db=new wpdb($prefix);$start=microtime(true);$network_delete=del_service($db)->delete(50);$elapsed=microtime(true)-$start;check(OrderDeletionResult::DELETED===$network_delete->code()&&$elapsed<.45&&!is_file("$dir/network_done"),'deletion neither waits on nor depends on a blocked provider call');pcntl_waitpid($pid,$status);unlink("$dir/network");unlink("$dir/network_done");

	concurrency_fixture($db,$t,60);$db->close();$pids=[];foreach(['a','b'] as $name){$pid=pcntl_fork();if(0===$pid){$c=new wpdb($prefix);$result=del_service($c)->delete(60);file_put_contents("$dir/$name",$result->code());$c->close();exit(0);}$pids[]=$pid;}foreach($pids as $pid){pcntl_waitpid($pid,$status);}$codes=[file_get_contents("$dir/a"),file_get_contents("$dir/b")];sort($codes);check($codes===[OrderDeletionResult::ALREADY_DELETED,OrderDeletionResult::DELETED],'two concurrent deletes converge to deleted plus already_deleted');unlink("$dir/a");unlink("$dir/b");$db=new wpdb($prefix);
}finally{try{$db->close();}catch(Throwable $ignored){}$db=new wpdb($prefix);foreach(array_unique($created) as $table){$db->query('DROP TABLE IF EXISTS '.$table);}$db->close();foreach(glob("$dir/*")?:[] as $file){unlink($file);}rmdir($dir);}
echo "All atomic order-deletion concurrency checks passed.\n";
