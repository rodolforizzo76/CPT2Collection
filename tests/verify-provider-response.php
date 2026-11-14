<?php
/** B response/exception matrix, full create regression and real attempted-DML safety. */
declare(strict_types=1);
require __DIR__.'/support/provider-response.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,PaymentProviderInterface,PaymentProviderSessionRequest,PaymentProviderSessionResult,PaymentProviderException,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock,StripeCheckoutProvider};
foreach(['ready','resetting','completed','later','deleted','deleted_later'] as $state)foreach(['success','success_session_only','exception','session_only','payment_only','rejected','unavailable','window','generic'] as $kind){
 $db=new ResponseDb('c2c_b_response_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);$connections=[];
 try {
  validation_schema($db);$f=validation_order($db);$captured=response_captured($db,$f);$r=null;$history=null;
  if(str_starts_with($state,'deleted')){check((new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock()))->delete($f['order'])->code()==='deleted','permanent deletion fixture');}
  if(in_array($state,['resetting','completed','later','deleted_later'],true))$r=validation_start($db);
  if(in_array($state,['completed','later','deleted_later'],true)){$done=discovery_finish($db,$r);$history=serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));$r=null;}
  if(in_array($state,['later','deleted_later'],true))$r=validation_start($db);
  $before=response_business($db);$generation=$r?->evidence_generation();
  $p=new class($kind) implements PaymentProviderInterface {
   public function __construct(private string $kind){}public function name():string{return 'stripe_checkout';}
   public function create_session(PaymentProviderSessionRequest $r):PaymentProviderSessionResult {
	if($this->kind==='generic')throw new RuntimeException('transport');
	if(!str_starts_with($this->kind,'success'))throw new PaymentProviderException(match($this->kind){'rejected'=>PaymentProviderException::REJECTED,'unavailable'=>PaymentProviderException::UNAVAILABLE,'window'=>PaymentProviderException::WINDOW_TOO_SHORT,default=>PaymentProviderException::OUTCOME_UNKNOWN},$this->kind==='payment_only'?null:'cs_response',$this->kind==='session_only'?null:'pi_response');
	return new PaymentProviderSessionResult('cs_response','https://example.test/pay',$r->reservation_deadline(),$this->kind==='success_session_only'?null:'pi_response');
   }
  };
  $svc=response_service($db,$p,$connections);$db->capture=true;$db->captured=[];$out=response_resume($svc,$captured);$db->capture=false;
  if($state==='ready'){
   check($out->is_success()===str_starts_with($kind,'success'),'current success/exception externally correct '.$kind);
   $expected=match($kind){'success','success_session_only'=>'payment_session_created','rejected'=>'provider_rejected','unavailable'=>'provider_unavailable','window'=>'payment_window_too_short',default=>'provider_response_unknown'};
   check($out->get_code()===$expected,'existing current result code '.$kind.' '.$out->get_code());
  }else {
   check(!$out->is_success()&&response_business($db)===$before,'fenced response changes zero business/stock/foundation/claim/deletion rows '.$state.' '.$kind);
   foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'fenced operational connection attempts zero DML');
  }
  if($kind!=='generic'){
   check((int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE claim_id={$f['claim']}")===(in_array($kind,['payment_only','session_only','success_session_only'],true)?1:2),'success and identity exception retain original owner');
   check((int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE observed_epoch_id<>".$captured[4]->epoch_id())===0,'source observation never adopts current target');
  }else check(!$connections,'transport error without identity creates no evidence');
  if($r!==null)check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===$generation+($kind==='generic'?0:(in_array($kind,['payment_only','session_only','success_session_only'],true)?1:2)),'only active reset charged actual changes');
  if($history!==null)check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'completed history byte stable');
  response_no_operational($connections);
  if(str_starts_with($kind,'success')&&$state!=='ready'){
   $snapshot=baseline_snapshot($db);$more=[];$svc=response_service($db,$p,$more);$duplicate=response_resume($svc,$captured);
   check(!$duplicate->is_success()&&$snapshot===baseline_snapshot($db),'coherent duplicate has zero generation/state changes');response_no_operational($more);
  }
 }finally{baseline_cleanup($db);}
}
// Integrated fail-closed inputs: provider facts cannot manufacture or replace native ownership.
foreach(['malformed_session','malformed_payment','foreign_source','unknown_source','wrong_session','changed_request','unsupported_provider','transport'] as $case){
 $db=new ResponseDb('c2c_b_deny_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);$connections=[];
 try{
  validation_schema($db);$f=validation_order($db);$capture=response_captured($db,$f);
  if($case==='foreign_source')$capture[2]=new \RodolfoRizzo\Cpt2Collection\CommerceContext(str_repeat('f',32),$capture[4]->epoch_id(),$capture[4]->epoch_identity());
  if($case==='unknown_source')$capture[2]=new \RodolfoRizzo\Cpt2Collection\CommerceContext($capture[4]->installation_id(),99999,str_repeat('e',32));
  if($case==='wrong_session'){$other=validation_order($db);$capture[1]=response_captured($db,$other)[1];}
  $p=new class($case,$db,$t,$f) implements PaymentProviderInterface{
   public function __construct(private string $case,private wpdb $db,private TableNames $t,private array $f){}public function name():string{return $this->case==='unsupported_provider'?'other_provider':'stripe_checkout';}
   public function create_session(PaymentProviderSessionRequest $r):PaymentProviderSessionResult{
	if($this->case==='transport')throw new RuntimeException('transport');
	if($this->case==='changed_request')sql($this->db,'UPDATE '.$this->t->provider_claims()." SET creation_key_hash=REPEAT('e',64) WHERE id=".$this->f['claim']);
	return new PaymentProviderSessionResult($this->case==='malformed_session'?'foreign_id':'cs_negative','https://example.test/pay',$r->reservation_deadline(),$this->case==='malformed_payment'?'foreign_pi':'pi_negative');
   }
  };
  $svc=response_service($db,$p,$connections);$out=response_resume($svc,$capture);
  check(!$out->is_success()&&(int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities())===0,'B rejects '.$case);
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_sessions()." WHERE status='active'")===0,'denied provider response cannot activate');
  foreach($connections as $c)check(!array_filter($c->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'denied retained facts attempt zero DML');
 }finally{baseline_cleanup($db);}
}
// Full production claim -> Stripe adapter -> evidence -> exact-source activation, not only a resumed stage.
$db=new ResponseDb('c2c_b_create_'.bin2hex(random_bytes(5)).'_');
try{validation_schema($db);$f=validation_order($db,1,false);$connections=[];$client=new ModernBoundaryProvider();
 $client->during_call=function()use($db){check(TransactionManager::context_on($db)===null&&TransactionManager::retained_context_on($db)===null,'provider HTTP begins after all local authority ended');};
 $svc=response_service($db,new StripeCheckoutProvider($client,new UtcClock()),$connections);$key=str_repeat('b',32);check($svc->create($f['order'],$key)->is_success(),'ordinary full current-modern checkout activates');check($svc->create($f['order'],$key)->is_success()&&count($connections)===1,'active checkout replay creates no new provider/evidence operation');response_no_operational($connections);
}finally{baseline_cleanup($db);}
echo "All synchronous B response matrix checks passed.\n";
