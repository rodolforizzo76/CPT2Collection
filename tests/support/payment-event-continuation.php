<?php
/** D2 production Event fixtures and dispatch-boundary SQL faults, disposable MariaDB. */
declare(strict_types=1);
require_once __DIR__.'/committed-operation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,PaymentEventReceipt,CommittedOperation,ProviderEvidenceRouter,PaymentEventRepository,PaymentEventContinuation,PaymentEventAssociationService,PaymentEventOutcomeService,OrderRepository,PaymentSessionRepository,UtcClock,PaymentEventRecord};
class D2Db extends D1Db {
 public string $fault='';public bool $after=false;public bool $wrote=false;public bool $faulted=false;public int $write_number=0;public int $fail_write=0;public $after_close=null;
 public function query(string $q):int|false {
  if(preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)&&++$this->write_number===$this->fail_write){$this->last_error='D2 injected write failure';return false;}
  if($this->fault!==''&&!$this->faulted&&(($q===$this->fault&&($q!=='ROLLBACK'||$this->wrote))||($this->fault==='WRITE'&&$this->wrote&&str_starts_with($q,'UPDATE ')))){
   $this->faulted=true;if($this->after)parent::query($q);$this->last_error='D2 injected loss';return false;
  }
  if(preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q))$this->wrote=true;
  return parent::query($q);
 }
 public function close():void {if(!$this->closed){$this->poison_transaction();TransactionManager::connection_discarded_on($this);parent::close();if($this->after_close!==null){$f=$this->after_close;$this->after_close=null;$f();}}}
}
function d2_receipt(PaymentEventRecord $e):PaymentEventReceipt {return new PaymentEventReceipt($e->provider(),$e->provider_event_id(),$e->event_type(),$e->provider_created_at(),$e->livemode(),$e->provider_session_id(),$e->local_reference(),$e->provider_payment_id(),$e->currency(),$e->amount_minor(),$e->payment_status(),$e->session_status(),$e->payload_sha256(),$e->normalized_payload());}
function d2_fixture(D2Db $db):array {
 validation_schema($db);$f=validation_order($db);$e=validation_receipt($db,$f,'evt_d2');$op=CommittedOperation::authenticated_receipt(discovery_claim($db,$f),d2_receipt($e),gmdate('Y-m-d H:i:s'));
 $out=(new ProviderEvidenceRouter($db,new TableNames($db)))->retain($op->candidate);check($out->status==='retained','authenticated receipt evidence commits before D2');
 return [$f,$e,$op];
}
function d2_services(D2Db $db,?array &$fresh=null,?callable $configure=null):array {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);$o=new OrderRepository($db,$t);$s=new PaymentSessionRepository($db,$t);$e=new PaymentEventRepository($db,$t);$c=new UtcClock();
 $factory=$fresh===null?null:function()use($db,&$fresh,$configure){$n=new D2Db($db->prefix);$n->capture=true;if($configure!==null)$configure($n);$fresh[]=$n;return $n;};
 $guard=new PaymentEventContinuation($db,$t,$factory);
 return [new PaymentEventAssociationService($o,$s,$e,$tx,$c,false,null,$guard),new PaymentEventOutcomeService($o,$s,$e,$tx,$c,$guard),$tx,$e,$guard];
}
function d2_no_dml(D2Db $db):void {foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'D2 refusal/inspection attempts zero DML');}
function d2_event(D2Db $db,PaymentEventRecord $e):PaymentEventRecord {return (new PaymentEventRepository($db,new TableNames($db)))->find($e->id());}
function d2_associate(D2Db $db,PaymentEventRecord $e,CommittedOperation $op):PaymentEventRecord {[$a]=d2_services($db);check($a->associate($e,$op)->outcome()==='associated','production exact association commits');return d2_event($db,$e);}
function d2_processing(D2Db $db,PaymentEventRecord $e):PaymentEventRecord {$tx=new TransactionManager($db,new TableNames($db));check($tx->begin_commerce_shared()&&(new PaymentEventRepository($db,new TableNames($db)))->mark_processing($e->id(),$e->processing_status(),gmdate('Y-m-d H:i:s'))&&$tx->commit(),'fixture committed anomalous processing');return d2_event($db,$e);}
function d2_outcome(PaymentEventOutcomeService $o,string $stage,PaymentEventRecord $e,?CommittedOperation $op):RodolfoRizzo\Cpt2Collection\WebhookHttpDisposition {
 return match($stage){'ignored'=>$o->ignore($e,'d2_ignored',$op),'attention'=>$o->attention($e,'d2_attention',operation:$op),'quarantine_processing'=>$o->quarantine_processing($e,operation:$op),'integrity_conflict'=>$o->integrity_conflict($e,$op)};
}
