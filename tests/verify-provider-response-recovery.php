<?php
/** B consumes A1.1 on fresh connections, never replays or queries a poisoned owner. */
declare(strict_types=1);
require __DIR__.'/support/provider-response.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,ProviderEvidenceRouter,ProviderDiscoveryCandidate,ProviderClaimLocator,ProviderResponseEvidence};
foreach(['ready','resetting'] as $state)foreach(['commit','second_write','after_inspection'] as $fault)foreach([false,true] as $after){
 $db=new ResponseDb('c2c_b_recover_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);$capture=response_captured($db,$f);$r=$state==='resetting'?validation_start($db):null;$before=response_business($db);
  $p=new class implements \RodolfoRizzo\Cpt2Collection\PaymentProviderInterface {
   public function name():string{return 'stripe_checkout';}
   public function create_session(\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest $r):\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult{return new \RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult('cs_recovery','https://example.test/pay',$r->reservation_deadline(),'pi_recovery');}
  };
  $factory=function()use($db,&$connections,$fault,$after){$c=new ResponseFaultDb($db->prefix);$c->capture=true;$connections[]=$c;if(count($connections)===1){$c->mode=$fault;$c->executed=$after;}return $c;};
  $tx=new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t);
  $svc=new \RodolfoRizzo\Cpt2Collection\PaymentSessionService(new \RodolfoRizzo\Cpt2Collection\OrderRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\ReservationRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\PaymentSessionRepository($db,$t),$tx,new \RodolfoRizzo\Cpt2Collection\ReservationSetValidator(),$p,new \RodolfoRizzo\Cpt2Collection\UtcClock(),null,new ProviderResponseEvidence($factory));
  $out=response_resume($svc,$capture);$label="$state $fault ".($after?'after':'before');
  check(count($connections)===2&&$connections[0]!==$connections[1],$label.' B consumes pre-write descriptor on genuinely fresh connection');
  check(!array_filter($connections[1]->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),$label.' recovery emits zero DML/replay');
  $committed=$fault==='commit'&&$after;
  check($out->is_success()===($state==='ready'&&$committed),$label.' continuation only after proved commit and fresh operational authority');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities())===($committed?2:0),$label.' exact committed/rolled-back evidence outcome');
  if(!$out->is_success())check(response_business($db)===$before,$label.' zero operational/stock/owner changes');
  if($r!==null)check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===$r->evidence_generation()+($committed?2:0),$label.' original generation charge atomic');
  response_no_operational($connections);
 }finally{baseline_cleanup($db);}
}
// Effect-free uncertainty still discards the old connection and inspects existing facts.
foreach([false,true] as $after){
 $db=new ResponseDb('c2c_b_noop_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try{validation_schema($db);$f=validation_order($db);discovery_bind($db,$f,'session','cs_noop');discovery_bind($db,$f,'payment','pi_noop');$before=baseline_snapshot($db);$candidate=ProviderDiscoveryCandidate::response(discovery_claim($db,$f),'cs_noop','pi_noop',gmdate('Y-m-d H:i:s'));
  $factory=function()use($db,&$connections,$after){$c=new ResponseFaultDb($db->prefix);$c->capture=true;$connections[]=$c;if(count($connections)===1){$c->mode='commit_noop';$c->executed=$after;}return $c;};
  $out=(new ProviderResponseEvidence($factory))->retain($candidate);check($out->status==='retained'&&count($connections)===2,'no-op unknown uses fresh recovery without claiming a changed transaction committed');check($before===baseline_snapshot($db),'no-op recovery changes zero evidence/generation/commerce facts');foreach($connections as $c)check(!array_filter($c->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'no-op uncertainty/recovery emits zero DML');response_no_operational($connections);
 }finally{baseline_cleanup($db);}
}
foreach(['progress','completed','later','contradictory'] as $transition)foreach([false,true] as $committed){
 $db=new ResponseDb('c2c_b_transition_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);$captured=discovery_claim($db,$f);$r=validation_start($db);$done=null;
  $candidate=ProviderDiscoveryCandidate::response($captured,'cs_recovery','pi_recovery',gmdate('Y-m-d H:i:s'));
  $factory=function()use(&$db,&$connections,$committed,$transition,$f,&$r,&$done,$t){
   $c=new ResponseFaultDb($db->prefix);$c->capture=true;$connections[]=$c;
   if(count($connections)===1){$c->mode='commit';$c->executed=$committed;$c->on_close=function()use(&$db,$transition,$f,&$r,&$done,$t){
	$prefix=$db->prefix;$original=$r;$db->close();
	$worker=response_worker($prefix,function(ResponseDb $actor)use($transition,$f,$original):string{
	$db=$actor;$t=new TableNames($db);$r=$original;
	$r=validation_service($db)->resume($r->operation_identity())->operation();
	if($transition==='progress')$r=validation_service($db)->validate($r,1)->operation();
	elseif(in_array($transition,['completed','later'],true)){$done=discovery_finish($db,$r);if($transition==='later')$r=validation_start($db);}
	else {sql($db,"DELETE FROM {$t->provider_identities()} WHERE identity_kind='session'");if((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities())===0){$one=ProviderDiscoveryCandidate::correlated('stripe_checkout','test',ProviderClaimLocator::reference($f['reference']),['payment'=>'pi_recovery'],gmdate('Y-m-d H:i:s'));check((new ProviderEvidenceRouter($db,$t))->retain($one)->status==='retained','independent contradictory partial observer');}}
	return 'transitioned';
	});check(response_join($worker)==='transitioned','independent reset/observer progresses between uncertainty and B recovery');
	$db=new ResponseDb($prefix);if(in_array($transition,['completed','later'],true)){$done=validation_service($db)->resume($original->operation_identity())->operation();if($transition==='later')$r=\RodolfoRizzo\Cpt2Collection\CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE active_slot=1'));}
   };}
   return $c;
  };
  $out=(new ProviderResponseEvidence($factory))->retain($candidate);
  $expected=$transition==='contradictory'?'verification_required':($committed?'retained':'reentry_required');
  check($out->status===$expected,"$transition ".($committed?'committed':'rollback').' B recovery respects original domain: '.$out->status);
  check(count($connections)===2&&!array_filter($connections[1]->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'transition fresh recovery never replays evidence');
  if($done!==null){$history=serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));check($done->evidence_generation()===($committed?2:0)&&$done->equals(\RodolfoRizzo\Cpt2Collection\CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()))),'completed original domain is unchanged by recovery');}
  if($transition==='later')check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===0,'recovery never charges substitute later reset');
  response_no_operational($connections);
 }finally{baseline_cleanup($db);}
}
echo "All synchronous B A1.1 recovery integration checks passed.\n";
