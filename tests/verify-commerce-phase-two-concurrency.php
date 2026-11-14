<?php
/** Real InnoDB race barriers. No production epoch-transition/deletion API is provided. */
declare(strict_types=1);
require __DIR__.'/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,ProviderRegistry};
if(!function_exists('pcntl_fork')){throw new RuntimeException('pcntl required');}
$prefix='c2c_p2race_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);$created=phase_two_schema($db);attest_modern_runtime_fixture($db);$created[]=$t->order_deletions();$created[]=$t->commerce_resets();$dir=sys_get_temp_dir().'/'.$prefix;mkdir($dir,0700);
/** Parent connection is closed before forking; children never share a live mysqli connection. */
function race(array $jobs):array {
 global $db,$prefix,$dir;$db->close();$pids=[];
 foreach($jobs as $n=>$job){$pid=pcntl_fork();if($pid===-1){throw new RuntimeException('fork failed');}if($pid===0){try{$c=new wpdb($prefix);$result=$job($c);$c->close();file_put_contents($dir.'/'.$n,json_encode(['ok'=>true,'value'=>$result]));exit(0);}catch(Throwable $e){file_put_contents($dir.'/'.$n,json_encode(['ok'=>false,'error'=>$e->getMessage()]));exit(1);}}$pids[]=$pid;}
 foreach($pids as $pid){pcntl_waitpid($pid,$status);}
 $db=new wpdb($prefix);$results=[];foreach(array_keys($jobs) as $n){$r=json_decode(file_get_contents($dir.'/'.$n),true);check($r['ok'],$n.' worker completed'.($r['error']??''));$results[$n]=$r['value'];unlink($dir.'/'.$n);}return $results;
}

