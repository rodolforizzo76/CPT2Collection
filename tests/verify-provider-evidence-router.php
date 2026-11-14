<?php
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderEvidenceRouter,ProviderRegistry,TransactionManager,TableNames,ProviderEvidenceContext,ProviderDiscoveryCandidate,ProviderClaimLocator};
$db=new DiscoveryDb('c2c_a1_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
function retained_run(DiscoveryDb $db,ProviderDiscoveryCandidate $i):\RodolfoRizzo\Cpt2Collection\ProviderEvidenceResult {
 $t=new TableNames($db);$stock=deletion_stock($db);$operational=[];
 foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)$operational[$m]=$db->get_results('SELECT * FROM '.$t->$m().' ORDER BY id');
 $db->captured=[];$db->capture=true;$out=(new ProviderEvidenceRouter($db,$t))->retain($i);$db->capture=false;

 $epoch_ids=[];$reset_seen=false;$claim_seen=false;
 foreach($db->captured as $q)if(str_ends_with($q,'FOR UPDATE')){
  if(str_contains($q,'FROM '.$t->commerce_resets().' '))$reset_seen=true;
  if(str_contains($q,'FROM '.$t->provider_claims().' '))$claim_seen=true;
  if(str_contains($q,'FROM '.$t->commerce_epochs().' ')){check(!$reset_seen&&!$claim_seen,'no epoch acquisition after reset/provider locks');if(preg_match('/WHERE id=([0-9]+)/',$q,$m))$epoch_ids[(int)$m[1]]=true;}
 }
 $ids=array_keys($epoch_ids);$sorted=$ids;sort($sorted,SORT_NUMERIC);check($ids===$sorted,'first epoch ID lock acquisitions ascend numerically');
 foreach($db->captured as $q)if(preg_match('/\A(?:INSERT|UPDATE)\b/',$q))echo 'SQL_WRITE_EVIDENCE: '.$q."\n";
 foreach($db->captured as $q)foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)check(!str_contains($q,$t->$m()),'A1 no operational SQL '.$m);
 foreach($operational as $m=>$rows)check(serialize($rows)===serialize($db->get_results('SELECT * FROM '.$t->$m().' ORDER BY id')),'A1 preserves '.$m);
 check($stock===deletion_stock($db),'A1 stock unchanged');return $out;
}
try {
 validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f);$a0=discovery_run($db,$input);
 check(retained_run($db,$input)->status==='retained','current owner retained');check(retained_run($db,$input)->status==='duplicate','duplicate no-op');
 $tx=new TransactionManager($db,$t);$truth=$tx->begin_retained_evidence($input);$cap=TransactionManager::retained_context_on($db);
 check($cap instanceof ProviderEvidenceContext,'fresh owned narrow capability');
 check(TransactionManager::context_on($db)===null&&TransactionManager::evidence_context_on($db)===null&&TransactionManager::reset_context_on($db)===null&&TransactionManager::reset_control_on($db)===null&&TransactionManager::reset_finalization_on($db)===null,'A1 grants no previous authority');
 $registry=new ProviderRegistry($db,$t);check($registry->bind('stripe_checkout','session','cs_forbidden',$f['claim'],gmdate('Y-m-d H:i:s'))==='database_error','normal registry bind rejected');
 check($registry->terminal('stripe_checkout','evt_forbidden','processed')===false,'terminalization rejected');

 $at=gmdate('Y-m-d H:i:s');
 check(false===(new \RodolfoRizzo\Cpt2Collection\CartRepository($db,$t))->touch($f['cart'],$at,$at,$at),'cart write denied');
 check(false===(new \RodolfoRizzo\Cpt2Collection\OrderRepository($db,$t))->create(1,'pending','EUR',100,100,$at,$at),'order creation denied');
 check(false===(new \RodolfoRizzo\Cpt2Collection\OrderRepository($db,$t))->insert_item($f['order'],10,'Forbidden',100,1,100,100,'EUR',$at),'order item write denied');
 check(false===(new \RodolfoRizzo\Cpt2Collection\ReservationRepository($db,$t))->create(1,1,10,1,'active',$at,$at,$at),'reservation write denied');
 check(false===(new \RodolfoRizzo\Cpt2Collection\PaymentSessionRepository($db,$t))->mark_failed($f['session'],$at),'operational session write denied');
 check((new \RodolfoRizzo\Cpt2Collection\PaymentEventRepository($db,$t))->insert_receipt('stripe_checkout','evt_forbidden','checkout.session.completed',null,false,$at,str_repeat('a',64))->outcome()==='invalid_input','operational receipt creation denied');
 check(false===(new \RodolfoRizzo\Cpt2Collection\AdminStockRepository($db,$tx))->set_exact(10,1,7,6)&&false===(new \RodolfoRizzo\Cpt2Collection\AdminStockRepository($db,$tx))->initialize(10)&&false===(new \RodolfoRizzo\Cpt2Collection\LockedCatalogReader($db,$tx))->decrement_stock(10,7,1),'stock mutation denied');
 check(false===(new \RodolfoRizzo\Cpt2Collection\CommerceResetRepository($db,$t))->find_active(true),'reset repository normal mutation access denied');

 $normal=new \RodolfoRizzo\Cpt2Collection\CommerceContext($cap->claim->installation_id(),$cap->claim->epoch_id(),$cap->claim->epoch_identity());
 $session=\RodolfoRizzo\Cpt2Collection\PaymentSessionRecord::from_row($db->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id='.$f['session']));
 check(false===$registry->create_claim($session,$normal,null,'test',$at),'claim creation denied even with manually supplied normal context');
 try{$tx->begin_retained_evidence($a0);check(false,'A0 result cannot authorize');}catch(TypeError $e){check(true,'A0 result rejected as mutation input');}
 $forged=new ProviderEvidenceContext($input,$cap->claim,$truth);check($registry->bind_retained($forged)['status']==='database_error','constructed context cannot grant authority');
 $other=new DiscoveryDb($db->prefix);check((new ProviderRegistry($other,$t))->bind_retained($cap)['status']==='database_error','capability cannot cross connection');$other->close();check($tx->rollback(),'owned capability transaction ends');check($registry->bind_retained($cap)['status']==='database_error','ended capability revoked');
 $r=validation_start($db);$out=retained_run($db,discovery_input($db,$f,'cs_after_start'));check($out->truth->fence==='active_reset_source','stale A0 reclassified after reset start');
 $fresh=validation_service($db)->resume($r->operation_identity())->operation();check($fresh->evidence_generation()===$r->evidence_generation()+1,'active reset charged exactly once');check(retained_run($db,discovery_input($db,$f,'cs_after_start'))->status==='duplicate','active duplicate');check(validation_service($db)->resume($r->operation_identity())->operation()->equals($fresh),'duplicate freezes generation');
 $done=discovery_finish($db,$fresh);$history=serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));
 check(retained_run($db,discovery_input($db,$f,'cs_after_done'))->truth->fence==='completed_retired','stale A0 reclassified after final switch');check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'completed history byte-identical');
 $later=validation_start($db);$out=retained_run($db,discovery_input($db,$f,'cs_older'));check($out->truth->fence==='older_retired_during_active_reset','older owner under later reset');
 $id=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='cs_older'");check((int)$id->observed_epoch_id===$done->source_epoch_id()&&(int)$id->claim_id===$f['claim'],'original ownership and source observation preserved');
 check(validation_service($db)->resume($later->operation_identity())->operation()->evidence_generation()===$later->evidence_generation()+1,'only later reset charged');check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'older completed reset frozen');
 $current=validation_service($db)->resume($later->operation_identity())->operation();$blocked=validation_service($db)->block($current,'a1_test')->operation();check(retained_run($db,discovery_input($db,$f,'cs_blocked'))->status==='retained','blocked reset permits narrow evidence');
 $now=gmdate('Y-m-d H:i:s');sql($db,"INSERT INTO {$t->provider_identities()}(provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,environment,disposition) VALUES('stripe_checkout','session','cs_null',NULL,'unresolved','native',{$done->source_epoch_id()},'$now',NULL,'pending')");
 check(retained_run($db,discovery_input($db,$f,'cs_null'))->status==='retained','NULL owner binding and nullable enrichment one mutation');
 $web=discovery_webhook($f,event:'evt_a1',session:'cs_older');check(retained_run($db,$web)->status==='retained','Event retained without receipt');$gen=validation_service($db)->resume($later->operation_identity())->operation()->evidence_generation();
 check(retained_run($db,discovery_webhook($f,event:'evt_a1',session:'cs_older',hash:'contradiction'))->status==='conflict','new quarantine committed');check(validation_service($db)->resume($later->operation_identity())->operation()->evidence_generation()===$gen+1,'quarantine exactly one charge');
 check(retained_run($db,discovery_webhook($f,event:'evt_a1',session:'cs_older',hash:'contradiction'))->status==='conflict','existing quarantine remains conflict');check(validation_service($db)->resume($later->operation_identity())->operation()->evidence_generation()===$gen+1,'existing quarantine zero charge');
 $unknown=ProviderDiscoveryCandidate::correlated('stripe_checkout','test',null,['session'=>'cs_wholly_unknown'],$now);$before=baseline_snapshot($db);check(retained_run($db,$unknown)->status==='unattributable'&&$before===baseline_snapshot($db),'unknown provider evidence cannot create a claim');
 $bad=ProviderDiscoveryCandidate::correlated('stripe_checkout','test',ProviderClaimLocator::reference(md5('absent')),['session'=>'cs_older'],$now);check(retained_run($db,$bad)->status==='candidate_mismatch','missing supplied locator cannot authorize');
 sql($db,'UPDATE '.$t->provider_claims()." SET request_version=1 WHERE id={$f['claim']}");check(retained_run($db,$input)->status==='corrupt','malformed retained owner denied');
}finally{baseline_cleanup($db);}
echo "All A1 retained authority and generation checks passed.\n";
