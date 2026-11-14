<?php
/** Genuine guarded modern writers; reset validation observes only local durable evidence. */
declare( strict_types=1 );
require_once __DIR__ . '/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceOrderSourceIndexMigration,CommerceClaimSessionIndexMigration,CommerceDeletedOrderIndexesMigration,CommerceResetService,CommerceResetRepository,CommerceResetRecord,TransactionManager,TableNames,UtcClock,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,PaymentProviderSessionRequest,PaymentProviderLineItem,ProviderRegistry,ProviderClaimRecord,CommerceEvidenceContext,StripeEventNormalizer,PaymentEventRepository};
function validation_schema( wpdb $db, bool $notifications = true ): void {
 baseline_schema($db);
 check(CommerceBaselineMigration::run($db)&&CommerceBaselinePurgeMigration::run($db)&&CommerceOrderSourceIndexMigration::run($db)&&CommerceClaimSessionIndexMigration::run($db)&&CommerceDeletedOrderIndexesMigration::run($db),'v19 completed modern fixture');
 if ($notifications && !(\RodolfoRizzo\Cpt2Collection\PaymentNotificationMigration::run($db) && \RodolfoRizzo\Cpt2Collection\OrderCustomerMigration::run($db))) throw new RuntimeException('v21 Order fixture');
}
function validation_service( wpdb $db ): CommerceResetService { $t=new TableNames($db);return new CommerceResetService(new CommerceResetRepository($db,$t),new TransactionManager($db,$t),new UtcClock()); }
function validation_start( wpdb $db ): CommerceResetRecord { $t=new TableNames($db);$epoch=$db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE retired_at IS NULL');$r=validation_service($db)->start(bin2hex(random_bytes(16)),$epoch);check($r->code()==='started','production reset starts validating');return $r->operation(); }
function validation_order(wpdb $db,int $items=2,bool $session=true,bool $reservations=true):array {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'fixture owns commerce');$context=$tx->commerce_context();$at=gmdate('Y-m-d H:i:s');$deadline=gmdate('Y-m-d H:i:s',time()+7200);
 $c=new CartRepository($db,$t);$o=new OrderRepository($db,$t);$r=new ReservationRepository($db,$t);$s=new PaymentSessionRepository($db,$t);
 $cart=$c->create(hash('sha256',random_bytes(32)),$at,$at,$at,'2030-01-01 00:00:00');$order=$o->create($cart->id(),'pending','EUR',100*$items,100*$items,$at,$at,hash('sha256',random_bytes(32)),0,0);$ids=[];$lines=[];
 for($n=0;$n<$items;++$n){$source=$c->add_item_quantity_locked($cart->id(),77+$n,1);$item=$o->insert_item($order->id(),77+$n,'Snapshot '.$n,100,1,100,100,'EUR',$at,$source->id(),$source->content_version());if(false===$item)throw new RuntimeException('item fixture');$ids[]=$item->id();$lines[]=new PaymentProviderLineItem('Snapshot '.$n,100,1);if($reservations&&false===$r->create($order->id(),$item->id(),77+$n,1,'active',$at,$at,$deadline))throw new RuntimeException('reservation fixture');}
 $claim=null;$ps=null;
 if($session){$ps=$s->create_attempt(bin2hex(random_bytes(16)),$order->id(),'stripe_checkout',hash('sha256',random_bytes(32)),'EUR',100*$items,$at);$request=new PaymentProviderSessionRequest($ps->reference(),'c2c_ps_'.$ps->reference(),'EUR',100*$items,$lines,$deadline,['environment'=>'test','installation_id'=>$context->installation_id(),'epoch_identity'=>$context->epoch_identity()]);$claim=(new ProviderRegistry($db,$t))->create_claim($ps,$context,$request->snapshot(),'test',$at);if(false===$claim)throw new RuntimeException('claim fixture');}
 check($tx->commit(),'guarded snapshot/reservation/creating attempt/native claim committed');return ['order'=>$order->id(),'cart'=>$cart->id(),'items'=>$ids,'session'=>$ps?->id(),'claim'=>null===$claim?null:(int)$claim->id,'reference'=>$ps?->reference(),'installation'=>$context->installation_id(),'epoch'=>$context->epoch_identity()];
}
function validation_receipt(wpdb $db,array $f,string $event):object {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'receipt before association admitted');$p=modern_payload($f['reference'],$event,$f['installation'],$f['epoch']);$p['data']['object']['amount_total']=count($f['items'])*100;
 $receipt=(new StripeEventNormalizer())->normalize($p,hash('sha256',json_encode($p)))->receipt();$result=(new PaymentEventRepository($db,$t))->insert_complete_receipt($receipt,gmdate('Y-m-d H:i:s'));if(null===$result->event())throw new RuntimeException('receipt fixture');check($tx->commit(),'complete normalized unresolved receipt committed');return $result->event();
}
function validation_preserved(wpdb $db):string { $a=unserialize(baseline_snapshot($db));unset($a['commerce_resets']);$a['products']=$db->get_results('SELECT * FROM '.$db->posts.' ORDER BY ID');return serialize($a); }
function validation_pass(wpdb $db,CommerceResetRecord $record,int $size=1,?string $stop=null):CommerceResetRecord {
 $svc=validation_service($db);
 for($n=0;$n<10000;++$n){if($record->status()==='blocked'||$record->phase()!=='validating'||($stop!==null&&$record->checkpoint()===$stop))return $record;$result=$svc->validate($record,$size);if($result->code()!=='progressed'&&$result->code()!=='blocked')throw new RuntimeException('validation result '.$result->code().' at '.($record->checkpoint()??'capture'));$record=$result->operation();}
 throw new RuntimeException('pass did not terminate');
}
function validation_bind(wpdb $db,CommerceResetRecord $operation,int $claim_id,string $kind,string $value,?string $hash=null,?string $type=null):CommerceResetRecord {
 $t=new TableNames($db);$claim=ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$claim_id));$expected=new CommerceEvidenceContext($operation,$claim);$tx=new TransactionManager($db,$t);check($tx->begin_historical_evidence($expected),'real historical admission');$code=(new ProviderRegistry($db,$t))->bind_historical($expected,'stripe_checkout',$kind,$value,gmdate('Y-m-d H:i:s'),'test',$hash,$type);check(in_array($code,['bound','conflict'],true)&&$tx->commit(),'real historical bind commits '.$code);return validation_service($db)->resume($operation->operation_identity())->operation();
}
