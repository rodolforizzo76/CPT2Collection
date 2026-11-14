<?php
/** Disposable baseline fixtures, including explicit future epoch switches (not production APIs). */
declare(strict_types=1);
require_once __DIR__.'/reset-maintenance.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceBaselineMigration,CommercePhaseTwoMigration,CommercePhaseThreeCheckpointOneMigration,CommercePhaseFourCheckpointOneMigration,CommerceEvidenceGenerationMigration};
/** Counts attempted DML/DDL, including writes that a rollback would otherwise hide. */
class BaselineWriteProbeDb extends wpdb {
 public int $attempted_writes=0;
 public function query(string $query):int|false {
  if(preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$query))++$this->attempted_writes;
  return parent::query($query);
 }
}
function baseline_schema(wpdb $db):void {
 phase_one_schema($db);
 check(CommercePhaseTwoMigration::run($db)&&CommercePhaseThreeCheckpointOneMigration::run($db)&&CommercePhaseFourCheckpointOneMigration::run($db)&&CommerceEvidenceGenerationMigration::run($db),'empty fixture reaches v14');
 sql($db,"CREATE TABLE {$db->postmeta}(meta_id BIGINT AUTO_INCREMENT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB");
 sql($db,"CREATE TABLE {$db->posts}(ID BIGINT PRIMARY KEY,post_type VARCHAR(32)) ENGINE=InnoDB");
 sql($db,"INSERT INTO {$db->posts} VALUES(77,'collection_item')");
 sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(77,'_cpt2collection_stock_quantity','20')");
}
function baseline_snapshot(wpdb $db, bool $before_notifications = false):string {
 $t=new TableNames($db);$out=[];
 foreach(['commerce_control','commerce_epochs','commerce_resets','carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events','provider_claims','provider_identities','order_deletions'] as $m){$db->last_error='';$out[$m]=$db->get_results('SELECT * FROM '.$t->$m().' ORDER BY id');if($db->last_error!==''||!is_array($out[$m]))throw new RuntimeException('snapshot '.$m);}
 if($before_notifications)foreach($out['orders'] as $row)foreach(['notification_event_id','customer_mail_state','customer_mail_to','admin_mail_state','admin_mail_to','customer_name','customer_email'] as $column){
  if(isset($row->$column))throw new RuntimeException('Unexpected paid Order data in historical migration fixture');
  unset($row->$column);
 }
 $out['stock']=$db->get_results('SELECT * FROM '.$db->postmeta.' ORDER BY meta_id');return serialize($out);
}
function baseline_cleanup(wpdb $db):void {sql($db,'DROP TABLE IF EXISTS '.$db->posts);maintenance_cleanup($db);}
function baseline_epoch_switch(wpdb $db):void {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_exclusive(),'future epoch-switch fixture owns fence');
 $n=(int)$db->get_var('SELECT MAX(id) FROM '.$t->commerce_epochs())+1;
 sql($db,"UPDATE {$t->commerce_epochs()} SET retired_at=UTC_TIMESTAMP() WHERE retired_at IS NULL");
 sql($db,"INSERT INTO {$t->commerce_epochs()} VALUES($n,'".bin2hex(random_bytes(16))."',UTC_TIMESTAMP(),NULL)");sql($db,"UPDATE {$t->commerce_control()} SET current_epoch_id=$n");check($tx->commit(),'future epoch-switch fixture committed');
}
function get_option(string $key,mixed $default=false):mixed{return $key==='cpt2collection_post_type'?'collection_item':($GLOBALS['baseline_options'][$key]??(['cpt2collection_stripe_secret_key'=>'sk_test_fixture_only','cpt2collection_stripe_success_url'=>'https://example.test/success','cpt2collection_stripe_cancel_url'=>'https://example.test/cancel'][$key]??$default));}
function update_option(string $key,mixed $value,bool $autoload=true):bool{if($GLOBALS['baseline_lose_ack']??false)return false;$GLOBALS['baseline_options'][$key]=$value;return true;}
class WP_Post_Type {public string $name='collection_item';public bool $_builtin=false;public bool $show_ui=true;public bool $public=true;}
function get_post_types(array $a,string $b):array{return ['collection_item'=>new WP_Post_Type()];}
function apply_filters(string $s,mixed $v):mixed{return $v;}
function wp_cache_delete(mixed ...$a):bool{return true;}
function webhook_for(wpdb $db,?\RodolfoRizzo\Cpt2Collection\ProviderWebhookEvidence $evidence=null):\RodolfoRizzo\Cpt2Collection\PaymentWebhookOrchestrator {
 $ns='RodolfoRizzo\\Cpt2Collection\\';$new=static fn(string $class,...$args)=>new ($ns.$class)(...$args);
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);$clock=$new('UtcClock');$o=$new('OrderRepository',$db,$t);$s=$new('PaymentSessionRepository',$db,$t);$r=$new('ReservationRepository',$db,$t);$e=$new('PaymentEventRepository',$db,$t);$c=$new('CartRepository',$db,$t);
 $out=$new('PaymentEventOutcomeService',$o,$s,$e,$tx,$clock);$v=$new('ConversionReservationSetValidator');$stock=$new('StockConversionProcessor',$r,$new('LockedCatalogReader',$db,$tx),$tx,$v);
 return $new('PaymentWebhookOrchestrator',$new('StripeEventNormalizer'),$e,$new('PaymentEventAssociationService',$o,$s,$e,$tx,$clock,false),$new('SaleConversionService',$o,$r,$s,$e,$tx,$stock,$v,$new('PostTypes'),$clock),$new('PaymentExpirationService',$o,$s,$e,$tx,$out,$clock),$out,$clock,$new('CartReconciliationService',$c,$o,$tx,$clock),$tx,$evidence??$new('ProviderWebhookEvidence',static fn()=>new wpdb($db->prefix)));
}
function paid_payload(string $reference,string $event):array {
 return ['id'=>$event,'type'=>'checkout.session.completed','created'=>time(),'livemode'=>false,'data'=>['object'=>['object'=>'checkout.session','id'=>'cs_gap_'.$reference,'client_reference_id'=>$reference,'payment_intent'=>'pi_'.$reference,'mode'=>'payment','currency'=>'eur','amount_total'=>100,'payment_status'=>'paid','status'=>'complete']]];
}
function payment_fixture(wpdb $db,int $post=77):int {
 provider_response_schema($db);
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'payment fixture admitted');
 $o=new \RodolfoRizzo\Cpt2Collection\OrderRepository($db,$t);$r=new \RodolfoRizzo\Cpt2Collection\ReservationRepository($db,$t);$now=gmdate('Y-m-d H:i:s');
 $c=new \RodolfoRizzo\Cpt2Collection\CartRepository($db,$t);
 $cart=$c->create(hash('sha256',random_bytes(32)),$now,$now,$now,'2030-01-01 00:00:00');
 sql($db,"INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,content_version,created_at,updated_at) VALUES({$cart->id()},$post,1,0,'$now','$now')");$source=$db->insert_id;
 $order=$o->create($cart->id(),'pending','EUR',100,100,$now,$now,hash('sha256',random_bytes(32)),0,0);$item=$o->insert_item($order->id(),$post,'Concurrency',100,1,100,100,'EUR',$now,$source,0);
 check(false!==$r->create($order->id(),$item->id(),$post,1,'active',$now,$now,gmdate('Y-m-d H:i:s',time()+7200))&&$tx->commit(),'payment fixture commits');return $order->id();
}
function payment_service(wpdb $db,\RodolfoRizzo\Cpt2Collection\PaymentProviderInterface $p):array {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);
 if($p instanceof ModernBoundaryProvider)$p=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider($p,new \RodolfoRizzo\Cpt2Collection\UtcClock());
 return [new \RodolfoRizzo\Cpt2Collection\PaymentSessionService(new \RodolfoRizzo\Cpt2Collection\OrderRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\ReservationRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\PaymentSessionRepository($db,$t),$tx,new \RodolfoRizzo\Cpt2Collection\ReservationSetValidator(),$p,new \RodolfoRizzo\Cpt2Collection\UtcClock()),$tx];
}

