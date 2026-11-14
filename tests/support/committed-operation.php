<?php
/** D1 exact native fixtures and a deliberately synthetic (non-financial) stage predicate. */
declare(strict_types=1);
require_once __DIR__.'/provider-retained-discovery.php';
use RodolfoRizzo\Cpt2Collection\{CommittedOperation,CommittedOperationAuthority,OperationalStageInspection,OperationalInspectionResult,OperationalRecoveryInspector,ProviderClaimRecord,PaymentSessionRepository,ProviderRegistry,PaymentProviderSessionRequest,TableNames,TransactionManager};
class D1Db extends DiscoveryDb {
 public int $poison_sql=0;public bool $closed=false;
 public function get_results(string $q):?array {if(!$this->transaction_connection_usable()){++$this->poison_sql;$this->last_error='poison';return null;}return parent::get_results($q);}
 public function query(string $q):int|false {if(!$this->transaction_connection_usable()){++$this->poison_sql;$this->last_error='poison';return false;}return parent::query($q);}
 public function close():void {if(!$this->closed){parent::close();$this->closed=true;}}
}
function d1_operation(wpdb $db,array $f):CommittedOperation {return CommittedOperation::native_request(discovery_claim($db,$f),gmdate('Y-m-d H:i:s'));}
function d1_receipt(array $f,string $body='body',bool $live=false,string $type='checkout.session.completed'): \RodolfoRizzo\Cpt2Collection\PaymentEventReceipt {
 $p=modern_payload($f['reference'],'evt_d1',$f['installation'],$f['epoch']);$p['livemode']=$live;$p['type']=$type;
 return (new \RodolfoRizzo\Cpt2Collection\StripeEventNormalizer())->normalize($p,hash('sha256',$body))->receipt();
}
function d1_classify(D1Db $db,CommittedOperation $op):\RodolfoRizzo\Cpt2Collection\ProviderDiscoveryResult {
 $db->capture=true;$db->captured=[];$before=baseline_snapshot($db);$out=(new CommittedOperationAuthority($db,new TableNames($db)))->classify($op);$db->capture=false;
 check($before===baseline_snapshot($db),'D1 classification is durable-state neutral');
 foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i',$q),'D1 classification attempts zero DML');
 return $out;
}
function d1_delete(wpdb $db,array $f):void {
 $t=new TableNames($db);check((new \RodolfoRizzo\Cpt2Collection\OrderDeletionService(new \RodolfoRizzo\Cpt2Collection\OrderDeletionRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new \RodolfoRizzo\Cpt2Collection\UtcClock()))->delete($f['order'])->code()==='deleted','genuine permanent deletion');
}
function d1_second_attempt(wpdb $db,array $f):array {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'second legitimate attempt admitted');$sessions=new PaymentSessionRepository($db,$t);$at=gmdate('Y-m-d H:i:s');check($sessions->mark_failed($f['session'],$at),'first attempt fails without replacing its claim');
 $s=$sessions->create_attempt(bin2hex(random_bytes(16)),$f['order'],'stripe_checkout',hash('sha256',random_bytes(16)),'EUR',200,$at);
 $original=json_decode(discovery_claim($db,$f)->request_snapshot(),true);$original['reference']=$s->reference();$original['idempotency_key']='c2c_ps_'.$s->reference();$request=PaymentProviderSessionRequest::from_snapshot(json_encode($original));
 $claim=(new ProviderRegistry($db,$t))->create_claim($s,$tx->commerce_context(),$request->snapshot(),'test',$at);check(is_object($claim)&&$tx->commit(),'second distinct native attempt commits on same original order');
 return array_merge($f,['session'=>$s->id(),'claim'=>(int)$claim->id,'reference'=>$s->reference()]);
}
class D1SyntheticStage implements OperationalStageInspection {
 public int $calls=0;
 public function __construct(public readonly string $before,public readonly string $after){}
 public function inspect(CommittedOperation $operation,wpdb $db,TableNames $tables):OperationalInspectionResult {
  ++$this->calls;
  check(TransactionManager::context_on($db)===null&&TransactionManager::retained_context_on($db)===null&&TransactionManager::reset_control_on($db)===null&&TransactionManager::reset_context_on($db)===null,'inspection has no operational/evidence/control/maintenance capability');
  check(!(new PaymentSessionRepository($db,$tables))->mark_failed($operation->claim->payment_session_id(),gmdate('Y-m-d H:i:s')),'inspection cannot invoke guarded operational writer');
  $db->last_error='';$o=$db->get_row('SELECT * FROM '.$tables->orders().' FORCE INDEX(PRIMARY) WHERE id='.$operation->claim->order_id().' LIMIT 1');
  if($db->last_error!=='')return new OperationalInspectionResult('database_error','synthetic_read_failed');
  if($o===null)return new OperationalInspectionResult('verification_required','synthetic_tuple_absent');
  if((int)$o->epoch_id!==$operation->claim->epoch_id())return new OperationalInspectionResult('conflict','synthetic_owner_changed');
  return new OperationalInspectionResult(match($o->updated_at){$this->after=>'logically_completed',$this->before=>'reentry_eligible',default=>'conflict'},'synthetic_timestamp_postcondition');
 }
}
function d1_inspect(D1Db $prior,CommittedOperation $op,D1SyntheticStage $stage,?callable $configure=null):array {
 $connections=[];$prefix=$prior->prefix;
 $out=(new OperationalRecoveryInspector(function()use(&$connections,$prefix,$configure){$db=new D1Db($prefix);$db->capture=true;if($configure)$configure($db);$connections[]=$db;return $db;}))->inspect($op,$stage,$prior);
 check($prior->closed&&$prior->poison_sql===0,'old connection discarded without poisoned SQL');
 foreach($connections as $c){foreach($c->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i',$q),'fresh inspection zero DML/replay');check($c->closed,'fresh inspection connection discarded');}
 check($out->transaction_provenance==='not_provable','next action never asserts exact COMMIT provenance');
 return [$out,$connections];
}
