<?php
/** Receipt/CAS invariants formerly tested with unscoped in-memory legacy fixtures. */
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,TableNames,TransactionManager,StripeEventNormalizer,PaymentEventRepository,PaymentEventReceiptResult as Receipt,PaymentEventAttentionResult as Attention,PaymentEventAssociationResult as Association};
$db=new wpdb('c2c_basereceipt_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 baseline_schema($db);check(CommerceBaselineMigration::run($db),'receipt fixture has modern baseline');
 $order=payment_fixture($db);[$service]=payment_service($db,new ModernBoundaryProvider());check($service->create($order,str_repeat('8',32))->is_success(),'receipt fixture has native request claim');
 $ref=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$order);
 $payload=modern_payload($ref,'evt_receipt_checks');$hash=hash('sha256',json_encode($payload));$n=new StripeEventNormalizer();$receipt=$n->normalize($payload,$hash)->receipt();
 $tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'receipt owner admitted');$repo=new PaymentEventRepository($db,$t);$now=gmdate('Y-m-d H:i:s');
 $stored=$repo->insert_complete_receipt($receipt,$now);check($stored->outcome()===Receipt::INSERTED,'complete native receipt inserted atomically');$event=$stored->event();$id=$event->id();
 check($event->provider_session_id()==='cs_gap_'.$ref&&$event->local_reference()===$ref&&$event->provider_payment_id()==='pi_'.$ref&&$event->currency()==='EUR'&&$event->amount_minor()===100&&$event->processing_status()==='received','all normalized receipt identity/financial fields persisted together');
 check($repo->insert_complete_receipt($receipt,$now)->outcome()===Receipt::DUPLICATE_COHERENT,'same immutable receipt duplicate coherent');
 $different=$n->normalize($payload,hash('sha256','changed'))->receipt();check($repo->insert_complete_receipt($different,$now)->outcome()===Receipt::DUPLICATE_INTEGRITY_CONFLICT&&$repo->find($id)->payload_sha256()===$hash,'different duplicate hash cannot overwrite receipt');
 check($repo->bind_association($id,999,'cs_other',str_repeat('f',32),'pi_other','USD',999)->outcome()===Association::CONFLICT&&$repo->find($id)->payment_session_id()===null,'contradictory association cannot mutate identity');
 check($repo->bind_epoch($id,1),'receipt ownership bound to admitted epoch');
 check($repo->mark_attention_after_confirmed_rollback($id,'received',0,null,$hash,'insufficient_stock',$now)->outcome()===Attention::TRANSITIONED,'received snapshot transitions to attention');
 check($repo->mark_attention_after_confirmed_rollback($id,'received',0,null,$hash,'insufficient_stock',$now)->outcome()===Attention::ALREADY_ATTENTION,'attention replay idempotent');
 sql($db,"UPDATE {$t->payment_events()} SET processing_status='retryable_failed',attempt_count=2,last_attempt_at='$now',attention_at=NULL WHERE id=$id");
 check($repo->mark_attention_after_confirmed_rollback($id,'retryable_failed',2,$now,$hash,'stock_invalid',$now)->outcome()===Attention::TRANSITIONED,'retryable-failed snapshot transitions to attention');
 foreach(['processing','processed','ignored'] as $status){sql($db,$db->prepare('UPDATE '.$t->payment_events().' SET processing_status=%s WHERE id=%d',$status,$id));check($repo->mark_attention_after_confirmed_rollback($id,$status,2,$now,$hash,'conflict',$now)->outcome()===Attention::INVALID_INPUT,$status.': attention source rejected');}
 sql($db,"UPDATE {$t->payment_events()} SET processing_status='received',attempt_count=3,attention_at=NULL WHERE id=$id");
 check($repo->mark_attention_after_confirmed_rollback($id,'received',2,$now,$hash,'conflict',$now)->outcome()===Attention::STATE_CONFLICT,'attention rejects stale attempt metadata');
 check($repo->mark_attention_after_confirmed_rollback($id,'received',3,$now,hash('sha256','wrong'),'conflict',$now)->outcome()===Attention::STATE_CONFLICT,'attention rejects payload hash mismatch');
 check($tx->rollback(),'receipt test effects roll back');
 $source=file_get_contents(dirname(__DIR__).'/includes/class-payment-event-repository.php');check(substr_count($source,'processing_status = %s, attempt_count = attempt_count + 1')===1,'only transaction-owned processing increments acquisition metadata');
}finally{baseline_cleanup($db);}
