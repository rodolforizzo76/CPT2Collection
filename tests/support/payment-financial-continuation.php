<?php
/** D3 native fixtures, production services and disposable SQL fault adapter. */
declare(strict_types=1);
require_once __DIR__.'/payment-event-continuation.php';
require_once __DIR__.'/provider-webhook.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,UtcClock,OrderRepository,PaymentSessionRepository,PaymentEventRepository,ReservationRepository,ConversionReservationSetValidator,StockConversionProcessor,LockedCatalogReader,PostTypes,SaleConversionService,PaymentExpirationService,PaymentEventOutcomeService,PaymentEventContinuation,CommittedOperation,StripeEventNormalizer,ProviderEvidenceRouter,ProviderRegistry};
class D3Db extends D2Db {
 public string $read_fault='';public $at_financial_read=null;
 public function get_results(string $q):?array {if($this->at_financial_read!==null&&str_contains($q,'FROM '.$this->prefix.'c2c_orders FORCE INDEX(PRIMARY)')&&TransactionManager::continuation_inspection_on($this)){$f=$this->at_financial_read;$this->at_financial_read=null;$f();}if($this->read_fault!==''&&str_contains($q,$this->read_fault)){$this->captured[]=$q;$this->last_error='D3 injected inspection read failure';return null;}return parent::get_results($q);}
}
function d3_services(D2Db $db,?array &$fresh=null,?callable $configure=null):array {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);$o=new OrderRepository($db,$t);$s=new PaymentSessionRepository($db,$t);$e=new PaymentEventRepository($db,$t);$r=new ReservationRepository($db,$t);$clock=new UtcClock();$v=new ConversionReservationSetValidator();
 $factory=$fresh===null?null:function()use($db,&$fresh,$configure){$n=new D3Db($db->prefix);$n->capture=true;if($configure!==null)$configure($n);$fresh[]=$n;return $n;};
 $guard=new PaymentEventContinuation($db,$t,$factory);$out=new PaymentEventOutcomeService($o,$s,$e,$tx,$clock,$guard);
 return [new SaleConversionService($o,$r,$s,$e,$tx,new StockConversionProcessor($r,new LockedCatalogReader($db,$tx),$tx,$v),$v,new PostTypes(),$clock,null,$guard),new PaymentExpirationService($o,$s,$e,$tx,$out,$clock,null,$guard),$tx,$e,$guard];
}
function d3_event(D2Db $db,array $f,string $type='checkout.session.completed',string $id='evt_d3',?callable $change=null):array {
 $p=webhook_payload($f,$id,$type);if($type==='checkout.session.expired'){$p['data']['object']['status']='expired';$p['data']['object']['payment_status']='unpaid';$p['data']['object']['payment_intent']=null;}
 if($change!==null)$change($p);$receipt=(new StripeEventNormalizer())->normalize($p,hash('sha256',json_encode($p)))->receipt();
 $op=CommittedOperation::authenticated_receipt(discovery_claim($db,$f),$receipt,gmdate('Y-m-d H:i:s'));$t=new TableNames($db);
 check((new ProviderEvidenceRouter($db,$t))->retain($op->candidate)->status==='retained','D3 original authenticated evidence retained');
 $tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared($op->expected_context()),'D3 receipt admitted');$e=new PaymentEventRepository($db,$t);$stored=$e->insert_complete_receipt($receipt,gmdate('Y-m-d H:i:s'));
 check($stored->event()!==null&&(new ProviderRegistry($db,$t))->bind_event($receipt,(new ProviderRegistry($db,$t))->claim_by_id($op->claim->id()),gmdate('Y-m-d H:i:s'))==='bound'&&$tx->commit(),'D3 exact original descriptor survives acknowledged receipt COMMIT');
 return [d2_associate($db,$stored->event(),$op),$op];
}
function d3_fixture(D2Db $db,string $type='checkout.session.completed'):array {validation_schema($db);sql($db,"INSERT INTO {$db->posts} VALUES(78,'collection_item')");sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(78,'_cpt2collection_stock_quantity','20')");$f=validation_order($db);webhook_activate($db,$f);[$e,$op]=d3_event($db,$f,$type);return [$f,$e,$op];}
function d3_run(D2Db $db,string $stage,array $f,$e,$op,?array &$fresh=null,?callable $configure=null):object {[$sale,$expire]=d3_services($db,$fresh,$configure);return match($stage){'conversion'=>$sale->convert($f['order'],$f['session'],$e->id(),$op),'expiration'=>$expire->expire($f['order'],$f['session'],$e->id(),$op),'processed_replay'=>$sale->probe_processed($e,$op)};}
function d3_stock(wpdb $db):array {return array_map(static fn($r)=>(int)$r->meta_value,$db->get_results('SELECT meta_value FROM '.$db->postmeta.' ORDER BY meta_id'));}
function d3_cleanup(D2Db $db):void {if($db->closed)$db=new D2Db($db->prefix);baseline_cleanup($db);}

function d3_assert_lock_order(D2Db $db,string $stage):void {
 $t=new TableNames($db);$locks=[];$first_write=null;
 foreach($db->captured as $n=>$q){
  if($first_write===null&&preg_match('/\A(?:UPDATE|INSERT|DELETE|REPLACE)\b/',$q))$first_write=$n;
  if(!str_contains($q,'FOR UPDATE')&&!str_contains($q,'LOCK IN SHARE MODE'))continue;
  foreach(['commerce_control','commerce_epochs','orders','payment_sessions','payment_events','reservations','provider_claims','provider_identities'] as $m)if(str_contains($q,'FROM '.$t->$m().' '))$locks[$m][]=$n;
  if(str_contains($q,'SELECT ID FROM '.$db->posts.' '))$locks['products'][]=$n;
 }
 foreach(['commerce_control','commerce_epochs','orders','payment_sessions','payment_events','provider_claims','provider_identities'] as $m)check(isset($locks[$m]),'D3 takes required '.$m.' lock');
 $chain=['commerce_control','commerce_epochs','orders','payment_sessions','payment_events'];
 if($stage==='conversion')$chain=array_merge($chain,['products','reservations']);
 $chain=array_merge($chain,['provider_claims','provider_identities']);
 for($i=1;$i<count($chain);++$i)check(max(array_filter($locks[$chain[$i-1]],static fn($n)=>$n<$first_write))<min($locks[$chain[$i]]),'D3 lock order '.$chain[$i-1].' before '.$chain[$i]);
 check(max(array_filter($locks['provider_identities'],static fn($n)=>$n<$first_write))<$first_write,'all exact ownership locks precede first financial DML');
}
