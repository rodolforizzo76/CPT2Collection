<?php
/** Phase 4.7 signed current context, invalidation and exact payment claim boundary. */
declare(strict_types=1);
require __DIR__.'/support/checkout-context.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CartRepository,CheckoutContextToken,CommerceContext,CartContext,CartToken,CartCookie,CartActivity,UtcClock};
defined('DAY_IN_SECONDS')||define('DAY_IN_SECONDS',86400);
function sanitize_text_field($s){return $s;}function wp_unslash($s){return $s;}
foreach(['valid','forged','revision','generation','other_cart','expired','retired','reset_complete','old_cookie_new_cart'] as $case){
 $db=new D4Db('c2c_47_ctx_'.bin2hex(random_bytes(4)).'_');
 try{
  validation_schema($db);$f=c47_new($db);$token=c47_token($db,$f);$before_token=$token;
  if($case==='forged')$token['token']=substr($token['token'],0,-1).(substr($token['token'],-1)==='0'?'1':'0');
  if($case==='revision')d4_edit($db,$f,3);
  if($case==='generation'){ $t=new TableNames($db);$tx=new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t);check($tx->begin_commerce_shared()&&(new CartRepository($db,$t))->find_for_update($f['cart'])!==false&&(new CartRepository($db,$t))->advance_checkout_generation($f['cart'],0,0,gmdate('Y-m-d H:i:s'))&&$tx->commit(),'47 authoritative generation changes');}
  if($case==='expired'){$t=new TableNames($db);$tx=new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t);check($tx->begin_commerce_shared(),'expired-token fixture context');$token=CheckoutContextToken::issue(c47_cart($db,$f),$tx->commerce_context(),time()-3601);check($tx->rollback(),'expired-token fixture releases');}
  if($case==='other_cart')$f=c47_new($db);
  if(in_array($case,['retired','reset_complete','old_cookie_new_cart'],true)){$old=c47_cart($db,$f);$reset=validation_start($db);if($case!=='retired')discovery_finish($db,$reset);if($case==='old_cookie_new_cart')$f=c47_new($db);}
  $before=baseline_snapshot($db);$db->capture=true;$db->captured=[];
  $out=in_array($case,['retired','reset_complete'],true)?d4_checkout($db)->prepare($old,bin2hex(random_bytes(16)),$token['token']):c47_prepare($db,$f,bin2hex(random_bytes(16)),$token['token']);
  if($case==='valid'){
   check($out->is_success()&&$out->browser_context()!==null,'47 valid current context prepares snapshot/reservations: '.$out->get_code());$key=bin2hex(random_bytes(16));$repeat=c47_prepare($db,$f,$key,$out->browser_context()['token']);check($repeat->is_success()&&$repeat->get_order_id()===$out->get_order_id(),'same current tuple converges to exact current Order');
   $connections=[];$p=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider(new ModernBoundaryProvider(),new UtcClock());$service=response_service($db,$p,$connections);$pay=$service->create($out->get_order_id(),str_repeat('a',32),$out->commerce_context(),$out->browser_context()['token']);check($pay->is_success(),'47 payment creation validates Cart before Order and keeps current association: '.$pay->get_code());$again=$service->create($out->get_order_id(),str_repeat('a',32),$out->commerce_context(),$out->browser_context()['token']);check($again->is_success()&&$again->get_reference()===$pay->get_reference(),'same unchanged payment attempt legitimately replays');
  }else {check(!$out->is_success(),'obsolete or forged context denied: '.$case);if(!in_array($case,['retired','reset_complete'],true))check($out->get_code()==='stale_checkout_context','precise context refusal '.$case);c47_no_dml($db);check(baseline_snapshot($db)===$before,'context refusal preserves all business/retained/stock state');}
 }finally{d3_cleanup($db);}
}
// Renewal advances generation atomically. Only its own exact key may recover a lost response.
$db=new D4Db('c2c_47_renew_'.bin2hex(random_bytes(4)).'_');try{
 [$f]=d4_expired_fixture($db);$before=c47_token($db,$f);$key=bin2hex(random_bytes(16));$out=c47_prepare($db,$f,$key,$before['token']);check($out->is_success()&&$out->browser_context()['version']!==$before['version'],'renewal returns acknowledged new generation context');
 $snapshot=baseline_snapshot($db);$db->capture=true;$db->captured=[];check(c47_prepare($db,$f,bin2hex(random_bytes(16)),$before['token'])->get_code()==='stale_checkout_context','another old tab cannot adopt the newer Order');c47_no_dml($db);check(baseline_snapshot($db)===$snapshot,'stale renewal tab changes nothing');
 $retry=c47_prepare($db,$f,$key,$before['token']);check($retry->is_success()&&$retry->get_order_id()===$out->get_order_id(),'lost acknowledged renewal response retries only its exact committed attempt');
}finally{d3_cleanup($db);}
// Cookie identity is used identically for guests and logged-in users; no user-account cart exists.
foreach([false,true] as $logged_in){$db=new D4Db('c2c_47_cookie_'.bin2hex(random_bytes(4)).'_');try{
 validation_schema($db);$f=c47_new($db);$_COOKIE[CartCookie::COOKIE_NAME]=$f['raw'];$tokens=new CartToken();$repo=new CartRepository($db,new TableNames($db));$clock=new UtcClock();$ctx=new CartContext($repo,$tokens,new CartCookie($tokens),$clock,new CartActivity($repo,$clock));check($ctx->get_existing_cart()?->id()===$f['cart'],'cookie-bound cart resolved in '.($logged_in?'authenticated':'guest').' mode');$_COOKIE[CartCookie::COOKIE_NAME]=str_repeat('f',64);check($ctx->get_existing_cart()===null,'unknown cookie never adopts another cart');unset($_COOKIE[CartCookie::COOKIE_NAME]);
}finally{d3_cleanup($db);}}
echo "47 checkout context checks passed.\n";
// A freshly fetched context cannot make an old checkout key belong to a different revision.
foreach(['old_key','payment_gap','missing_order','missing_session','missing_creating_session'] as $case){$db=new D4Db('c2c_47_gap_'.bin2hex(random_bytes(4)).'_');try{
 validation_schema($db);$f=c47_new($db);$token=c47_token($db,$f);$key=bin2hex(random_bytes(16));$prepared=c47_prepare($db,$f,$key,$token['token']);check($prepared->is_success(),'47 original checkout before '.$case);$t=new TableNames($db);
 $connections=[];$provider=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider(new ModernBoundaryProvider(),new UtcClock());$service=response_service($db,$provider,$connections);
 if(in_array($case,['old_key','payment_gap'],true))d4_edit($db,$f,3);
 if($case==='missing_order')sql($db,'DELETE FROM '.$t->orders().' WHERE id='.$prepared->get_order_id());
 if($case==='missing_creating_session'){$local=new ModernBoundaryProvider();$local->exception=true;$service=response_service($db,new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider($local,new UtcClock()),$connections);check(!$service->create($prepared->get_order_id(),str_repeat('a',32),$prepared->commerce_context(),$prepared->browser_context()['token'])->is_success(),'test-only missing Session starts from a durable uncertain creating attempt');sql($db,'DELETE FROM '.$t->payment_sessions());}
 if($case==='missing_session'){check($service->create($prepared->get_order_id(),str_repeat('a',32),$prepared->commerce_context(),$prepared->browser_context()['token'])->is_success(),'original exact Session exists before test-only erasure');sql($db,'DELETE FROM '.$t->payment_sessions());}
 $next=$case==='old_key'?c47_token($db,$f):$token;$before=baseline_snapshot($db);$db->capture=true;$db->captured=[];
 if($case==='old_key'){$out=c47_prepare($db,$f,$key,$next['token']);check($out->get_code()==='checkout_attempt_conflict','old key cannot be rebound using fresh revision context');}
 else {$out=$service->create($prepared->get_order_id(),str_repeat('a',32),$prepared->commerce_context(),$prepared->browser_context()['token']);check(!$out->is_success(),'missing/stale parent or Session cannot be implicitly replaced: '.$case);}
 c47_no_dml($db);check(baseline_snapshot($db)===$before,'attempt/claim gap refusal is fully nonmutating');
}finally{d3_cleanup($db);}}
foreach(['create','renew'] as $mode)foreach([false,true] as $physical){$prefix='c2c_47_commit_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);try{
 if($mode==='renew'){[$f]=d4_expired_fixture($db);}else{validation_schema($db);$f=c47_new($db);}
 $token=c47_token($db,$f);$key=bin2hex(random_bytes(16));$db->fault='COMMIT';$db->after=$physical;$db->faulted=false;$out=c47_prepare($db,$f,$key,$token['token']);check($out->get_code()==='transaction_outcome_unknown'&&$out->browser_context()===null&&$db->closed,'unknown checkout COMMIT discards and returns no success/context');$db=new D4Db($prefix);$retry=c47_prepare($db,$f,$key,$token['token']);check($retry->is_success(),'fresh exact checkout retry resolves both physical COMMIT cases without financial replay');check((int)$db->get_var('SELECT COUNT(*) FROM '.(new TableNames($db))->payment_sessions()." WHERE status='creating'")===0,'uncertain checkout never dispatches payment creation');
}finally{d3_cleanup($db);}}
$db=new D4Db('c2c_47_issue_loss_'.bin2hex(random_bytes(4)).'_');try{validation_schema($db);$f=c47_new($db);$db->bookkeeping_failure='ROLLBACK';check((new CartRepository($db,new TableNames($db)))->browser_checkout_context(c47_cart($db,$f))===null&&$db->closed,'unconfirmed context-issuance rollback discards and publishes no context');}finally{d3_cleanup($db);}
echo "47 checkout gaps and uncertainty checks passed.\n";
// Exercise the public controller + real services. Logged-in users retain the same cookie cart workflow.
function home_url(string $path='/'):string{return 'https://example.test'.$path;}
function wp_parse_url(string $url):array|false{return parse_url($url);}
function is_user_logged_in():bool{return $GLOBALS['c47_logged_in']??false;}
if(!class_exists('WP_Error')){class WP_Error {public function __construct(...$args){}}}
class C47CheckoutRequest extends WP_REST_Request {
 public function __construct(private array $parameters,private array $headers){}
 public function get_param(string $key):mixed{return $this->parameters[$key]??null;}
 public function get_header(string $key):string{return $this->headers[strtolower($key)]??'';}
}
foreach([false,true] as $login){$db=new D4Db('c2c_47_rest_'.bin2hex(random_bytes(4)).'_');try{
 validation_schema($db);$f=c47_new($db);$token=c47_token($db,$f);$GLOBALS['c47_logged_in']=$login;$_COOKIE[CartCookie::COOKIE_NAME]=$f['raw'];$repo=new CartRepository($db,new TableNames($db));$clock=new UtcClock();$tokens=new CartToken();$ctx=new CartContext($repo,$tokens,new CartCookie($tokens),$clock,new CartActivity($repo,$clock));$connections=[];$payment_calls=0;
 $provider=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider(new ModernBoundaryProvider(),$clock);$payments=response_service($db,$provider,$connections);$csrf=new \RodolfoRizzo\Cpt2Collection\GuestCsrf();
 $controller=new \RodolfoRizzo\Cpt2Collection\CheckoutRestController(fn()=>$ctx->get_existing_cart(),fn($cart,$key,$opaque)=>d4_checkout($db)->prepare($cart,$key,$opaque),function($id,$key,$source,$opaque)use($payments,&$payment_calls){++$payment_calls;return $payments->create($id,$key,$source,$opaque);},$csrf);
 $params=['checkout_attempt_key'=>str_repeat('a',32),'payment_attempt_key'=>str_repeat('b',32),'checkout_context'=>$token['token']];$headers=['content-type'=>'application/json','origin'=>'https://example.test','x-cpt2collection-csrf'=>$csrf->create_token()];$request=new C47CheckoutRequest($params,$headers);check(is_user_logged_in()===$login&&$controller->authorize($request)===true,'real public authorization accepts same-origin cookie cart in actual supported login mode');
 $bad_headers=$headers;$bad_headers['origin']='https://foreign.test';check($controller->authorize(new C47CheckoutRequest($params,$bad_headers)) instanceof WP_Error,'foreign checkout origin refused before processing');
 $before=baseline_snapshot($db);$db->capture=true;$db->captured=[];$bad=$params;$bad['checkout_context']='v1.'.(time()+3600).'.'.str_repeat('0',64);$response=$controller->initiate(new C47CheckoutRequest($bad,$headers));check($response->get_status()===409&&$response->get_data()['code']==='stale_checkout_context'&&$payment_calls===0,'forged opaque context fails through production REST/service chain');c47_no_dml($db);check(baseline_snapshot($db)===$before,'public invalid context has no commerce side effects');
 $response=$controller->initiate($request);check($response->get_status()===200&&$response->get_data()['checkout_context']!==null,'real public checkout returns hosted response plus acknowledged opaque context');$again=$controller->initiate($request);check($again->get_status()===200&&$again->get_data()['reference']===$response->get_data()['reference'],'real public unchanged retry preserves exact native Session');
 $_COOKIE[CartCookie::COOKIE_NAME]=str_repeat('e',64);$before=baseline_snapshot($db);$response=$controller->initiate($request);check($response->get_status()===404&&baseline_snapshot($db)===$before,'invalid cookie cannot use signed context to adopt another cart');unset($_COOKIE[CartCookie::COOKIE_NAME]);
}finally{d3_cleanup($db);}}
echo "47 public checkout access and exact claim checks passed.\n";
