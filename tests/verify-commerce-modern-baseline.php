<?php
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaseline,CommerceBaselineMigration,TransactionManager,TableNames,ProviderRegistry,CommerceContext,PaymentSessionRecord,CommerceResetService,CommerceResetRepository,UtcClock};
foreach(['fresh','populated'] as $mode){
 $db=new BaselineWriteProbeDb('c2c_base_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try {
  if($mode==='fresh')baseline_schema($db);else maintenance_schema($db);
  $tx=new TransactionManager($db,$t);
  check(!$tx->begin_commerce_shared(),'unprepared installation denies new commerce');
  $before=unserialize(baseline_snapshot($db));
  check(CommerceBaselineMigration::run($db)&&CommerceBaselineMigration::verify_structure($db,$t),$mode.': baseline migration succeeds');
  $after=unserialize(baseline_snapshot($db));$contract=json_decode($after['commerce_control'][0]->baseline_contract,true);
  unset($before['commerce_control'],$after['commerce_control']);check(serialize($before)===serialize($after),$mode.': migration preserves all commerce/evidence/epochs/stock');
  $snapshot=baseline_snapshot($db);check(CommerceBaselineMigration::run($db)&&baseline_snapshot($db)===$snapshot,'retry keeps exact identity boundary and rows');
  check($contract['status']===($mode==='fresh'?'completed':'prepared'),'fresh and pending statuses distinct');
  check($tx->begin_commerce_shared()===($mode==='fresh'),'normal admission matches baseline state');$tx->rollback();
  $expected=$mode==='fresh'?CommerceBaseline::ELIGIBLE:CommerceBaseline::PRE_BASELINE;
  check($tx->provider_scope(str_repeat('a',32),str_repeat('b',32),1)===$expected,'source epoch classified');
  foreach([[str_repeat('f',32),str_repeat('b',32),CommerceBaseline::FOREIGN],[null,null,CommerceBaseline::INVALID],[str_repeat('a',32),'bad',CommerceBaseline::INVALID],[str_repeat('a',32),str_repeat('e',32),CommerceBaseline::UNKNOWN]] as [$i,$e,$want])check($tx->provider_scope($i,$e)===$want,'foreign malformed missing unknown classifications');
  if($mode==='populated'){
   $reset=new CommerceResetService(new CommerceResetRepository($db,$t),$tx,new UtcClock());$res=$reset->start(str_repeat('9',32),str_repeat('b',32));check(!$tx->is_active()&&baseline_snapshot($db)===$snapshot,'pending baseline excludes reset start without writes');
   $old=PaymentSessionRecord::from_row($db->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id=1'));
   [$svc]=payment_service($db,new ModernBoundaryProvider());$method=new ReflectionMethod($svc,'record_identity');
   $attempted=$db->attempted_writes;
   check(!$method->invoke($svc,$old,new CommerceContext(str_repeat('a',32),1,str_repeat('b',32)),'cs_late_pre','pi_late_pre'),'captured old request response rejected');
   check(baseline_snapshot($db)===$snapshot&&$db->attempted_writes===$attempted,'late pre-baseline response attempts no writes and preserves all rows and stock');
   foreach(['evt_brand_new_old_session','evt_old'] as $event){$p=modern_payload(str_repeat('c',32),$event);$p['data']['object']['id']='cs_old';$p['data']['object']['payment_intent']='pi_old';$r=webhook_for($db)->handle($p,hash('sha256',json_encode($p)));check($r->code()==='pre_baseline'&&baseline_snapshot($db)===$snapshot&&$db->attempted_writes===$attempted,'new/known Event old Session rejected before every write');}
  }else{
   $order=payment_fixture($db);$provider=new ModernBoundaryProvider();[$svc]=payment_service($db,$provider);check($svc->create($order,str_repeat('1',32))->is_success(),'modern synchronous provider response activates');
   $ref=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$order);
   $payload=modern_payload($ref,'evt_modern');$hash=hash('sha256',json_encode($payload));$r=webhook_for($db)->handle($payload,$hash);check($r->is_accepted(),'modern webhook routes normally: '.$r->code());
   $snap=baseline_snapshot($db);$r=webhook_for($db)->handle($payload,$hash);check($r->is_accepted()&&baseline_snapshot($db)===$snap,'duplicate modern delivery idempotent');
   $conflict=webhook_for($db)->handle($payload,hash('sha256','conflicting duplicate'));
   check(!$conflict->is_accepted()&&$db->get_var("SELECT binding_state FROM {$t->provider_identities()} WHERE identity_kind='event' AND identity_value='evt_modern'")==='quarantined','conflicting modern Event hash retains quarantine without ownership reassignment');
   $snap=baseline_snapshot($db);
   foreach(['foreign','missing','malformed','unknown','unsupported','claim_conflict'] as $case){
	$attempted=$db->attempted_writes;
	$p=modern_payload($ref,'evt_reject_'.$case);$m=&$p['data']['object']['metadata'];
	if($case==='foreign')$m['c2c_installation_id']=str_repeat('f',32);if($case==='missing')unset($m['c2c_epoch_identity']);if($case==='malformed')$m['c2c_epoch_identity']='broken';if($case==='unknown')$m['c2c_epoch_identity']=str_repeat('e',32);if($case==='unsupported')$p['type']='payment_intent.succeeded';if($case==='claim_conflict')$p['livemode']=true;
	$r=webhook_for($db)->handle($p,hash('sha256',json_encode($p)));check(baseline_snapshot($db)===$snap&&$db->attempted_writes===$attempted,$case.': no receipt identity claim operational stock generation write');
	if($case==='claim_conflict')check(!$r->is_accepted(),'modern contradiction is not silent success');
   }
   $order=payment_fixture($db);$late=new ModernBoundaryProvider();$late->during_call=fn()=>baseline_epoch_switch($db);[$service]=payment_service($db,$late);$r=$service->create($order,str_repeat('2',32));
   check(!$r->is_success(),'retired modern response cannot activate old operational session');
   $ref=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$order);
   check(null===(new ProviderRegistry($db,$t))->identity('stripe_checkout','session','cs_gap_'.$ref),'synthetic retirement without canonical reset history cannot authorize response evidence; genuine completion is covered by verify-provider-response');
   check($tx->provider_scope(str_repeat('a',32),str_repeat('b',32),1)==='eligible','retired modern epoch eligible');
   $p=modern_payload($ref,'evt_retired_modern');$r=webhook_for($db)->handle($p,hash('sha256',json_encode($p)));check(!$r->is_accepted(),'synthetic retirement lacking canonical reset history grants no webhook authority');
   $retired=$db->get_var('SELECT retired_at FROM '.$t->commerce_epochs().' WHERE id=1');
   sql($db,'UPDATE '.$t->commerce_epochs().' SET retired_at=NULL WHERE id=1');
   check(!$tx->begin_commerce_shared(),'inconsistent unretired first epoch fails closed after a modern switch');
   sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET retired_at=%s WHERE id=1',$retired));
  }
  $valid=$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control());
  $invalid=['{}','null','{"version":2}'];
  $original=json_decode($valid,true);
  foreach(['first_epoch_id'=>0,'first_epoch_identity'=>'invalid','installation_id'=>str_repeat('f',32),'status'=>'unknown','version'=>2] as $key=>$value){$bad=$original;$bad[$key]=$value;$invalid[]=json_encode($bad);}
  foreach($invalid as $bad){sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET baseline_contract=%s',$bad));$snap=baseline_snapshot($db);check(!$tx->begin_commerce_shared()&&!CommerceBaselineMigration::run($db)&&baseline_snapshot($db)===$snap,'malformed durable baseline fails closed without repair');}
  sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET baseline_contract=%s',$valid));
 }finally{baseline_cleanup($db);}
}
