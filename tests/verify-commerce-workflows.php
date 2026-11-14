<?php
/** Real provider boundary and guarded repositories, on disposable tables. */
declare(strict_types=1);
require __DIR__.'/support/commerce-db-test.php';
function __($s,$domain=''){return $s;}
foreach(['money','order-statuses','reservation-statuses','payment-session-statuses','order-record','order-item','reservation-record','payment-session-record','order-repository','reservation-repository','payment-session-repository','utc-clock','reservation-set-validation-result','reservation-set-validator','payment-provider-line-item','payment-provider-session-request','payment-provider-session-result','payment-provider-exception','payment-provider-interface','payment-session-result','payment-session-service','cart-repository','payment-event-statuses','payment-event-receipt-result','payment-event-repository'] as $class)require_once dirname(__DIR__).'/includes/class-'.$class.'.php';
require __DIR__.'/support/class-fake-payment-provider.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,OrderRepository,ReservationRepository,PaymentSessionRepository,PaymentSessionService,ReservationSetValidator,UtcClock,CartRepository,PaymentEventRepository,PaymentEventReceiptResult};
use RodolfoRizzo\Cpt2Collection\Tests\FakePaymentProvider;
$prefix='c2c_boundary_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);$created=[];
try {
 $source=file_get_contents(dirname(__DIR__).'/includes/class-database.php');preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
 foreach($matches[1] as $ddl){$ddl=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$t->{$m[1]}(),$ddl);preg_match('/\A([^\s]+)/',$ddl,$name);sql($db,'CREATE TABLE '.$ddl.' ENGINE=InnoDB');$created[]=$name[1];}seed_commerce_foundation($db);$created=array_merge($created,migrate_commerce_phase_two_fixture($db));
 $orders=new OrderRepository($db,$t);$reservations=new ReservationRepository($db,$t);$sessions=new PaymentSessionRepository($db,$t);$carts=new CartRepository($db,$t);$events=new PaymentEventRepository($db,$t);
 $at=gmdate('Y-m-d H:i:s');$future=gmdate('Y-m-d H:i:s',time()+1800);
 check(false===$carts->create(str_repeat('f',64),$at,$at,$at,$future),'cart creation rejects autocommit');
 check(false===$orders->create(1,'pending','EUR',100,100,$at,$at),'order creation rejects autocommit');
 check(false===$sessions->create_attempt(str_repeat('e',32),1,'fake',str_repeat('d',64),'EUR',100,$at),'session creation rejects autocommit');
 check(false===$reservations->create(1,1,123,1,'active',$at,$at,$future),'reservation creation rejects autocommit');
 check(!$orders->update_status(1,'pending','failed',$at),'order lifecycle rejects autocommit');
 check(!$events->mark_processing(1,'received',$at),'event processing rejects autocommit');
 check(0===$carts->delete_expired($at),'dormant cart cleanup rejects autocommit');
 $tx=new TransactionManager($db,$t);
 check($tx->begin(),'generic transaction for guard probe');check(false===$carts->create(str_repeat('f',64),$at,$at,$at,$future),'generic transaction cannot bypass commerce admission');$tx->rollback();
 foreach(['success','rejected','context_changed'] as $index=>$scenario){
  $id=$index+1;
  sql($db,$db->prepare("INSERT INTO {$t->orders()}(id,cart_id,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(%d,1,'pending','EUR',100,100,%s,%s)",$id,$at,$at));
  sql($db,$db->prepare("INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(%d,%d,123,'Snapshot',100,1,100,100,'EUR',%s)",$id,$id,$at));
  sql($db,$db->prepare("INSERT INTO {$t->reservations()}(order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES(%d,%d,123,1,'active',%s,%s,%s)",$id,$id,$at,$at,$future));
  $provider=new FakePaymentProvider();if($scenario==='rejected')$provider->scenario='rejected';$calls=0;
  $provider->transaction_probe=function()use($prefix,$tx,$scenario,&$calls){
   ++$calls;check(!$tx->is_active()&&!$tx->is_commerce_admitted(),'provider has no transaction or admission');
   $observer=new wpdb($prefix);sql($observer,'SET SESSION innodb_lock_wait_timeout=1');$exclusive=new TransactionManager($observer,new TableNames($observer));
   check($exclusive->begin_commerce_exclusive(),'independent exclusive admission succeeds during provider call');
   if($scenario==='context_changed')sql($observer,"UPDATE {$prefix}c2c_commerce_control SET installation_id=REPEAT('c',32) WHERE id=1");
   check($exclusive->commit(),'provider observer releases exclusive admission');$observer->close();return false;
  };
  $service=new PaymentSessionService($orders,$reservations,$sessions,$tx,new ReservationSetValidator(),$provider,new UtcClock());$r=$service->create($id,str_repeat(chr(97+$index),32));
  check($calls===1,'one network phase for '.$scenario);
  $state=$db->get_var("SELECT status FROM {$t->payment_sessions()} WHERE order_id=$id");
  if($scenario==='success')check($r->is_success()&&$state==='active','response independently readmits and activates');
  elseif($scenario==='rejected')check($r->get_code()==='provider_rejected'&&$state==='failed','failure response independently readmits');
  else check(!$r->is_success()&&$state==='creating'&&!$tx->is_active(),'response context mismatch cannot activate or rebase');
 }
}finally{foreach(array_reverse($created) as $name)$db->query('DROP TABLE IF EXISTS '.$name);$db->close();}
