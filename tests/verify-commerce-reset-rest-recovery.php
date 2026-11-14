<?php
/** Physical COMMIT/ROLLBACK loss through production REST/service paths; no automatic replay. */
declare(strict_types=1);
require __DIR__.'/support/reset-rest-database.php';
use RodolfoRizzo\Cpt2Collection\{TransactionManager,TableNames};
foreach(['start','resume'] as $action)foreach([false,true] as $after){
 [$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$c=reset_rest_controller($db);$revision='0';
 try {
  if($action==='resume'){$c->start(rest_request('start',$id,$epoch));$revision=$c->status(rest_request('status',$id))->get_data()['operation']['revision'];}
  $snapshot=baseline_snapshot($db);$db->commits=0;$db->fault_commit=$action==='start'?1:2;$db->commit_after=$after;$db->observe=true;$db->writes=[];
  $r=$c->$action(rest_request($action,$id,$epoch,$revision));$data=$r->get_data();
  check($r->get_status()===503&&$data['code']==='transaction_outcome_unknown'&&$data['verify_state']&&$data['operation']===null,$action.' uncertain commit '.($after?'after':'before').' never claims snapshot/success');
  check(!$db->transaction_connection_usable()&&$db->commits===$db->fault_commit,$action.' poisons original connection without replay');
  check(TransactionManager::reset_control_on($db)===null&&TransactionManager::reset_context_on($db)===null,$action.' uncertain owner has no usable authority');
  $db->close();$db=new ResetRestDb($prefix);$c=reset_rest_controller($db);$status=$c->status(rest_request('status',$id));
  if($action==='start'){
   check($status->get_status()===($after?202:404),'fresh exact status proves physical start outcome');
   $r=$c->start(rest_request('start',$id,$epoch));check($r->get_data()['code']===($after?'existing':'started'),'same immutable start explicitly retried only after fresh verification');
  }else{
   check($status->get_data()['operation']['revision']===($after?'1':'0'),'fresh status sees old or whole committed checkpoint');
   $r=$c->resume(rest_request('resume',$id,$epoch,$revision));check($r->get_status()===($after?409:202),'old revision cannot duplicate committed batch');
  }
  $t=new TableNames($db);check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->commerce_resets())===1,'no competing reset operation after uncertainty');
 }finally{$db->close();rest_cleanup($prefix);}
}
foreach(['status','start','resume'] as $action){
 [$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$c=reset_rest_controller($db);
 try{
  $c->start(rest_request('start',$id,$epoch));$snapshot=baseline_snapshot($db);$db->rollback_fault=true;
  if($action==='status'){$request=rest_request('status',$id);}
  elseif($action==='start'){$request=rest_request('start',$id,str_repeat('e',32));}
  else{$db->read_fault='WHERE operation_identity=';$request=rest_request('resume',$id,$epoch);}
  $r=$c->$action($request);check($r->get_status()===503&&$r->get_data()['verify_state']&&$r->get_data()['operation']===null,$action.' unknown ROLLBACK requires fresh verification');
  check(!$db->transaction_connection_usable(),$action.' unknown rollback poisons connection');
  $db->close();$db=new ResetRestDb($prefix);$c=reset_rest_controller($db);
  check(baseline_snapshot($db)===$snapshot&&$c->status(rest_request('status',$id))->get_status()===202,'fresh connection confirms unchanged durable state');
 }finally{$db->close();rest_cleanup($prefix);}
}
// Failed semantic batch with an unacknowledged ROLLBACK must not advance/retry.
[$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$c=reset_rest_controller($db);
try {
 $c->start(rest_request('start',$id,$epoch));$c->resume(rest_request('resume',$id,$epoch));
 $snapshot=baseline_snapshot($db);$db->read_fault='FORCE INDEX(PRIMARY) WHERE id>'; $db->rollback_fault=true;
 $r=$c->resume(rest_request('resume',$id,$epoch,'1'));
 check($r->get_status()===503&&$r->get_data()['verify_state']&&$r->get_data()['operation']===null,'semantic batch unknown ROLLBACK returns no checkpoint');
 check(!$db->transaction_connection_usable(),'semantic batch rollback loss poisons owner');
 $db->close();$db=new ResetRestDb($prefix);$c=reset_rest_controller($db);
 check(baseline_snapshot($db)===$snapshot&&$c->status(rest_request('status',$id))->get_data()['operation']['revision']==='1','fresh inspection proves failed semantic batch unchanged');
}finally{$db->close();rest_cleanup($prefix);}
// Uncertain inspection COMMIT in resume stops before semantic batch admission.
[$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$c=reset_rest_controller($db);
try {
 $c->start(rest_request('start',$id,$epoch));$db->commits=0;$db->fault_commit=1;$db->commit_after=true;$db->observe=true;$db->writes=[];
 check($c->resume(rest_request('resume',$id,$epoch))->get_status()===503&&$db->writes===[],'uncertain locked resume inspection prevents subsequent batch');
}finally{$db->close();rest_cleanup($prefix);}
// Known service read failure is infrastructure error with confirmed rollback.
[$db,$prefix,$epoch]=rest_fixture();$id=bin2hex(random_bytes(16));$c=reset_rest_controller($db);
try {
 $db->read_fault='WHERE operation_identity=';$r=$c->start(rest_request('start',$id,$epoch));check($r->get_status()===500&&!$r->get_data()['verify_state']&&$db->transaction_connection_usable(),'known DB read error maps 500 after confirmed rollback');
 $db->read_fault='';$t=new TableNames($db);sql($db,'UPDATE '.$t->commerce_control()." SET baseline_contract='malformed'");
 check($c->start(rest_request('start',$id,$epoch))->get_status()===409,'malformed admission baseline cannot authorize start');
}finally{$db->close();rest_cleanup($prefix);}
echo "All reset REST transaction-recovery checks passed.\n";
