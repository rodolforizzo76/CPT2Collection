<?php
/** Real Stripe SDK signatures and private retained connections; no provider HTTP. */
declare(strict_types=1);
require_once __DIR__.'/provider-response.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use RodolfoRizzo\Cpt2Collection\{ProviderWebhookEvidence,StripeWebhookRestController,StripeWebhookVerifier,TableNames};
class WebhookFaultDb extends ResponseFaultDb {
 public function get_results(string $q):?array {
  if($this->mode==='after_inspection'&&$this->identity_writes===3&&str_starts_with($q,'SELECT * FROM '.$this->prefix.'c2c_provider_identities FORCE INDEX(identity)')&&!str_ends_with($q,'FOR UPDATE')){$this->captured[]=$q;$this->last_error='post-write read failure';return null;}
  return parent::get_results($q);
 }
}
class WP_REST_Request {
 public function __construct(private string $body,private string $signature){}
 public function get_body():string{return $this->body;}
 public function get_header(string $key):string{return $key==='stripe-signature'?$this->signature:'';}
}
class WP_REST_Response {
 public function __construct(private array $data,private int $status){}
 public function header(string $key,string $value):void{}
 public function get_status():int{return $this->status;}
 public function get_data():array{return $this->data;}
}
function webhook_payload(array $f,string $event='evt_c',string $type='checkout.session.completed'):array {
 $p=modern_payload($f['reference'],$event,$f['installation'],$f['epoch']);$p['type']=$type;if($type==='checkout.session.expired')$p['data']['object']['status']='expired';$p['data']['object']['amount_total']=count($f['items'])*100;
 return $p;
}
function webhook_controller(wpdb $db,array &$connections,?Closure $configure=null):StripeWebhookRestController {
 $factory=function()use($db,&$connections,$configure){$c=new WebhookFaultDb($db->prefix);$c->capture=true;$connections[]=$c;if($configure!==null)$configure($c,count($connections));return $c;};
 return new StripeWebhookRestController(new StripeWebhookVerifier('whsec_'.str_repeat('fixture',3)),webhook_for($db,new ProviderWebhookEvidence($factory)));
}
function webhook_deliver(StripeWebhookRestController $controller,array|string $payload,string $signature='valid'):WP_REST_Response {
 $body=is_array($payload)?json_encode($payload,JSON_UNESCAPED_SLASHES):$payload;$at=$signature==='stale'?time()-1000:time();
 $header='t='.$at.',v1='.hash_hmac('sha256',$at.'.'.$body,'whsec_'.str_repeat('fixture',3));
 if(in_array($signature,['missing','invalid','malformed'],true))$header=match($signature){'missing'=>'','invalid'=>'t='.$at.',v1='.str_repeat('0',64),default=>'not-a-signature'};
 return $controller->receive(new WP_REST_Request($body,$header));
}
function webhook_no_operational(wpdb $db,array $connections):void {
 response_no_operational($connections);
 foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'historical webhook operational connection attempts zero DML');
}
function webhook_deleted(wpdb $db,array $f):void {
 $t=new TableNames($db);
 $out=(new \RodolfoRizzo\Cpt2Collection\OrderDeletionService(new \RodolfoRizzo\Cpt2Collection\OrderDeletionRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\OrderDeletionRegistry($db,$t),new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t),new \RodolfoRizzo\Cpt2Collection\UtcClock()))->delete($f['order']);check($out->code()==='deleted','permanent deletion fixture');
}

function webhook_activate(wpdb $db,array $f):void {
 $connections=[];$provider=new class implements \RodolfoRizzo\Cpt2Collection\PaymentProviderInterface {
 public function name():string{return 'stripe_checkout';}
 public function create_session(\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest $r):\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult{return new \RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult('cs_gap_'.$r->reference(),'https://example.test/pay',$r->reservation_deadline(),'pi_'.$r->reference());}
 };
 check(response_resume(response_service($db,$provider,$connections),response_captured($db,$f))->is_success(),'current native Session active before authenticated webhook');
}
