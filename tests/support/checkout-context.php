<?php
/** Phase 4.7 current native cart/checkout fixtures; real SQL and no provider HTTP. */
declare(strict_types=1);
require_once __DIR__.'/checkout-renewal-edge.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,UtcClock,CartRecord,CartRepository,CheckoutContextToken,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry};
function wp_salt(string $scheme):string {return 'phase47-local-test-signing-material-'.$scheme;}
function c47_cart(wpdb $db,array $f):CartRecord {return CartRecord::from_row($db->get_row('SELECT * FROM '.(new TableNames($db))->carts().' WHERE id='.$f['cart']));}
function c47_new(wpdb $db):array {
 $t=new TableNames($db);if((int)$db->get_var("SELECT COUNT(*) FROM {$db->postmeta} WHERE post_id=77 AND meta_key='_cpt2collection_price_minor'")===0)sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(77,'_cpt2collection_price_minor','100'),(77,'_cpt2collection_status','available')");$tx=new TransactionManager($db,$t);$now=gmdate('Y-m-d H:i:s');$raw=bin2hex(random_bytes(32));$repo=new CartRepository($db,$t);
 check($tx->begin_commerce_shared(),'47 fresh cart admitted');$cart=$repo->create(hash('sha256',$raw),$now,$now,$now,'2030-01-01 00:00:00');check($cart instanceof CartRecord&&$repo->add_item_quantity_locked($cart->id(),77,1)!==false&&$tx->commit(),'47 exact cookie cart and source committed');return ['cart'=>$cart->id(),'raw'=>$raw];
}
function c47_token(wpdb $db,array $f):array {$out=(new CartRepository($db,new TableNames($db)))->browser_checkout_context(c47_cart($db,$f));check(is_array($out),'47 context signed under fresh Cart/control fence');return $out;}
function c47_prepare(wpdb $db,array $f,string $key,string $token):object {return d4_checkout($db)->prepare(c47_cart($db,$f),$key,$token);}
function c47_delete(wpdb $db,int $id):object {$t=new TableNames($db);return (new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock()))->delete($id);}
function c47_no_dml(D2Db $db):void {foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'47 rejection attempts zero DML');}