function __(string $s,string $domain=''):string{return $s;}
function get_option(string $key,mixed $default=false):mixed{return $key==='cpt2collection_post_type'?'collection_item':(['cpt2collection_stripe_secret_key'=>'sk_test_fixture_only','cpt2collection_stripe_success_url'=>'https://example.test/success','cpt2collection_stripe_cancel_url'=>'https://example.test/cancel'][$key]??$default);}
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
 return ['id'=>$event,'type'=>'checkout.session.completed','created'=>time(),'livemode'=>false,'data'=>['object'=>['object'=>'checkout.session','id'=>'cs_gap_'.$reference,'client_reference_id'=>$reference,'payment_intent'=>'pi_'.$reference,'metadata'=>['c2c_integration'=>'cpt2collection','c2c_installation_id'=>str_repeat('a',32),'c2c_epoch_identity'=>str_repeat('b',32),'c2c_payment_session_reference'=>$reference],'mode'=>'payment','currency'=>'eur','amount_total'=>100,'payment_status'=>'paid','status'=>'complete']]];
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
 if($p instanceof BoundaryProvider)$p=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider($p,new \RodolfoRizzo\Cpt2Collection\UtcClock());
 return [new \RodolfoRizzo\Cpt2Collection\PaymentSessionService(new \RodolfoRizzo\Cpt2Collection\OrderRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\ReservationRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\PaymentSessionRepository($db,$t),$tx,new \RodolfoRizzo\Cpt2Collection\ReservationSetValidator(),$p,new \RodolfoRizzo\Cpt2Collection\UtcClock()),$tx];
}
final class BoundaryProvider implements \RodolfoRizzo\Cpt2Collection\PaymentProviderInterface, \RodolfoRizzo\Cpt2Collection\StripeCheckoutClientInterface {
 public int $calls=0;public ?Closure $during_call=null;
 public function create_checkout_session(array $parameters,array $options):array {
  ++$this->calls; if(null!==$this->during_call)($this->during_call)($parameters); $ref=$parameters['client_reference_id'];
  return ['id'=>'cs_gap_'.$ref,'payment_intent'=>'pi_'.$ref,'url'=>'https://example.test/pay','expires_at'=>$parameters['expires_at']];
 }
 public function name():string{return 'stripe_checkout';}
 public function create_session(\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest $request):\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult { throw new RuntimeException('Use the Stripe capture path'); }
}
final class LostClaimDb extends wpdb {
 public string $loss='';
 public function query(string $query):int|false {
  if($this->loss==='insert'&&str_starts_with($query,'INSERT INTO')&&str_contains($query,'provider_claims')){$this->last_error='injected claim persistence failure';return false;}
  if($query==='COMMIT'&&in_array($this->loss,['before_commit','after_commit'],true)){
   $mode=$this->loss;$this->loss='';if($mode==='after_commit'){parent::query($query);}
   $killer=new wpdb($this->prefix);$killer->query('KILL CONNECTION '.(int)$this->dbh->thread_id);$killer->close();
   $this->last_error='Connection lost at claim commit';return false;
  }
  return parent::query($query);
 }
}
try {
 $r=race([
  'shared'=>function(wpdb $c)use($dir){$tx=new TransactionManager($c,new TableNames($c));if(!$tx->begin_commerce_shared()){throw new RuntimeException('admission');}file_put_contents($dir.'/held','1');usleep(350000);return $tx->commit();},
  'exclusive'=>function(wpdb $c)use($dir){$end=microtime(true)+5;while(!is_file($dir.'/held')&&microtime(true)<$end){usleep(1000);}$start=microtime(true);$tx=new TransactionManager($c,new TableNames($c));if(!$tx->begin_commerce_exclusive()){throw new RuntimeException('exclusive');}$wait=microtime(true)-$start;$tx->rollback();return $wait;}
 ]);check($r['shared']&&$r['exclusive']>.20,'exclusive transition waits for shared admission');unlink($dir.'/held');
 $now=gmdate('Y-m-d H:i:s');foreach([1,2] as $id){sql($db,"INSERT INTO {$t->provider_claims()}(id,installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,evidence_state,disposition,created_at) VALUES($id,REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout',REPEAT('$id',32),".($id+1000).",".($id+1000).",REPEAT('$id',64),'c2c_ps_$id',2,'native','open','$now')");sql($db,"UPDATE {$t->provider_claims()} SET idempotency_key=CONCAT('c2c_ps_',reference) WHERE id=$id");native_provider_claim_fixture($db,$id);}
 $worker=static function(int $claim,string $kind,string $identity)use($now):Closure{return static function(wpdb $c)use($claim,$kind,$identity,$now):string{$t=new TableNames($c);$tx=new TransactionManager($c,$t);$registry=new ProviderRegistry($c,$t);for($attempt=0;$attempt<4;++$attempt){if(!$tx->begin_commerce_shared()){throw new RuntimeException('admission failed');}$result=$registry->bind('stripe_checkout',$kind,$identity,$claim,$now,'event'===$kind?str_repeat('f',64):null);if($result==='database_error'){$tx->rollback();usleep(20000);continue;}if(!$tx->commit()){throw new RuntimeException('commit failed');}return $result;}throw new RuntimeException('race did not converge');};};
 $r=race(['claim_a'=>$worker(1,'session','cs_race'),'claim_b'=>$worker(2,'session','cs_race')]);sort($r);check($r===['bound','conflict'],'competing identity claims converge to one owner and durable conflict');
 check(1===(int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE identity_value='cs_race'"),'unique key permits one exact identity');check('quarantined'===$db->get_var("SELECT binding_state FROM {$t->provider_identities()} WHERE identity_value='cs_race'"),'loser cannot overwrite winner ownership');
 $r=race(['response'=>$worker(1,'session','cs_same_claim'),'webhook'=>$worker(1,'session','cs_same_claim')]);check($r['response']==='bound'&&$r['webhook']==='bound','concurrent response and webhook evidence bind idempotently');
 $r=race(['delivery_a'=>$worker(1,'event','evt_duplicate'),'delivery_b'=>$worker(1,'event','evt_duplicate')]);check($r['delivery_a']==='bound'&&$r['delivery_b']==='bound','concurrent duplicate event registration converges');
 check(1===(int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE identity_value='evt_duplicate'"),'duplicate deliveries retain one durable event');
 // Future deletion uses the same gate; registry ownership remains independent of operational rows.
 $r=race([
  'association'=>function(wpdb $c)use($dir){$t=new TableNames($c);$tx=new TransactionManager($c,$t);$tx->begin_commerce_shared();$registry=new ProviderRegistry($c,$t);$claim=$registry->claim_by_id(1,true);file_put_contents($dir.'/associated','1');usleep(300000);$ok=$registry->bind('stripe_checkout','payment','pi_before_delete',1,gmdate('Y-m-d H:i:s'));$tx->commit();return $ok;},
  'delete_fixture'=>function(wpdb $c)use($dir){$end=microtime(true)+5;while(!is_file($dir.'/associated')&&microtime(true)<$end){usleep(1000);}$t=new TableNames($c);$tx=new TransactionManager($c,$t);$tx->begin_commerce_exclusive();sql($c,"UPDATE {$t->provider_claims()} SET disposition='deleted',tombstoned_at=UTC_TIMESTAMP() WHERE id=1");$tx->commit();return true;}
 ]);check($r['association']==='bound'&&$r['delete_fixture'],'test deletion serializes after admitted association');check('1'===(string)$db->get_var("SELECT claim_id FROM {$t->provider_identities()} WHERE identity_value='pi_before_delete'"),'tombstone retains provider ownership');unlink($dir.'/associated');

 // Exercise complete deliveries, including operational lock order and stock conversion.
 sql($db,"CREATE TABLE {$db->posts}(ID BIGINT PRIMARY KEY,post_type VARCHAR(32)) ENGINE=InnoDB");$created[]=$db->posts;
 sql($db,"CREATE TABLE {$db->postmeta}(meta_id BIGINT AUTO_INCREMENT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB");$created[]=$db->postmeta;
 sql($db,"INSERT INTO {$db->posts} VALUES(77,'collection_item')");sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(77,'_cpt2collection_stock_quantity','20')");
 $duplicate_order=payment_fixture($db);$duplicate_provider=new BoundaryProvider();[$svc,$duplicate_tx]=payment_service($db,$duplicate_provider);check($svc->create($duplicate_order,str_repeat('d',32))->is_success(),'concurrent webhook fixture activated');
 $reference=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$duplicate_order);$payload=paid_payload($reference,'evt_concurrent_complete');$hash=hash('sha256',json_encode($payload));
 $deliver=function(wpdb $c)use($payload,$hash){for($attempt=0;$attempt<4;++$attempt){$result=webhook_for($c)->handle($payload,$hash);if($result->is_accepted()){return true;}usleep(20000);}throw new RuntimeException($result->code());};
 $delivered=race(['complete_a'=>$deliver,'complete_b'=>$deliver]);check($delivered['complete_a']&&$delivered['complete_b'],'concurrent full webhook deliveries converge');
 check('19'===$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77"),'concurrent deliveries decrement physical stock exactly once');
 $early_order=payment_fixture($db);$early_provider=new BoundaryProvider();
 $early_provider->during_call=function($request)use($prefix){$other=new wpdb($prefix);$payload=paid_payload($request['client_reference_id'],'evt_before_response');$result=webhook_for($other)->handle($payload,hash('sha256',json_encode($payload)));check($result->is_accepted(),'webhook before provider response durably records reconciliation outcome');$other->close();};
 [$svc,$early_tx]=payment_service($db,$early_provider);$svc->create($early_order,str_repeat('e',32));
 check('pending'===$db->get_var('SELECT status FROM '.$t->orders().' WHERE id='.$early_order)&&'creating'===$db->get_var('SELECT status FROM '.$t->payment_sessions().' WHERE order_id='.$early_order),'late provider response cannot activate an attempt requiring reconciliation');
 check('attention'===$db->get_var("SELECT disposition FROM {$t->provider_identities()} WHERE identity_kind='event' AND identity_value='evt_before_response'"),'early webhook evidence remains durable');
 check('19'===$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77"),'creating-session webhook preserves existing reconciliation-only stock policy');
 $order=payment_fixture($db);$provider=new BoundaryProvider();
 $provider->during_call=function($request)use($prefix){
  $other=new wpdb($prefix);$t=new TableNames($other);$tx=new TransactionManager($other,$t);
  check(is_object((new ProviderRegistry($other,$t))->claim($request['client_reference_id'])),'another connection sees committed claim during network gap');
  check($tx->begin_commerce_exclusive(),'network gap does not retain admission');
  sql($other,"UPDATE {$t->commerce_epochs()} SET retired_at=UTC_TIMESTAMP() WHERE id=1");sql($other,"INSERT INTO {$t->commerce_epochs()} VALUES(2,REPEAT('8',32),UTC_TIMESTAMP(),NULL)");sql($other,"UPDATE {$t->commerce_control()} SET current_epoch_id=2 WHERE id=1");check($tx->commit(),'concurrent fixture retires epoch');$other->close();
 };
 [$service,$tx]=payment_service($db,$provider);$result=$service->create($order,str_repeat('g',32));
 check(!$result->is_success()&&$provider->calls===1,'retirement during network gap prevents stale activation');
 check('creating'===$db->get_var('SELECT status FROM '.$t->payment_sessions().' WHERE order_id='.$order),'old operational attempt remains unactivated');
 $reference=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$order);
 check(null===(new ProviderRegistry($db,$t))->identity('stripe_checkout','session','cs_gap_'.$reference),'synthetic noncanonical epoch switch grants no retained evidence authority; genuine completion covered by B network races');
 payment_fixture($db);$r=new \RodolfoRizzo\Cpt2Collection\ReservationRepository($db,$t);check($tx->begin_commerce_shared(),'stock availability admitted');
 $totals=$r->get_active_reserved_quantities_for_posts_for_update([77],gmdate('Y-m-d H:i:s'));
 check($totals===[77=>1],'retired active reservations do not consume current stock availability');$tx->rollback();
 foreach(['insert','before_commit','after_commit'] as $mode){
  $id=payment_fixture($db);$probe=new LostClaimDb($prefix);$p=new BoundaryProvider();[$svc,$claim_tx]=payment_service($probe,$p);$probe->loss=$mode;
  $outcome=$svc->create($id,str_repeat('k',32));check(!$outcome->is_success()&&$p->calls===0,"$mode: claim failure prevents provider call");
  if($mode!=='insert'){check($claim_tx->is_outcome_uncertain()&&!$probe->transaction_connection_usable(),"$mode: uncertain connection is poisoned");}
  $count=(int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims().' WHERE order_id='.$id.' AND epoch_id=2');
  $sessions=(int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_sessions().' WHERE order_id='.$id.' AND epoch_id=2');
  check($count===$sessions&&$count===($mode==='after_commit'?1:0),"$mode: claim and operational attempt commit or roll back together");$probe->close();
 }
}finally{foreach($created as $table){$db->query('DROP TABLE IF EXISTS '.$table);}$db->close();foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir);}
