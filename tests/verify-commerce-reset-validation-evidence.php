<?php
/** Retained global ownership contradictions and supported complete/interrupted workflows. */
declare(strict_types=1);
require __DIR__.'/support/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock,ProviderRegistry,PaymentSessionRepository,CartRepository,PaymentEventRepository,StripeEventNormalizer,PaymentEventReceiptResult};
foreach(['deleted_claim_owner','deletion_owners','duplicate_session_claim'] as $reason){$db=new wpdb('c2c_a1_global_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$old=validation_order($db,1);$d=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock());check($d->delete($old['order'])->code()==='deleted','old modern deletion is real');baseline_epoch_switch($db);$f=validation_order($db,1);check($d->delete($f['order'])->code()==='deleted','source modern deletion is real');
 if($reason==='deleted_claim_owner')sql($db,"UPDATE {$t->provider_claims()} SET order_id={$f['order']} WHERE id={$old['claim']}");
 elseif($reason==='deletion_owners')sql($db,"UPDATE {$t->order_deletions()} SET original_order_id={$f['order']} WHERE original_order_id={$old['order']}");
 else sql($db,"UPDATE {$t->provider_claims()} SET payment_session_id={$f['session']} WHERE id={$old['claim']}");
 $r=validation_start($db);$before=validation_preserved($db);$r=validation_pass($db,$r,1);check($r->status()==='blocked'&&$r->error_code()==='validation_'.$reason,$reason.': retained other-epoch contradiction found at batch size one');check($before===validation_preserved($db),'global-owner contradiction changes no durable evidence or stock');
 }finally{baseline_cleanup($db);}}
// Guarded normal lifecycle: paid conversion, duplicate delivery and cart source cleanup.
$db=new wpdb('c2c_a1_workflow_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);$order=payment_fixture($db);$provider=new ModernBoundaryProvider();$calls=0;$provider->during_call=function()use(&$calls){++$calls;};[$service]=payment_service($db,$provider);check($service->create($order,bin2hex(random_bytes(16)))->is_success(),'real payment service creates active native attempt');$ref=$db->get_var("SELECT reference FROM {$t->payment_sessions()} WHERE order_id=$order");$control=$db->get_row("SELECT * FROM {$t->commerce_control()}");$epoch=$db->get_var("SELECT identity FROM {$t->commerce_epochs()} WHERE id=1");$payload=modern_payload($ref,'evt_paid_a1',$control->installation_id,$epoch);$hash=hash('sha256',json_encode($payload));$hook=webhook_for($db);$response=$hook->handle($payload,$hash);check($response->status()===200,'real paid webhook conversion and reconciliation complete');check($hook->handle($payload,$hash)->status()===200,'duplicate provider delivery is coherent');
 $r=validation_start($db);$before=validation_preserved($db);$old_calls=$calls;$r=validation_pass($db,$r);check($r->phase()==='retiring_claims','paid order, converted coverage, bound provider receipt and absent cart source validate');check($calls===$old_calls&&$before===validation_preserved($db),'validator performs zero provider calls, stock writes or reconciliation');
}finally{baseline_cleanup($db);}
// Creating attempt after a provider return, interrupted before activation, permits nullable identity evidence.
$db=new wpdb('c2c_a1_gap_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'returned identity transaction admitted');$registry=new ProviderRegistry($db,$t);check($registry->bind('stripe_checkout','session','cs_returned',$f['claim'],gmdate('Y-m-d H:i:s'))==='bound'&&$tx->commit(),'provider-return evidence committed while Session remains creating');
 $event=validation_receipt($db,$f,'evt_processing_gap');check($tx->begin_commerce_shared(),'association gap admitted');$er=new PaymentEventRepository($db,$t);
 // The normalized money must match the two-item immutable order.
 $p=modern_payload($f['reference'],'evt_processing_gap',$f['installation'],$f['epoch']);$p['data']['object']['amount_total']=200;$receipt=(new StripeEventNormalizer())->normalize($p,$event->payload_sha256())->receipt();
 check($registry->bind_event($receipt,$registry->claim_by_id($f['claim']),gmdate('Y-m-d H:i:s'))==='bound'&&$tx->commit(),'provider binding may precede operational receipt association');
 $r=validation_start($db);$before=validation_preserved($db);$r=validation_pass($db,$r);check($r->phase()==='retiring_claims'&&$before===validation_preserved($db),'returned identity and bound durable evidence with unresolved receipt are legitimate');
}finally{baseline_cleanup($db);}
// More than one retained claim per deleted order is legitimate and must be traversed.
$db=new wpdb('c2c_a1_attempts_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'renewed attempt admitted');$sessions=new PaymentSessionRepository($db,$t);check($sessions->mark_failed($f['session'],gmdate('Y-m-d H:i:s')),'first attempt releases its active slot');$next=$sessions->create_attempt(bin2hex(random_bytes(16)),$f['order'],'stripe_checkout',hash('sha256','next attempt'),'EUR',200,gmdate('Y-m-d H:i:s'));$original=$db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id={$f['claim']}");$snapshot=json_decode($original->request_snapshot,true);$snapshot['reference']=$next->reference();$snapshot['idempotency_key']='c2c_ps_'.$next->reference();check(is_object((new ProviderRegistry($db,$t))->create_claim($next,$tx->commerce_context(),json_encode($snapshot),'test',gmdate('Y-m-d H:i:s')))&&$tx->commit(),'second native attempt for same order committed');
 $delete=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),$tx,new UtcClock());check($delete->delete($f['order'])->code()==='deleted','actual deletion tombstones both claims');$r=validation_start($db);$before=validation_preserved($db);$r=validation_pass($db,$r,1);check($r->phase()==='retiring_claims'&&$before===validation_preserved($db),'size-one deletion stream accepts and preserves both legitimate retained claims');
}finally{baseline_cleanup($db);}
// Real cart expiration removes mutable sources; historical snapshots remain authoritative.
$db=new wpdb('c2c_a1_cartgone_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'cart expiration admitted');$carts=new CartRepository($db,$t);check($carts->touch($f['cart'],gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),'2000-01-01 00:00:00')&&$carts->delete_expired(gmdate('Y-m-d H:i:s'),1)===1&&$tx->commit(),'actual cart lifecycle removes cart and all mutable sources');sql($db,'DELETE FROM '.$db->posts);$r=validation_start($db);$before=validation_preserved($db);$r=validation_pass($db,$r,1);check($r->phase()==='retiring_claims'&&$before===validation_preserved($db),'absent cart, cart items and mutable catalog do not invalidate immutable history');
}finally{baseline_cleanup($db);}
echo "All A1 retained-evidence and modern workflow checks passed.\n";
