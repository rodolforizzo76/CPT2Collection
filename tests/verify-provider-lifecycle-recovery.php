<?php
/** Phase E full authenticated delivery: physical lost-COMMIT outcomes at every operational stage. */
declare(strict_types=1);
require __DIR__.'/support/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,UtcClock,OrderRepository,PaymentSessionRepository,PaymentEventRepository,ReservationRepository,CartRepository,PaymentEventContinuation,PaymentEventOutcomeService,PaymentEventAssociationService,SaleConversionService,PaymentExpirationService,CartReconciliationService,ConversionReservationSetValidator,StockConversionProcessor,LockedCatalogReader,PostTypes,PaymentWebhookOrchestrator,StripeEventNormalizer,StripeWebhookVerifier,StripeWebhookRestController,ProviderWebhookEvidence};
class ECommitDb extends D4Db {
 public int $commit_number=0;public int $lose_at=0;public bool $physical=false;public bool $lost=false;
 public function query(string $q):int|false {
  if($q==='COMMIT'&&++$this->commit_number===$this->lose_at){$this->lost=true;if($this->physical)parent::query($q);else $this->captured[]=$q;$this->last_error='E missing COMMIT acknowledgement';return false;}
  return parent::query($q);
 }
}
function e_recovery_controller(D2Db $db,array &$fresh):StripeWebhookRestController {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);$clock=new UtcClock();$o=new OrderRepository($db,$t);$s=new PaymentSessionRepository($db,$t);$e=new PaymentEventRepository($db,$t);$r=new ReservationRepository($db,$t);$c=new CartRepository($db,$t);$validator=new ConversionReservationSetValidator();
 $factory=function()use($db,&$fresh){$n=new D4Db($db->prefix);$n->capture=true;$fresh[]=$n;return $n;};
 $boundary=new PaymentEventContinuation($db,$t,$factory);$out=new PaymentEventOutcomeService($o,$s,$e,$tx,$clock,$boundary);
 $orchestrator=new PaymentWebhookOrchestrator(new StripeEventNormalizer(),$e,new PaymentEventAssociationService($o,$s,$e,$tx,$clock,false,null,$boundary),new SaleConversionService($o,$r,$s,$e,$tx,new StockConversionProcessor($r,new LockedCatalogReader($db,$tx),$tx,$validator),$validator,new PostTypes(),$clock,null,$boundary),new PaymentExpirationService($o,$s,$e,$tx,$out,$clock,null,$boundary),$out,$clock,new CartReconciliationService($c,$o,$tx,$clock,null,$c->reconciliation_boundary($factory)),$tx,new ProviderWebhookEvidence($factory));
 return new StripeWebhookRestController(new StripeWebhookVerifier('whsec_'.str_repeat('fixture',3)),$orchestrator);
}
foreach(['receipt'=>1,'association'=>2,'outcome'=>3,'conversion'=>3,'expiration'=>3,'cart'=>4] as $stage=>$commit)foreach([false,true] as $physical){
 $prefix='c2c_e_commit_'.bin2hex(random_bytes(4)).'_';$db=new ECommitDb($prefix);
 try{
  validation_schema($db);$f=validation_order($db,1);webhook_activate($db,$f);$payload=webhook_payload($f,'evt_e_unknown',type:$stage==='expiration'?'checkout.session.expired':'checkout.session.completed');
  if(in_array($stage,['outcome','expiration'],true)){$payload['data']['object']['payment_status']='unpaid';$payload['data']['object']['payment_intent']=null;}
  $db->commit_number=0;$db->lose_at=$commit;$db->physical=$physical;$db->capture=true;$db->captured=[];$fresh=[];
  $response=webhook_deliver(e_recovery_controller($db,$fresh),$payload);
  check($db->lost&&$response->get_status()===500,'full authenticated '.$stage.' lost COMMIT always remains HTTP failure');check(!$db->transaction_connection_usable()&&$db->poison_sql===0,'uncertain operational connection has no usable authority or follow-up SQL');
  foreach(array_slice($fresh,1) as $n){check($n->closed,"fresh inspection connection discarded");if($n->closed){foreach($n->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'fresh operational inspection is read-only');}}
  $db->close();$db=new D4Db($prefix);$t=new TableNames($db);
  $sale=$stage==='cart'||($stage==='conversion'&&$physical);$reconciled=$stage==='cart'&&$physical;
  check(d3_stock($db)===[$sale?19:20],'physical stock matches server outcome at '.$stage);
  $order=$db->get_row('SELECT * FROM '.$t->orders().' WHERE id='.$f['order']);check(($order->status==='paid')===$sale,'Order payment matches physical financial COMMIT');
  check(($order->cart_reconciliation_status==='completed')===$reconciled,'unknown financial COMMIT never automatically starts cart reconciliation');
  check((int)$db->get_var('SELECT content_revision FROM '.$t->carts().' WHERE id='.$f['cart'])===($reconciled?1:0),'cart revision matches only its independent physical COMMIT');check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->cart_items().' WHERE cart_id='.$f['cart'])===($reconciled?0:1),'cart sources and marker commit together');
  $receipt_count=(int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events());check($receipt_count===($stage==='receipt'&&!$physical?0:1),'receipt physical presence is observed independently');
  echo 'E_FAULT '.json_encode(['stage'=>$stage,'commit_physically_executed'=>$physical,'http'=>500,'stock'=>d3_stock($db),'paid'=>$sale,'cart_completed'=>$reconciled,'exact_transaction_provenance'=>'not_provable'])."\n";
  // A genuinely new authenticated delivery proves the current sale independently.
  $again=[];check(webhook_deliver(e_recovery_controller($db,$again),$payload)->get_status()===200,'new authenticated request independently admits '.$stage.' continuation');
  check(d3_stock($db)===[in_array($stage,['outcome','expiration'],true)?20:19],'fresh delivery never repeats a committed decrement');
  if(!in_array($stage,['outcome','expiration'],true)){check($db->get_var('SELECT cart_reconciliation_status FROM '.$t->orders().' WHERE id='.$f['order'])==='completed','new exact K11 admission reconciles coherent sale');$before=baseline_snapshot($db);check(webhook_deliver(e_recovery_controller($db,$again),$payload)->get_status()===200&&baseline_snapshot($db)===$before,'subsequent complete duplicate is entirely idempotent');}
 }finally{d3_cleanup($db);}
}
echo "Phase E full-delivery operational COMMIT fault matrix passed (12 physical cases).\n";
