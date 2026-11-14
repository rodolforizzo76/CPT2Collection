<?php
/** Modern registry contracts; genuine legacy upgrade is tested separately. */
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,ProviderRegistry,ProviderClaimRecord,TransactionManager,TableNames,UtcClock};
$db=new wpdb('c2c_registry_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);$tx=new TransactionManager($db,$t);$r=new ProviderRegistry($db,$t);$now=gmdate('Y-m-d H:i:s');
try {
 baseline_schema($db);check(CommerceBaselineMigration::run($db)&&CommerceBaselinePurgeMigration::run($db),'modern registry fixture reaches pristine baseline');
 $order=payment_fixture($db);[$svc]=payment_service($db,new ModernBoundaryProvider());$key=str_repeat('k',32);check($svc->create($order,$key)->is_success(),'native provider attempt activates');
 $ref=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$order);$claim=$r->claim($ref);check(ProviderClaimRecord::from_row($claim)->epoch_id()===1,'native captured claim parses');
 check($svc->create($order,$key)->is_success(),'active native replay converges');
 $saved=$claim->request_snapshot;sql($db,'UPDATE '.$t->provider_claims().' SET request_version=1,request_snapshot=NULL WHERE id='.(int)$claim->id);
 check($svc->create($order,$key)->get_code()==='payment_identity_conflict','active replay cannot authorize obsolete request evidence');
 sql($db,"UPDATE {$t->payment_sessions()} SET status='creating' WHERE order_id=$order");check($svc->create($order,$key)->get_code()==='payment_identity_conflict','creating retry cannot reconstruct absent request');
 $db->update($t->provider_claims(),['request_version'=>2,'request_snapshot'=>$saved],['id'=>(int)(int)$claim->id],['%d','%s'],['%d']);sql($db,"UPDATE {$t->payment_sessions()} SET status='active' WHERE order_id=$order");
 check($tx->begin_commerce_shared(),'modern registry writes admitted');
 check($r->bind('stripe_checkout','session','cs_case',(int)$claim->id,$now)==='bound'&&$r->bind('stripe_checkout','session','CS_case',(int)$claim->id,$now)==='bound','case-distinct identities stay distinct');
 check($r->bind('stripe_checkout','session','cs_case',(int)$claim->id,$now)==='bound','coherent duplicate binding idempotent');
 check($r->bind('stripe_checkout','session','cs_unknown',null,$now)==='scope_rejected','unscoped first-write rejected');
 check($r->bind('stripe_checkout','session','cs_no_owner',99999,$now)==='database_error','missing owner rejected');
 // A modern unresolved observation can still receive attributable evidence.
 sql($db,"INSERT INTO {$t->provider_identities()}(provider,identity_kind,identity_value,binding_state,evidence_state,observed_epoch_id,first_seen_at,disposition) VALUES('stripe_checkout','session','cs_unresolved','unresolved','native',1,'$now','pending')");
 check($r->bind('stripe_checkout','session','cs_unresolved',(int)$claim->id,$now)==='bound','native unresolved observation can bind without legacy semantics');
 check($r->bind('stripe_checkout','event','evt_probe',(int)$claim->id,$now,str_repeat('a',64),'probe','test')==='bound','event evidence bound');
 check($r->bind('stripe_checkout','event','evt_probe',(int)$claim->id,$now,str_repeat('b',64),'probe','test')==='conflict','duplicate Event with another hash quarantined');
 check($r->identity('stripe_checkout','event','evt_probe')->binding_state==='quarantined','quarantine retained');
 check($r->bind('stripe_checkout','session','cs_wrong_mode',(int)$claim->id,$now,null,null,'live')==='conflict','native claim environment corroboration enforced');check($tx->commit(),'modern evidence commits');
 $payload=modern_payload($ref,'evt_native');$hash=hash('sha256',json_encode($payload));$webhook=webhook_for($db);
 check($webhook->handle($payload,$hash)->is_accepted()&&$db->get_var('SELECT status FROM '.$t->orders().' WHERE id='.$order)==='paid','native webhook converts sale');
 check($db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77')==='19','physical stock decremented once');
 check($webhook->handle($payload,$hash)->is_accepted()&&$db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77')==='19','duplicate never reconverts');
 baseline_epoch_switch($db);$payload['id']='evt_late_modern';$payload['data']['object']['payment_intent']='pi_late_modern';
 check(!$webhook->handle($payload,hash('sha256',json_encode($payload)))->is_accepted(),'synthetic retirement without canonical completed history grants no webhook evidence authority');
 check(null===$r->identity('stripe_checkout','event','evt_late_modern')&&$db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77')==='19','unproved retirement preserves stock without fabricating evidence; genuine history covered by C');
} finally {if($tx->is_active())$tx->rollback();baseline_cleanup($db);}
