<?php
/** D4 native paid-sale fixtures and real SQL faults; no provider transport. */
declare(strict_types=1);
require_once __DIR__.'/payment-financial-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CartRepository,OrderRepository,CartReconciliationService,TransactionManager,UtcClock};
class D4Db extends D3Db {
 public $at_query=null;public $at_end=null;
 public function get_results(string $q):?array {
  if($this->at_query!==null){$hook=$this->at_query;$hook($q,$this);}
  return parent::get_results($q);
 }
 public function query(string $q):int|false {
  if($this->at_end!==null&&in_array($q,['COMMIT','ROLLBACK'],true)&&(TransactionManager::admitted_on($this)||TransactionManager::reset_control_on($this)!==null)){$hook=$this->at_end;$this->at_end=null;$hook($q,$this);}
  return parent::query($q);
 }
}
function d4_fixture(D2Db $db):array {[$f,$e,$op]=d3_fixture($db);check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'D4 authoritative sale COMMIT');return [$f,d2_event($db,$e),$op];}
function d4_service(D2Db $db,?array &$fresh=null,?callable $configure=null):CartReconciliationService {
 $t=new TableNames($db);$c=new CartRepository($db,$t);$factory=$fresh===null?null:function()use($db,&$fresh,$configure){$n=new D4Db($db->prefix);$n->capture=true;if($configure!==null)$configure($n);$fresh[]=$n;return $n;};
 return new CartReconciliationService($c,new OrderRepository($db,$t),new TransactionManager($db,$t),new UtcClock(),null,$c->reconciliation_boundary($factory));
}
function d4_cart(wpdb $db):string {$t=new TableNames($db);$out=[];foreach(['carts','cart_items','orders'] as $m)$out[$m]=$db->get_results('SELECT * FROM '.$t->$m().' ORDER BY id');return serialize($out);}
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS',60);
function get_post(int $id):?object {return in_array($id,[77,78],true)?(object)['ID'=>$id]:null;}
function get_post_type(int $id):string {return 'collection_item';}
function get_the_title(int $id):string {return 'Snapshot '.($id-77);}
function get_post_meta(int $id,string $key,bool $single=true):mixed {
 if($key===\RodolfoRizzo\Cpt2Collection\MetaKeys::STATUS)return 'available';
 if($key===\RodolfoRizzo\Cpt2Collection\MetaKeys::PRICE_MINOR)return '100';
 if($key===\RodolfoRizzo\Cpt2Collection\MetaKeys::MINIMUM_ORDER_EXEMPT)return '1';
 $db=$GLOBALS['d4_catalog_db'];return $db->get_var($db->prepare('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=%d AND meta_key=%s',$id,$key));
}
function d4_edit(wpdb $db,array $f,int $quantity=4,bool $required=true):bool {
 $GLOBALS['d4_catalog_db']=$db;$t=new TableNames($db);$tx=new TransactionManager($db,$t);$c=new CartRepository($db,$t);
 $row=$db->get_row('SELECT * FROM '.$t->carts().' WHERE id='.$f['cart']);$cart=\RodolfoRizzo\Cpt2Collection\CartRecord::from_row($row);
 $service=new \RodolfoRizzo\Cpt2Collection\CartService($cart,$c,new \RodolfoRizzo\Cpt2Collection\CartActivity($c,new UtcClock()),new \RodolfoRizzo\Cpt2Collection\CommerceRules(new \RodolfoRizzo\Cpt2Collection\PostTypes()),$tx);
 $result=$c->find_item($f['cart'],77)===null?$service->add(77,$quantity):$service->set_quantity(77,$quantity);
 if($required)check($result->is_success(),'D4 production CartService edit commits quantity/version/revision: '.$result->get_code());
 return $result->is_success();
}
function d4_later(wpdb $db,array $f):void {
 // Simulates a preexisting inconsistent attempt, not a newly supported checkout lifecycle.
 $t=new TableNames($db);$r=get_object_vars($db->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id='.$f['session']));unset($r['id']);$r['reference']=bin2hex(random_bytes(16));$r['creation_key_hash']=hash('sha256',random_bytes(16));$r['provider_session_id']=null;$r['provider_payment_id']=null;$r['active_slot']=null;$r['status']='failed';$db->insert($t->payment_sessions(),$r,array_fill(0,count($r),'%s'));
}
function d4_lock_order(D2Db $db):void {
 $t=new TableNames($db);$locks=[];$source_ids=[];$first_write=null;
 foreach($db->captured as $n=>$q){
  if($first_write===null&&preg_match('/^(INSERT|UPDATE|DELETE|REPLACE) /',$q))$first_write=$n;
  if(!str_contains($q,'FOR UPDATE')&&!str_contains($q,'LOCK IN SHARE MODE'))continue;
  foreach(['commerce_control','commerce_epochs','carts','orders','payment_sessions','payment_events','provider_claims','provider_identities','cart_items'] as $m)if(str_contains($q,'FROM '.$t->$m().' '))$locks[$m][]=$n;
  if(preg_match('/FROM '.preg_quote($t->cart_items(),'/').' WHERE id = (\d+).*FOR UPDATE/',$q,$match))$source_ids[]=(int)$match[1];
 }
 $chain=['commerce_control','commerce_epochs','carts','orders','payment_sessions','payment_events','provider_claims','provider_identities','cart_items'];
 foreach($chain as $m)check(isset($locks[$m]),'K11 actual SQL takes '.$m.' lock');
 for($n=1;$n<count($chain);++$n)check(max($locks[$chain[$n-1]])<min($locks[$chain[$n]]),'K11 actual SQL lock order '.$chain[$n-1].' before '.$chain[$n]);
 $sorted=$source_ids;sort($sorted,SORT_NUMERIC);check($source_ids===$sorted&&count($source_ids)===2,'K11 original source PK locks ascend');check(max($locks['cart_items'])<$first_write,'every source validated before first cart/marker write');
}
