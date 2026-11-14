<?php
/** C fresh A1.1 recovery, lost HTTP acknowledgement and provider retry. */
declare(strict_types=1);
require __DIR__.'/support/provider-webhook.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,ProviderEvidenceRouter,ProviderDiscoveryCandidate,StripeEventNormalizer};
foreach(['ready','resetting'] as $state)foreach(['commit','second_write','after_inspection'] as $fault)foreach([false,true] as $after){
 $db=new ResponseDb('c2c_c_recovery_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db,1);$r=$state==='resetting'?validation_start($db):null;$p=webhook_payload($f);$p['data']['object']['id']='cs_recovery';$p['data']['object']['payment_intent']='pi_recovery';
  $before=response_business($db);$controller=webhook_controller($db,$connections,function(ResponseFaultDb $c,int $n)use($fault,$after){if($n===1){$c->mode=$fault;$c->executed=$after;}});
  $out=webhook_deliver($controller,$p);$committed=$fault==='commit'&&$after;$label="$state $fault ".($after?'after':'before');
  check(count($connections)===2&&$connections[0]!==$connections[1],$label.' uncertainty uses fresh connection');
  check($out->get_status()===($committed?200:500),$label.' HTTP acknowledges only proved retained evidence');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities())===($committed?3:0),$label.' exact evidence outcome');
  check(!array_filter($connections[1]->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),$label.' recovery emits no DML/replay');
  if($state==='resetting'||!$committed)check(response_business($db)===$before,$label.' zero operational/stock effects');
  if($r!==null)check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===($committed?3:0),$label.' evidence generation atomic');
  response_no_operational($connections);
  // Model lost HTTP acknowledgement: a new provider delivery gets its own admission.
  $snapshot=baseline_snapshot($db);$retry=[];$again=webhook_deliver(webhook_controller($db,$retry),$p);
  check($again->get_status()===200,'fresh provider retry retained safely '.$label);
  if($committed)check(baseline_snapshot($db)===$snapshot,'retry after lost committed acknowledgement changes nothing');
  else check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities())===3,'retry after proved rollback is independently admitted, not local replay');
  if($r!==null)check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===3,'provider retry charges exactly once per actual key');
  response_no_operational($retry);
 }finally{baseline_cleanup($db);}
}
foreach(['progress','completed','later','conflict'] as $transition)foreach([false,true] as $committed){
 $db=new ResponseDb('c2c_c_recovery_transition_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db,1);$r=validation_start($db);$p=webhook_payload($f);$original=$r;$history=null;
  $controller=webhook_controller($db,$connections,function(ResponseFaultDb $c,int $n)use(&$db,$r,$transition,$committed,$p,$t,&$history){
   if($n!==1)return;$c->mode='commit';$c->executed=$committed;$c->on_close=function()use(&$db,$r,$transition,$p,$t,&$history){
	$prefix=$db->prefix;$db->close();$worker=response_worker($prefix,function(ResponseDb $actor)use($r,$transition,$p):string{
	 $current=validation_service($actor)->resume($r->operation_identity())->operation();
	 if($transition==='progress')validation_service($actor)->validate($current,1);
	 elseif(in_array($transition,['completed','later'],true)){discovery_finish($actor,$current);if($transition==='later')validation_start($actor);}
	 else{$other=$p;$other['created']-=1;$receipt=(new StripeEventNormalizer())->normalize($other,hash('sha256',json_encode($other,JSON_UNESCAPED_SLASHES)))->receipt();check(in_array((new ProviderEvidenceRouter($actor,new TableNames($actor)))->retain(ProviderDiscoveryCandidate::verified_receipt($receipt,gmdate('Y-m-d H:i:s')))->status,['retained','conflict'],true),'independent conflicting observer');}
	 return 'transitioned';
	});check(response_join($worker)==='transitioned','independent transition before fresh C recovery');$db=new ResponseDb($prefix);
	if(in_array($transition,['completed','later'],true))$history=serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$r->id()));
   };
  });
  $out=webhook_deliver($controller,$p);
  // Reset terminalization changes pending disposition: exact recovery stays closed.
  $expected=$transition==='progress'&&$committed?200:500;
  check($out->get_status()===$expected,'fresh Event recovery '.$transition.' '.($committed?'committed':'rollback').' stays exact');
  response_no_operational($connections);check(count($connections)===2&&!array_filter($connections[1]->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'transition recovery zero replay');
  if($history!==null)check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$original->id())),'recovery never modifies completed history');
  if($transition!=='conflict'){$retry=[];$again=webhook_deliver(webhook_controller($db,$retry),$p);check($again->get_status()===200,'new provider retry revalidates original owner after transition');response_no_operational($retry);}
 }finally{baseline_cleanup($db);}
}
echo "All C authenticated webhook recovery checks passed.\n";
// A1.1 does not recover an operational COMMIT; retain the shipped typed 500/poison fence.
class WebhookOperationalFaultDb extends ResponseDb {
 public bool $after=false;private bool $receipt=false;
 public function query(string $q):int|false {
  if(str_starts_with($q,'INSERT INTO')&&str_contains($q,'c2c_payment_events'))$this->receipt=true;
  if($q==='COMMIT'&&$this->receipt){if($this->after)parent::query($q);else $this->captured[]=$q;$this->last_error='operational receipt acknowledgement lost';return false;}
  return parent::query($q);
 }
}
foreach([false,true] as $after){
 $prefix='c2c_c_operational_unknown_'.bin2hex(random_bytes(5)).'_';$db=new WebhookOperationalFaultDb($prefix);$connections=[];
 try {validation_schema($db);$f=validation_order($db,1);webhook_activate($db,$f);$db->after=$after;$db->capture=true;$db->captured=[];$p=webhook_payload($f);
  $out=webhook_deliver(webhook_controller($db,$connections),$p);check($out->get_status()===500&&!$db->transaction_connection_usable()&&$db->poison_queries===0,'operational uncertainty stays typed, poisoned, no A1 recovery or follow-up SQL');
  check(count($connections)===1,'operational uncertainty never triggers evidence recovery');$db->close();$db=new ResponseDb($prefix);$t=new TableNames($db);
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events())===($after?1:0),'operational unknown receipt has observed commit/rollback outcome on discarded fresh connection');
  check((int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77")===20,'uncertain receipt never starts conversion or stock effects');
  $retry=[];check(webhook_deliver(webhook_controller($db,$retry),$p)->get_status()===200,'fresh provider retry uses existing operational idempotency after uncertain receipt');
  check((int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77")===19,'new authenticated request converts exactly once');
 }finally{if(!$db->transaction_connection_usable()){$db->close();$db=new ResponseDb($prefix);}baseline_cleanup($db);}
}
echo "All C operational uncertainty fences passed.\n";
foreach(['resetting','later'] as $state)foreach([false,true] as $after){
 $db=new ResponseDb('c2c_c_noop_unknown_'.bin2hex(random_bytes(5)).'_');$connections=[];
 try{validation_schema($db);$f=validation_order($db,1);$r=validation_start($db);if($state==='later'){discovery_finish($db,$r);$r=validation_start($db);}$p=webhook_payload($f);
  check(webhook_deliver(webhook_controller($db,$connections),$p)->get_status()===200,'historical no-op fixture');$snapshot=baseline_snapshot($db);$connections=[];
  $out=webhook_deliver(webhook_controller($db,$connections,function(ResponseFaultDb $c,int $n)use($after){if($n===1){$c->mode='commit_noop';$c->executed=$after;}}),$p);
  check($out->get_status()===200&&count($connections)===2,'effect-free uncertain COMMIT obtains fresh exact recovery');
  check(baseline_snapshot($db)===$snapshot,'effect-free uncertainty and recovery change no evidence/generation/business/history');
  foreach($connections as $c)check(!array_filter($c->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'effect-free uncertain observation emits zero DML including attempted generation CAS');response_no_operational($connections);
 }finally{baseline_cleanup($db);}
}
echo "All C effect-free uncertainty checks passed.\n";
