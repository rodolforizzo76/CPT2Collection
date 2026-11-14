<?php
/** Real SQL and production reset histories; capture every attempted discovery statement. */
declare(strict_types=1);
require_once __DIR__.'/reset-final-switch.php';
use RodolfoRizzo\Cpt2Collection\{ProviderDiscoveryCandidate,ProviderRetainedDiscovery,ProviderClaimRecord,ProviderClaimLocator,ProviderDiscoveryResult,TableNames,TransactionManager};
class DiscoveryDb extends FinalSwitchDb {
 public array $captured=[];public bool $capture=false;public string $bookkeeping_failure='';public bool $bookkeeping_after=false;public $barrier=null;
 public function get_results(string $q):?array {
  if($this->capture){$this->captured[]=$q;if($this->barrier!==null){$f=$this->barrier;$this->barrier=null;$f($q);}}
  return parent::get_results($q);
 }
 public function query(string $q):int|false {
  if($this->capture)$this->captured[]=$q;
  if($this->bookkeeping_failure!==''&&str_starts_with($q,$this->bookkeeping_failure)){if($this->bookkeeping_after)parent::query($q);$this->last_error='injected snapshot bookkeeping loss';return false;}
  return parent::query($q);
 }
}
function discovery_claim(wpdb $db,array $f):ProviderClaimRecord {$t=new TableNames($db);return ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']));}
function discovery_input(wpdb $db,array $f,string $session='cs_discovery'):ProviderDiscoveryCandidate {return ProviderDiscoveryCandidate::response(discovery_claim($db,$f),$session,null,gmdate('Y-m-d H:i:s'));}
function discovery_run(DiscoveryDb $db,ProviderDiscoveryCandidate $input):ProviderDiscoveryResult {
 $t=new TableNames($db);$before=baseline_snapshot($db).final_preserved($db);$db->captured=[];$db->capture=true;
 $out=(new ProviderRetainedDiscovery($db,$t))->discover($input);$db->capture=false;
 check($before===baseline_snapshot($db).final_preserved($db),'A0 zero durable changes including stock/products/configuration/generations');
 foreach($db->captured as $q){check(!preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$q),'A0 never attempts durable write');foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)check(!str_contains($q,$t->$m()),'A0 no '.$m.' operational SQL');}
 check(\RodolfoRizzo\Cpt2Collection\TransactionManager::context_on($db)===null&&\RodolfoRizzo\Cpt2Collection\TransactionManager::reset_context_on($db)===null&&\RodolfoRizzo\Cpt2Collection\TransactionManager::evidence_context_on($db)===null,'result returns after owned snapshot ended without authority');
 return $out;
}
function discovery_bind(wpdb $db,array $f,string $kind,string $value,?string $hash=null,?string $type=null):void {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'fixture normal identity authority');check((new \RodolfoRizzo\Cpt2Collection\ProviderRegistry($db,$t))->bind('stripe_checkout',$kind,$value,$f['claim'],gmdate('Y-m-d H:i:s'),$hash,$type,'test')==='bound'&&$tx->commit(),'fixture identity committed');
}
function discovery_webhook(array $f,string $event='evt_discovery',string $session='cs_discovery',?string $payment=null,string $hash='body',string $type='checkout.session.completed',bool $live=false):ProviderDiscoveryCandidate {
 $p=modern_payload($f['reference'],$event,$f['installation'],$f['epoch']);$p['type']=$type;$p['livemode']=$live;$p['data']['object']['id']=$session;$p['data']['object']['payment_intent']=$payment;
 $receipt=(new \RodolfoRizzo\Cpt2Collection\StripeEventNormalizer())->normalize($p,hash('sha256',$hash))->receipt();
 return ProviderDiscoveryCandidate::verified_receipt($receipt,gmdate('Y-m-d H:i:s'));
}
function discovery_finish(wpdb $db,\RodolfoRizzo\Cpt2Collection\CommerceResetRecord $r):\RodolfoRizzo\Cpt2Collection\CommerceResetRecord {
 if($r->phase()==='validating')$r=validation_pass($db,$r,100);
 if(in_array($r->phase(),['retiring_claims','terminalizing_events'],true))$r=retirement_pass($db,$r,100);
 $r=deletion_pass($db,deletion_retained($db,$r,100),100);$out=validation_service($db)->finalize($r);check($out->code()==='completed','production reset completes in A0 fixture');return $out->operation();
}
