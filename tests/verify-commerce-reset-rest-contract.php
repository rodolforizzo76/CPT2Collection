<?php
/** REST validation and exact public mapping; no WordPress bootstrap is claimed. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require __DIR__.'/support/reset-rest.php';
foreach(['commerce-reset-record','commerce-reset-lifecycle','commerce-reset-result','commerce-reset-rest-controller'] as $f)require_once dirname(__DIR__).'/includes/class-'.$f.'.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRecord,CommerceResetResult,CommerceResetRestController as Controller};
function check(bool $yes,string $label):void{if(!$yes)throw new RuntimeException($label);echo "PASS: $label\n";}
$id=str_repeat('a',32);$epoch=str_repeat('b',32);$calls=[];$next=new CommerceResetResult('database_error');
$c=new Controller(function($action,$params)use(&$calls,&$next){$calls[]=[$action,$params];return $next;});$c->register();
check(count($GLOBALS['reset_routes'])===3,'three routes registered');
foreach($GLOBALS['reset_routes'] as $route=>[$ns,$args])check($ns===Controller::REST_NAMESPACE&&$args['permission_callback']===[$c,'authorize'],'every route independently authorizes '.$route);
foreach(['start','status','resume'] as $action){
 $q=rest_request($action,$id,$epoch);
 foreach(['invalid','expired','malformed','missing'] as $nonce){$n=count($calls);$r=rest_dispatch($c,$action,$q,$nonce);check($r instanceof WP_Error&&$r->data['status']===($nonce==='missing'?401:403)&&count($calls)===$n,$action.' simulated cookie nonce '.$nonce.' denied');}
 $GLOBALS['reset_user']=0;$r=rest_dispatch($c,$action,$q);check($r instanceof WP_Error&&$r->data['status']===401,$action.' unauthenticated denied');
 $GLOBALS['reset_user']=7;$GLOBALS['reset_capable']=false;
 foreach(['valid','alternate'] as $auth){$n=count($calls);$r=rest_dispatch($c,$action,$q,$auth);check($r instanceof WP_Error&&$r->data['status']===403&&count($calls)===$n,$action.' valid '.$auth.' authentication without capability denied');}
 $GLOBALS['reset_capable']=true;
 foreach(['valid','alternate'] as $auth)check(rest_dispatch($c,$action,$q,$auth) instanceof WP_REST_Response,$action.' authorized '.$auth.' authentication reaches controller');
}
// Legitimate core routing metadata must name exactly the route selected by WordPress.
foreach (['start','status','resume'] as $action) {
 $original=rest_request($action,$id,$epoch);
 $route='/'.Controller::REST_NAMESPACE.Controller::ROOT.($action==='start'?'':'/'.$id.($action==='resume'?'/resume':''));
 foreach ([$route,$route.'/'] as $routing) {
  $n=count($calls);$q=new WP_REST_Request($original->get_url_params(),$original->get_json_params(),['rest_route'=>$routing,'_wpnonce'=>'valid']);
  $c->$action($q);check(count($calls)===$n+1,'query route reaches same authorized service '.$action);
 }
 foreach ([null,[],true,'',strtoupper($route),$route.'?x=1',$route.'/other','https://example.test'.$route] as $routing) {
  $n=count($calls);$q=new WP_REST_Request($original->get_url_params(),$original->get_json_params(),['rest_route'=>$routing]);
  check($c->$action($q)->get_status()===400&&count($calls)===$n,'invalid or mismatched core routing metadata denied '.$action);
 }
 $q=new WP_REST_Request($original->get_url_params(),$original->get_json_params(),['rest_route'=>$route,'unexpected'=>'x']);$n=count($calls);
 check($c->$action($q)->get_status()===400&&count($calls)===$n,'routing metadata does not permit extra query parameters '.$action);
}
$bad=[];
foreach([null,true,[],0,'',str_repeat('A',32),str_repeat('a',33),' a'] as $v)$bad[]=new WP_REST_Request([],['operation_identity'=>$v,'expected_epoch_identity'=>$epoch,'confirmation'=>Controller::CONFIRMATION]);
foreach([true,[],0,'',str_repeat('X',32)] as $v)$bad[]=rest_request('start',$id,$epoch,'0',['expected_epoch_identity'=>$v]);
foreach([null,true,false,1,[],Controller::CONFIRMATION.' '] as $v)$bad[]=rest_request('start',$id,$epoch,'0',['confirmation'=>$v]);
$bad[]=new WP_REST_Request([],['operation_identity'=>$id,'expected_epoch_identity'=>$epoch]);
$bad[]=rest_request('start',$id,$epoch,'0',['requested_by'=>9]);
$bad[]=new WP_REST_Request([],null,[],str_repeat('x',2049));
$bad[]=new WP_REST_Request([],null,[],'{bad');
$bad[]=new WP_REST_Request([],[],[],null,'application/x-www-form-urlencoded');
$bad[]=new WP_REST_Request([],[],['operation_identity'=>$id]);
$bad[]=new WP_REST_Request([],[],[],null,'application/json',['idempotency-key'=>'conflicting']);
foreach($bad as $i=>$q){$n=count($calls);check($c->start($q)->get_status()===400&&$n===count($calls),'malformed start denied before service '.$i);}
foreach([0,-1,true,[],'-1','01','1.0',str_repeat('9',100)] as $v){$n=count($calls);check($c->resume(rest_request('resume',$id,$epoch,'0',['revision'=>$v]))->get_status()===400&&$n===count($calls),'noncanonical revision denied '.get_debug_type($v));}
check($c->resume(rest_request('resume',$id,$epoch,'0',['confirmation'=>Controller::CONFIRMATION]))->get_data()['code']==='confirmation_required','resume confirmation names exact operation');
foreach(['',str_repeat('x',32),str_repeat('a',33)] as $badId)check($c->status(rest_request('status',$badId))->get_status()===400,'invalid exact status identity');
check($c->status(new WP_REST_Request(['operation_identity'=>$id],['x'=>1]))->get_status()===400,'GET rejects body');
foreach([null,[],str_repeat('a',33)] as $badId){$n=count($calls);check($c->resume(new WP_REST_Request(['operation_identity'=>$badId],['expected_epoch_identity'=>$epoch,'revision'=>'0','confirmation'=>Controller::CONFIRMATION]))->get_status()===400&&$n===count($calls),'missing or invalid resume identity denied');}
foreach([null,[],str_repeat('n',129)] as $nonce){$q=new WP_REST_Request([],['operation_identity'=>$id,'expected_epoch_identity'=>$epoch,'confirmation'=>Controller::CONFIRMATION],['_wpnonce'=>$nonce]);check($c->start($q)->get_status()===400,'nonce query shape/size validated independently of authentication');}
check($c->resume(rest_request('resume',$id,$epoch,'0',['operation_identity'=>$id]))->get_status()===400,'resume body cannot override URL operation identity');
$row=['id'=>1,'operation_identity'=>$id,'installation_id'=>str_repeat('c',32),'source_epoch_id'=>1,'source_epoch_identity'=>$epoch,'target_epoch_identity'=>str_repeat('d',32),'target_epoch_id'=>null,'requested_by'=>7,'active_slot'=>1,'status'=>'running','phase'=>'validating','checkpoint'=>null,'error_code'=>null,'cursor'=>0,'revision'=>0,'evidence_generation'=>0,'validation_generation'=>null,'started_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00','completed_at'=>null];
$record=CommerceResetRecord::from_row((object)$row);
$mapping=['started'=>202,'existing'=>200,'running'=>202,'progressed'=>202,'completed'=>200,'blocked'=>409,'not_found'=>404,'conflict'=>409,'stale'=>409,'inconsistent_state'=>409,'invalid_request'=>400,'database_error'=>500,'epoch_id_exhausted'=>409,'temporarily_unavailable'=>503,'transaction_outcome_unknown'=>503,'verification_required'=>503,'unexpected_domain_code'=>503];
foreach($mapping as $code=>$status){
 $r=$record;
 if($code==='blocked')$r=CommerceResetRecord::from_row((object)array_merge($row,['status'=>'blocked','error_code'=>'secret_diagnostic']));
 if($code==='completed')$r=CommerceResetRecord::from_row((object)array_merge($row,['status'=>'completed','phase'=>'completed','completed_at'=>$row['started_at'],'active_slot'=>null,'target_epoch_id'=>2,'checkpoint'=>'z1_n_0_done','validation_generation'=>0]));
 $next=new CommerceResetResult($code,in_array($code,['started','existing','running','progressed','completed','blocked'],true)?$r:null);
 $res=$c->start(rest_request('start',$id,$epoch));$data=$res->get_data();
 check($res->get_status()===$status&&$data['code']===($code==='unexpected_domain_code'?'verification_required':$code),'exact HTTP mapping '.$code);
 check($data['verify_state']===in_array($code,['transaction_outcome_unknown','verification_required','unexpected_domain_code'],true),'exact verification flag '.$code);
 check(!str_contains(json_encode($data),'secret_diagnostic')&&!str_contains(json_encode($data),'installation_id')&&!str_contains(json_encode($data),'requested_by'),'no private record fields '.$code);
 check($res->headers['Cache-Control']==='no-store','response cache disabled '.$code);
}
$next=new CommerceResetResult('existing',CommerceResetRecord::from_row((object)array_merge($row,['status'=>'blocked','error_code'=>'secret_diagnostic'])));
check($c->start(rest_request('start',$id,$epoch))->get_status()===409,'idempotent blocked start is not HTTP success');
$next=new CommerceResetResult('started');check($c->start(rest_request('start',$id,$epoch))->get_data()['verify_state'],'acceptance without durable record fails closed');
$blocked=CommerceResetRecord::from_row((object)array_merge($row,['status'=>'blocked','error_code'=>'secret_diagnostic']));
foreach(['transaction_outcome_unknown','unexpected_domain_code'] as $code){$next=new CommerceResetResult($code,$blocked);$d=$c->start(rest_request('start',$id,$epoch))->get_data();check($d['verify_state']&&$d['operation']===null,'uncertainty has precedence over supplied blocked snapshot '.$code);}
foreach(['completed','blocked'] as $code){$next=new CommerceResetResult($code,$record);check($c->start(rest_request('start',$id,$epoch))->get_data()['code']==='verification_required','contradictory result record fails closed '.$code);}
$throws=new Controller(function(){throw new RuntimeException('private secret SQL');});$d=$throws->start(rest_request('start',$id,$epoch))->get_data();check($d['code']==='verification_required'&&!str_contains(json_encode($d),'secret'),'unexpected exception is safe verification-required');
check(end($calls)[1]['requested_by']===7,'requester comes from authenticated principal');
echo "All reset REST contract checks passed.\n";
