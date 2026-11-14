<?php
/** Real reset domain/HTTP integration. WP auth and request dispatch are standalone simulations. */
declare(strict_types=1);
require __DIR__.'/support/reset-rest-database.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRepository,CommerceResetRecord,TransactionManager,TableNames};
[$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$t=new TableNames($db);$c=reset_rest_controller($db);
try {
 $before=validation_preserved($db);$r=$c->start(rest_request('start',$id,$epoch));$p=$r->get_data()['operation'];
 check($r->get_status()===202&&$r->get_data()['code']==='started'&&!$p['completed']&&$p['revision']==='0'&&$p['checkpoint']===null,'accepted start is initial durable running operation, never completion');
 check($before!==validation_preserved($db),'start fences control');
 $snapshot=baseline_snapshot($db);$r=$c->start(rest_request('start',$id,$epoch));check($r->get_status()===200&&$r->get_data()['code']==='existing'&&baseline_snapshot($db)===$snapshot,'identical start preserves entire durable snapshot');
 check($c->start(rest_request('start',bin2hex(random_bytes(16)),$epoch))->get_status()===409,'competing operation cannot start');
 check($c->start(rest_request('start',$id,str_repeat('e',32)))->get_status()===409,'same identity conflicting source rejected');
 $GLOBALS['reset_user']=8;check($c->start(rest_request('start',$id,$epoch))->get_status()===409,'immutable requester mismatch rejected');$GLOBALS['reset_user']=7;
 $unknown=bin2hex(random_bytes(16));check($c->status(rest_request('status',$unknown))->get_status()===404,'unknown exact status not found');
 check($c->resume(rest_request('resume',$unknown,$epoch))->get_status()===404,'unknown exact resume not found');
 check($c->resume(rest_request('resume',$id,str_repeat('e',32)))->get_status()===409,'wrong source never advances target');
 $db->observe=true;$db->writes=[];$snapshot=baseline_snapshot($db);
 $db->inspect_probe=static function($db):void {
  check(TransactionManager::reset_inspection_on($db)!==null,'status has inspection owner');
  check(TransactionManager::reset_control_on($db)===null&&!TransactionManager::admitted_on($db)&&TransactionManager::reset_context_on($db)===null&&TransactionManager::evidence_context_on($db)===null,'status has no control, normal, maintenance or evidence authority');
  $repo=new CommerceResetRepository($db,new TableNames($db));check(false===$repo->start(str_repeat('f',32),str_repeat('b',32),7,gmdate('Y-m-d H:i:s')),'inspection owner cannot start reset');
 };
 $r=$c->status(rest_request('status',$id));$db->inspect_probe=null;
 check($r->get_status()===202&&$r->get_data()['operation']['revision']==='0','status returns initial durable tuple');
 check($snapshot===baseline_snapshot($db)&&$db->writes===[],'status makes no attempted DML and changes no state');
 check(TransactionManager::reset_inspection_on($db)===null,'inspection authority released after confirmed rollback');
 $encoded=json_encode($r->get_data());foreach(['evt_private_rest','provider','installation_id','requested_by','target_epoch_id','error_code','snapshot_json'] as $secret)check(!str_contains($encoded,$secret),'status omits private field '.$secret);
 $stock=deletion_stock($db);$phases=[];$calls=0;
 do {
  $p=$c->status(rest_request('status',$id))->get_data()['operation'];$phases[$p['phase']]=true;$entry=baseline_snapshot($db);$db->writes=[];
  $q=rest_request('resume',$id,$epoch,$p['revision']);$r=$c->resume($q);$out=$r->get_data();
  check(in_array($r->get_status(),[200,202],true)&&!$out['verify_state'],'bounded HTTP resume succeeds '.$calls);
  check((int)$out['operation']['revision']===(int)$p['revision']+1,'one resume commits exactly one revision '.$calls);
  check(deletion_stock($db)===$stock,'physical stock unchanged '.$calls);
  $after=baseline_snapshot($db);$duplicate=$c->resume($q);
  check($duplicate->get_status()===($out['operation']['completed']?200:409)&&$after===baseline_snapshot($db),'duplicate revision performs no second mutation '.$calls);
  check(count(array_filter($db->writes,fn($sql)=>str_starts_with($sql,'DELETE FROM ')))<=100,'resume point deletes bounded at 100 '.$calls);
  if(++$calls>500)throw new RuntimeException('HTTP convergence failed');
 }while(!$out['operation']['completed']);
 check(isset($phases['validating'],$phases['retiring_claims'],$phases['terminalizing_events'],$phases['deleting']),'HTTP path traverses all semantic services');
 $snapshot=baseline_snapshot($db);$r=$c->status(rest_request('status',$id));check($r->get_status()===200&&$r->get_data()['operation']['completed']&&!$r->get_data()['operation']['can_resume'],'canonical completion read');
 check($c->resume(rest_request('resume',$id,$epoch,'0'))->get_status()===200&&$snapshot===baseline_snapshot($db),'completed resume read-only with old revision');
 check($c->start(rest_request('start',$id,$epoch))->get_status()===200&&$snapshot===baseline_snapshot($db),'completed same-request start is exact historical replay');
 foreach(['carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events'] as $method)check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->$method())===0,'full operational erasure '.$method);
 check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->commerce_epochs())===2,'exactly one epoch allocation');
 check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims()." WHERE disposition='retired'")===1,'native claim retired once');
 check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->order_deletions())===0,'reset does not fabricate per-order deletion evidence');
 // An older completed identity must never select or advance a subsequent active reset.
 $newEpoch=(string)$db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE retired_at IS NULL');$later=bin2hex(random_bytes(16));
 check($c->start(rest_request('start',$later,$newEpoch))->get_status()===202,'subsequent reset starts against fresh target source');
 $laterSnapshot=baseline_snapshot($db);
 check($c->resume(rest_request('resume',$id,$epoch,'0'))->get_status()===200&&baseline_snapshot($db)===$laterSnapshot,'old completed resume never advances later active operation');
 check($c->start(rest_request('start',$id,$epoch))->get_status()===200&&baseline_snapshot($db)===$laterSnapshot,'old exact start replay preserves later active operation');
 check($c->resume(rest_request('resume',bin2hex(random_bytes(16)),$newEpoch))->get_status()===404&&baseline_snapshot($db)===$laterSnapshot,'unknown identity never selects newest active operation');
 check($c->start(rest_request('start',bin2hex(random_bytes(16)),$epoch))->get_status()===409,'stale source cannot start another reset');
 // Canonical completed history remains independently validated.
 sql($db,'UPDATE '.$t->commerce_resets()." SET checkpoint=NULL WHERE operation_identity='$id'");
 check($c->status(rest_request('status',$id))->get_status()===409&&$c->resume(rest_request('resume',$id,$epoch))->get_status()===409,'contradictory terminal certificate cannot be reported complete or resumed');
} finally {$db->close();rest_cleanup($prefix);}
// Blocked status must remain non-success even for idempotent start.
[$db,$prefix,$epoch]=rest_fixture();$c=reset_rest_controller($db);$id=bin2hex(random_bytes(16));
try {
 $c->start(rest_request('start',$id,$epoch));$svc=validation_service($db);$op=$svc->resume($id)->operation();$blocked=$svc->block($op,'private_investigation_reason');check($blocked->code()==='blocked','durable service block');
 $snapshot=baseline_snapshot($db);$db->observe=true;$db->writes=[];
 foreach(['status','start','resume'] as $action){$r=$c->$action(rest_request($action,$id,$epoch));check($r->get_status()===409&&$r->get_data()['code']==='blocked'&&!$r->get_data()['operation']['can_resume']&&!str_contains(json_encode($r->get_data()),'private_investigation_reason'),'blocked '.$action.' requires investigation');}
 check(baseline_snapshot($db)===$snapshot&&$db->writes===[],'blocked operations have no automatic unblock or mutation');
}finally{$db->close();rest_cleanup($prefix);}
// Real validation corruption becomes a durable block through the REST path.
[$db,$prefix,$epoch]=rest_fixture();$c=reset_rest_controller($db);$id=bin2hex(random_bytes(16));
try {
 $t=new TableNames($db);sql($db,'UPDATE '.$t->order_items().' SET line_total_minor=99');$c->start(rest_request('start',$id,$epoch));
 for($i=0;$i<100;++$i){$p=$c->status(rest_request('status',$id))->get_data()['operation'];$r=$c->resume(rest_request('resume',$id,$epoch,$p['revision']));if($r->get_status()===409)break;}
 check($r->get_data()['code']==='blocked'&&$r->get_data()['operation']['blocked'],'service validation failure maps durable block to HTTP conflict');
}finally{$db->close();rest_cleanup($prefix);}
echo "All reset REST MariaDB integration checks passed.\n";
