<?php
declare(strict_types=1);
require __DIR__.'/support/baseline-purge-concurrency.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration as Migration,CommerceBaselinePurgeMigration,CommerceContext,PaymentSessionRecord,PaymentProviderSessionRequest,PaymentProviderLineItem,TableNames};
foreach(['during','after'] as $when) foreach(['success','exception'] as $kind){
 $prefix='c2c_basenet_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);maintenance_schema($db);
 // Model a native request already committed by v14, then paused at the external call.
 $source=new CommerceContext(str_repeat('a',32),1,str_repeat('b',32));
 $stripe=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider(new ModernBoundaryProvider(),new \RodolfoRizzo\Cpt2Collection\UtcClock());
 $request=new PaymentProviderSessionRequest(str_repeat('c',32),'c2c_ps_'.str_repeat('c',32),'EUR',100,[new PaymentProviderLineItem('Captured request',100,1)],gmdate('Y-m-d H:i:s',time()+7200),$stripe->request_configuration($source));
 sql($db,$db->prepare('UPDATE '.$t->provider_claims()." SET request_version=2,request_snapshot=%s,evidence_state='native',environment='test' WHERE id=1",$request->snapshot()));
 sql($db,"UPDATE {$t->payment_sessions()} SET status='creating',active_slot=1,provider_session_id=NULL,provider_payment_id=NULL,provider_event_id=NULL,completed_at=NULL WHERE id=1");
 sql($db,"UPDATE {$t->orders()} SET status='pending',paid_at=NULL WHERE id=1");
 $db->close();
 $w=worker($prefix,function(wpdb $c,$socket)use($kind):string{
  $t=new TableNames($c);$session=PaymentSessionRecord::from_row($c->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id=1'));
  $source=new CommerceContext(str_repeat('a',32),1,str_repeat('b',32));
  $provider=new ModernBoundaryProvider();$provider->exception=$kind==='exception';
  $provider->during_call=function()use($socket){send_signal($socket,'NETWORK');if(signal($socket)!=='RETURN')throw new RuntimeException('network barrier');};
  [$svc,$tx]=payment_service($c,$provider);
  $request=PaymentProviderSessionRequest::from_snapshot($c->get_var('SELECT request_snapshot FROM '.$t->provider_claims().' WHERE id=1'));
  // Resume the production network stage with a durable session captured before upgrade.
  $result=(new ReflectionMethod($svc,'finish_provider_request'))->invoke($svc,1,$session,$source,$request);
  check(!$tx->is_active()&&$c->attempted_writes===0,'network continuation attempts zero DML/DDL and releases all authority');return $result->get_code();
 });
 $phase=signal($w[1]);check($phase==='NETWORK','request started before baseline, outside locks: '.$phase);$db=new wpdb($prefix);
 try{check(Migration::run($db),'baseline prepares while provider request is in flight');check(CommerceBaselinePurgeMigration::run($db),'progress schema installed');$b=purge_baseline($db);$svc=purge_service($db);$r=$svc->step($b,null,1);$r=$svc->step($b,$r->operation(),1);if($when==='after'){sql($db,"INSERT INTO {$t->orders()}(id,status,currency,subtotal_minor,total_minor,created_at,updated_at,epoch_id) VALUES(99,'paid','EUR',100,100,'2026-01-01','2026-01-01',1)");sql($db,"DELETE FROM {$t->orders()} WHERE id=99");purge_to_end($db,$b);}$snapshot=baseline_snapshot($db);send_signal($w[1],'RETURN');check(joined($w)==='pre_baseline',$kind.': response explicitly excluded');check(baseline_snapshot($db)===$snapshot,$kind.': every provider/operational/stock/generation row unchanged');}
 finally{baseline_cleanup($db);}
}
