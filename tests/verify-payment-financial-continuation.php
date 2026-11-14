<?php
/** D3 conversion, expiration and read-only processed replay against real MariaDB. */
declare(strict_types=1);
require __DIR__.'/support/payment-financial-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommittedOperation,PaymentFinancialStageInspection};
foreach(['conversion','expiration'] as $stage){
 $db=new D2Db('c2c_d3_normal_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');$stock=d3_stock($db);
  $db->capture=true;$db->captured=[];
  $out=d3_run($db,$stage,$f,$e,$op);d3_assert_lock_order($db,$stage);check(in_array($out->code(),['sale_converted','expiration_processed'],true),'normal '.$stage.' commits: '.$out->code());
  $now=d3_stock($db);check($stage==='expiration'?$now===$stock:$now===array_map(static fn($n)=>$n-1,$stock),'physical stock exclusively decremented once by sale');
  $e=d2_event($db,$e);$snapshot=baseline_snapshot($db);$db->capture=true;$db->captured=[];
  $out=d3_run($db,$stage,$f,$e,$op);check(in_array($out->code(),['sale_already_converted','coherent_idempotent_delivery'],true),'legitimate repeated service call is coherent');d2_no_dml($db);check($snapshot===baseline_snapshot($db),'idempotent call changes nothing');
  $db->captured=[];$out=d3_run($db,'processed_replay',$f,$e,$op);check($out->is_success(),'processed duplicate independently validates financial tuple');d2_no_dml($db);check($snapshot===baseline_snapshot($db),'processed duplicate changes nothing');
  $db->capture=false;
  if($stage==='conversion'){
   [$second,$second_op]=d3_event($db,$f,'checkout.session.async_payment_succeeded','evt_d3_second');
   check(d3_run($db,'conversion',$f,$second,$second_op)->code()==='sale_already_converted'&&d3_stock($db)===$now,'different authenticated paid Event completes without second decrement');
   $t=new TableNames($db);sql($db,'UPDATE '.$t->reservations()." SET converted_at=NULL WHERE order_id=".$f['order']);$db->capture=true;$db->captured=[];
   check(!d3_run($db,'processed_replay',$f,$e,$op)->is_success(),'processed Event cannot conceal contradictory reservations');d2_no_dml($db);
  }
 }finally{d3_cleanup($db);}
}
foreach(['missing_operation','native_request_only','request_items_changed','wrong_claim','wrong_event','session_money','order_currency','missing_reservation','contradictory_converted','provider_identity','processing','retired_attempt'] as $case){
 $db=new D2Db('c2c_d3_reject_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d3_fixture($db);$t=new TableNames($db);$supplied=$op;
  if($case==='missing_operation')$supplied=null;
  elseif($case==='native_request_only')$supplied=CommittedOperation::native_request(discovery_claim($db,$f),gmdate('Y-m-d H:i:s'));
  elseif($case==='request_items_changed'){sql($db,'UPDATE '.$t->order_items().' SET quantity=2,line_subtotal_minor=200,line_total_minor=200 WHERE order_id='.$f['order']);sql($db,'UPDATE '.$t->reservations().' SET quantity=2 WHERE order_id='.$f['order']);}
  elseif($case==='wrong_claim'){$g=validation_order($db);[$other,$supplied]=d3_event($db,$g,'checkout.session.completed','evt_other_claim');}
  elseif($case==='wrong_event'){[$other,$supplied]=d3_event($db,$f,'checkout.session.completed','evt_other');}
  elseif($case==='session_money')sql($db,'UPDATE '.$t->payment_sessions().' SET amount_minor=201 WHERE id='.$f['session']);
  elseif($case==='order_currency')sql($db,'UPDATE '.$t->orders()." SET currency='USD' WHERE id=".$f['order']);
  elseif($case==='missing_reservation')sql($db,'DELETE FROM '.$t->reservations().' WHERE id=(SELECT MIN(id) FROM '.$t->reservations().')');
  elseif($case==='contradictory_converted')sql($db,'UPDATE '.$t->reservations()." SET status='converted',converted_at=NOW() WHERE order_id=".$f['order']);
  elseif($case==='provider_identity')sql($db,'UPDATE '.$t->payment_sessions()." SET provider_payment_id='pi_contradiction' WHERE id=".$f['session']);
  elseif($case==='processing')$e=d2_processing($db,$e);
  elseif($case==='retired_attempt'){ $tx=new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t); check($tx->begin_commerce_shared()&&(new \RodolfoRizzo\Cpt2Collection\PaymentSessionRepository($db,$t))->transition_terminal($f['session'],'active','failed',gmdate('Y-m-d H:i:s'))&&$tx->commit(),'original attempt retired through guarded lifecycle'); }
  $before=baseline_snapshot($db);$db->capture=true;$db->captured=[];$out=d3_run($db,'conversion',$f,$e,$supplied);
  check(!$out->is_success()&&$out->code()!=='transaction_outcome_unknown','sale refuses '.$case);check($before===baseline_snapshot($db),'rejection preserves complete atomic tuple '.$case);d2_no_dml($db);
 }finally{d3_cleanup($db);}
}
foreach(['conversion','expiration','processed_replay'] as $stage)foreach(['reset','delete'] as $mode){
 $db=new D2Db('c2c_d3_fenced_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d3_fixture($db,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');
  if($stage==='processed_replay'){check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'sale before processed fencing');$e=d2_event($db,$e);}
  if($mode==='delete')d1_delete($db,$f);else discovery_finish($db,validation_start($db));
  $snapshot=baseline_snapshot($db);$stock=d3_stock($db);$db->capture=true;$db->captured=[];$out=d3_run($db,$stage,$f,$e,$op);
  check($out->code()===($mode==='delete'?'order_deleted':'commerce_reset_fenced'),'positive original '.$mode.' stops '.$stage);d2_no_dml($db);check($snapshot===baseline_snapshot($db)&&$stock===d3_stock($db),'historical evidence and stock unchanged');
 }finally{d3_cleanup($db);}
}
echo "D3 financial continuation checks passed.\n";
// Exact latest attempt succeeds without selecting the earlier claim by order.
$db=new D2Db('c2c_d3_attempts_'.bin2hex(random_bytes(4)).'_');
try{
 validation_schema($db);sql($db,"INSERT INTO {$db->posts} VALUES(78,'collection_item')");sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(78,'_cpt2collection_stock_quantity','20')");$f=validation_order($db);$second=d1_second_attempt($db,$f);webhook_activate($db,$second);[$e,$op]=d3_event($db,$second);
 check(d3_run($db,'conversion',$second,$e,$op)->code()==='sale_converted','two native attempts on one order preserve the exact completing second owner');
 [$old,$old_op]=d3_event($db,$f,'checkout.session.completed','evt_superseded');$db->capture=true;$db->captured=[];$before=baseline_snapshot($db);
 check(d3_run($db,'conversion',$f,$old,$old_op)->code()==='continuation_verification_required','a superseded original attempt is never replaced with the current claim');d2_no_dml($db);check(baseline_snapshot($db)===$before,'superseded attempt has zero operational effects');
}finally{d3_cleanup($db);}
foreach(['already_expired','already_paid','late_paid','processing_quarantine','ignored','attention','mismatched_original'] as $case){
 $db=new D2Db('c2c_d3_expiry_states_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d3_fixture($db,'checkout.session.expired');$stock=d3_stock($db);
  if(in_array($case,['already_expired','late_paid'],true))check(d3_run($db,'expiration',$f,$e,$op)->is_accepted(),'original expiration commits');
  if(in_array($case,['already_paid','late_paid'],true)){
   [$paid,$paid_op]=d3_event($db,$f,'checkout.session.completed','evt_late_paid');check(d3_run($db,'conversion',$f,$paid,$paid_op)->code()==='sale_converted','current paid Event converts before/after expiration');
   if($case==='late_paid'){$db->capture=true;$db->captured=[];check(d3_run($db,'processed_replay',$f,d2_event($db,$e),$op)->is_success(),'expired processed Event followed by sale is coherently distinguished');d2_no_dml($db);$db->capture=false;}
  }
  if(in_array($case,['already_expired','already_paid'],true)){
   [$new,$new_op]=d3_event($db,$f,'checkout.session.expired','evt_second_expiration');$before=d3_stock($db);check(d3_run($db,'expiration',$f,$new,$new_op)->is_accepted(),'new expiration preserves existing terminal commercial state');$new=d2_event($db,$new);check($new->result_code()===($case==='already_paid'?'sale_already_completed':'already_expired'),'completed expiration is distinct from a sale winning first');check(d3_stock($db)===$before,'expiration performs no physical stock mutation');
  }elseif($case==='processing_quarantine'){
   $e=d2_processing($db,$e);check(d3_run($db,'expiration',$f,$e,$op)->is_accepted()&&d2_event($db,$e)->processing_status()==='attention','processing expiration uses separate D2 quarantine after confirmed rollback');check(d3_stock($db)===$stock,'quarantine never converts stock');
  }elseif(in_array($case,['ignored','attention'],true)){
   [, $outcomes]=d2_services($db);check(d2_outcome($outcomes,$case,$e,$op)->is_accepted(),'separate terminal Event outcome commits');$snapshot=baseline_snapshot($db);$db->capture=true;$db->captured=[];$out=d3_run($db,'expiration',$f,$e,$op);check($out->code()===$case&&baseline_snapshot($db)===$snapshot,'unrelated terminal outcome is preserved without claiming expiration');d2_no_dml($db);
  }elseif($case==='mismatched_original'){
   $g=validation_order($db);$wrong=CommittedOperation::native_request(discovery_claim($db,$g),gmdate('Y-m-d H:i:s'));$snapshot=baseline_snapshot($db);$db->capture=true;$db->captured=[];check(!d3_run($db,'expiration',$f,$e,$wrong)->is_accepted()&&baseline_snapshot($db)===$snapshot,'expiration rejects the baseline wrong-original-operation gap');d2_no_dml($db);
  }
 }finally{d3_cleanup($db);}
}
echo "D3 attempt ownership and expiration state decisions passed.\n";
foreach(['order_unpaid','session_incomplete','missing_order','missing_session','missing_event','missing_reservations','source_event_absent','identity_contradiction','money_conflict'] as $case){
 $db=new D2Db('c2c_d3_probe_corrupt_'.bin2hex(random_bytes(4)).'_');
 try{
  [$f,$e,$op]=d3_fixture($db);check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'sale before independent processed coherence corruption');$e=d2_event($db,$e);$t=new TableNames($db);$stock=d3_stock($db);
  if($case==='order_unpaid')sql($db,'UPDATE '.$t->orders()." SET status='awaiting_payment' WHERE id=".$f['order']);
  elseif($case==='session_incomplete')sql($db,'UPDATE '.$t->payment_sessions().' SET payment_confirmed_at=NULL WHERE id='.$f['session']);
  elseif($case==='missing_order')sql($db,'DELETE FROM '.$t->orders().' WHERE id='.$f['order']);
  elseif($case==='missing_session')sql($db,'DELETE FROM '.$t->payment_sessions().' WHERE id='.$f['session']);
  elseif($case==='missing_event')sql($db,'DELETE FROM '.$t->payment_events().' WHERE id='.$e->id());
  elseif($case==='missing_reservations')sql($db,'DELETE FROM '.$t->reservations().' WHERE order_id='.$f['order']);
  elseif($case==='source_event_absent')sql($db,'UPDATE '.$t->payment_sessions()." SET provider_event_id='evt_erased_original' WHERE id=".$f['session']);
  elseif($case==='identity_contradiction')sql($db,'UPDATE '.$t->provider_identities()." SET binding_state='quarantined',conflict_code='identity_conflict' WHERE identity_kind='payment'");
  elseif($case==='money_conflict')sql($db,'UPDATE '.$t->payment_sessions().' SET confirmed_amount_minor=201 WHERE id='.$f['session']);
  $before=baseline_snapshot($db);$db->capture=true;$db->captured=[];$out=d3_run($db,'processed_replay',$f,$e,$op);check(!$out->is_success(),'processed Event alone never proves sale: '.$case);d2_no_dml($db);check(baseline_snapshot($db)===$before&&d3_stock($db)===$stock,'read-only coherence refusal never repairs state or decrements stock');
 }finally{d3_cleanup($db);}
}
echo "D3 processed replay contradiction and erased-record checks passed.\n";