final class ModernBoundaryProvider implements \RodolfoRizzo\Cpt2Collection\PaymentProviderInterface, \RodolfoRizzo\Cpt2Collection\StripeCheckoutClientInterface {
 public ?Closure $during_call=null; public bool $exception=false;
 public function create_checkout_session(array $parameters,array $options):array {
  if($this->during_call!==null)($this->during_call)($parameters);
  $ref=$parameters['client_reference_id'];
  return ['id'=>'cs_gap_'.$ref,'payment_intent'=>'pi_'.$ref,'url'=>$this->exception?'invalid':'https://example.test/pay','expires_at'=>$parameters['expires_at']];
 }
 public function name():string{return 'stripe_checkout';}
 public function request_configuration(\RodolfoRizzo\Cpt2Collection\CommerceContext $c):array{return ['environment'=>'test','installation_id'=>$c->installation_id(),'epoch_identity'=>$c->epoch_identity()];}
 public function create_session(\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest $r):\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult {
  if($this->during_call!==null)($this->during_call)($r);
  if($this->exception)throw new \RodolfoRizzo\Cpt2Collection\PaymentProviderException(\RodolfoRizzo\Cpt2Collection\PaymentProviderException::OUTCOME_UNKNOWN,'cs_gap_'.$r->reference(),'pi_'.$r->reference());
  return new \RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult('cs_gap_'.$r->reference(),'https://example.test/pay',$r->reservation_deadline(),'pi_'.$r->reference());
 }
}
function modern_payload(string $ref,string $event, string $installation= 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',string $epoch='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'):array {
 $p=paid_payload($ref,$event);$p['data']['object']['metadata']=['c2c_integration'=>'cpt2collection','c2c_installation_id'=>$installation,'c2c_epoch_identity'=>$epoch,'c2c_payment_session_reference'=>$ref];return $p;
}
