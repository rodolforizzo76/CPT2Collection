<?php
/** K14 uses current checkout lifecycle, not retained ownership to renew a generation. */
declare(strict_types=1);
require_once __DIR__.'/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,UtcClock,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,PaymentEventRepository,CheckoutService,CommerceRules,PostTypes,ReservationAcquirer,LockedCatalogReader,OrderDeletionRegistry,CartRecord};
function d4_checkout(wpdb $db):CheckoutService {
 $GLOBALS['d4_catalog_db']=$db;$t=new TableNames($db);$tx=new TransactionManager($db,$t);$c=new CartRepository($db,$t);$o=new OrderRepository($db,$t);$r=new ReservationRepository($db,$t);$clock=new UtcClock();$post=new PostTypes();
 return new CheckoutService($c,new CommerceRules($post),$o,$tx,new ReservationAcquirer($o,$r,$tx,new LockedCatalogReader($db,$tx),$post,$clock),$clock,$r,new PaymentSessionRepository($db,$t),new PaymentEventRepository($db,$t),new OrderDeletionRegistry($db,$t));
}
function d4_renew(wpdb $db,array $f):object {$t=new TableNames($db);return d4_checkout($db)->prepare(CartRecord::from_row($db->get_row('SELECT * FROM '.$t->carts().' WHERE id='.$f['cart'])),bin2hex(random_bytes(16)));}
function d4_expired_fixture(D2Db $db,bool $active=false):array {
 [$f,$e,$op]=d3_fixture($db,'checkout.session.expired');$t=new TableNames($db);
 // A normal unpaid Checkout Session need not have a PaymentIntent. Fixture chronology
 // represents the expiry notification; no new production expiry/cleanup policy is added.
 sql($db,'UPDATE '.$t->payment_sessions()." SET provider_payment_id=NULL,expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=".$f['session']);
 foreach([77,78] as $id)sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES($id,'_cpt2collection_price_minor','100'),($id,'_cpt2collection_status','available')");
 check(d3_run($db,'expiration',$f,$e,$op)->is_accepted(),'K14 production expiration commits without releasing reservations');
 if(!$active)sql($db,'UPDATE '.$t->reservations()." SET status='expired',expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE order_id=".$f['order']);
 else sql($db,'UPDATE '.$t->reservations().' SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE order_id='.$f['order']);
 return [$f,d2_event($db,$e),$op];
}
