<?php
/** D0 K14: new generation on a new Order, old provider owner never adopted. */
declare(strict_types=1);
require __DIR__.'/support/checkout-renewal-edge.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
foreach(['safe','active_reservation','paid','multiple_sessions','unprocessed_expiration','confirmed_payment','alarm','future_deadline','reset','deleted'] as $case){
 $db=new D4Db('c2c_d4_renew_'.bin2hex(random_bytes(4)).'_');
 try{
  if($case==='paid')[$f,$e,$op]=d4_fixture($db);else [$f,$e,$op]=d4_expired_fixture($db,$case==='active_reservation');
  $t=new TableNames($db);$stock=d3_stock($db);$old_claim=serialize($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']));
  if($case==='multiple_sessions')d4_later($db,$f);
  elseif($case==='unprocessed_expiration')sql($db,'UPDATE '.$t->payment_events()." SET processing_status='received',result_code=NULL");
  elseif($case==='confirmed_payment')sql($db,'UPDATE '.$t->payment_sessions()." SET provider_payment_id='pi_unconfirmed'");
  elseif($case==='alarm')sql($db,'UPDATE '.$t->payment_sessions()." SET reconciliation_code='alarm'");
  elseif($case==='future_deadline')sql($db,'UPDATE '.$t->payment_sessions().' SET expires_at=UTC_TIMESTAMP()+INTERVAL 1 DAY');
  elseif($case==='reset')validation_start($db);
  elseif($case==='deleted')d1_delete($db,$f);
  $before=d4_cart($db);$out=d4_renew($db,$f);
  if($case==='safe'){
   check($out->is_success()&&$out->get_order_id()!==$f['order'],'K14 conclusively expired generation creates a distinct new Order');
   check((int)$db->get_var('SELECT checkout_generation FROM '.$t->carts().' WHERE id='.$f['cart'])===1,'renewal advances generation once without cart subtraction');
   check(serialize($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']))===$old_claim,'renewal preserves original request/claim identity');
   [$paid,$paid_op]=d3_event($db,$f,'checkout.session.completed','evt_old_after_renewal');
   check(d3_run($db,'conversion',$f,$paid,$paid_op)->is_success(),'late paid Event can still convert its exact old Order with sufficient stock/hold proof');
   check(d4_service($db)->reconcile($f['order'],$paid_op)->is_completed(),'legitimate renewed-generation late sale reconciles only its original sources');
   $new=$out->get_order_id();check($db->get_var('SELECT status FROM '.$t->orders().' WHERE id='.$new)==='pending','late old Event never pays the renewed Order');
   $snapshot=baseline_snapshot($db);check(d4_service($db)->reconcile($new,$paid_op)->code()==='continuation_verification_required'&&baseline_snapshot($db)===$snapshot,'shared cart and newer generation do not authorize adoption by old operation');
  }else {check(!$out->is_success(),'K14 refuses '.$case.': '.$out->get_code());check(d4_cart($db)===$before,'refused renewal leaves cart and Order marker unchanged');check(d3_stock($db)===$stock,'renewal refusal has no physical stock effect');}
 }finally{d3_cleanup($db);}
}
echo "D4 K14 checkout renewal edge checks passed.\n";
