<?php
/** A1.1 real-SQL mutation/inspection/acknowledgement boundaries and result invariants. */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderEvidenceRouter,ProviderEvidenceResult,ProviderEvidenceRecovery,ProviderDiscoveryCandidate,ProviderRegistry,TransactionManager,TableNames};

final class RecoveryFaultDb extends DiscoveryDb {
 public string $stage='';public bool $execute=false;public string $rollback_loss='none';
 public int $identity_writes=0,$generation_writes=0;public array $trace=[];
 public function query(string $q):int|false {
  $this->trace[]=$q;
  if(!$this->transaction_connection_usable()){$this->last_error='poisoned test connection';return false;}
  if($q==='ROLLBACK'&&$this->rollback_loss!=='none'){
   if($this->rollback_loss==='after')parent::query($q);
   $this->last_error='lost rollback acknowledgement';return false;
  }
  $identity=str_starts_with($q,'INSERT INTO `'.$this->prefix.'c2c_provider_identities`');
  $generation=str_starts_with($q,'UPDATE '.$this->prefix.'c2c_commerce_resets SET evidence_generation=');
  $fail=($this->stage==='identity'&&$identity)||($this->stage==='generation'&&$generation)||($this->stage==='commit'&&$q==='COMMIT')||($this->stage==='connection'&&$identity);
  $out=(!$fail||$this->execute)?parent::query($q):false;
  if($out!==false&&$this->stage!==''){if($identity)++$this->identity_writes;if($generation)++$this->generation_writes;}
  if($fail){if($this->stage==='connection')$this->poison_transaction();$this->last_error='injected execution/acknowledgement failure';return false;}
  return $out;
 }
 public function get_results(string $q):?array {
  $this->trace[]=$q;
  if(!$this->transaction_connection_usable()){$this->last_error='poisoned test connection';return null;}
  $identity=str_starts_with($q,'SELECT * FROM '.$this->prefix.'c2c_provider_identities FORCE INDEX(identity)')&&!str_ends_with($q,'FOR UPDATE');
  $owned=TransactionManager::retained_context_on($this)!==null;
  if($owned&&(($this->stage==='descriptor'&&$identity)||($this->stage==='inspection'&&$identity&&$this->identity_writes===2)||($this->stage==='generation_read'&&$this->generation_writes===1&&str_starts_with($q,'SELECT * FROM '.$this->prefix.'c2c_commerce_resets FORCE INDEX(source_epoch)')))){
   $this->last_error='injected owned inspection failure';return null;
  }
  return parent::get_results($q);
 }
}

foreach(['ready','resetting'] as $state){
 $stages=['descriptor','identity','inspection','commit','connection'];if($state==='resetting')$stages=array_merge($stages,['generation','generation_read']);
 foreach($stages as $stage)foreach([false,true] as $execute){
  $losses=$stage==='commit'||$stage==='connection'?['none']:['none','before','after'];
  foreach($losses as $loss){
   $prefix='c2c_a11_fault_'.bin2hex(random_bytes(5)).'_';$db=new RecoveryFaultDb($prefix);
   try{
	validation_schema($db);$f=validation_order($db);$t=new TableNames($db);$r=$state==='resetting'?validation_start($db):null;
	$input=ProviderDiscoveryCandidate::response(discovery_claim($db,$f),'cs_complete','pi_complete',gmdate('Y-m-d H:i:s'));
	$before=baseline_snapshot($db);$stock=deletion_stock($db);
	$db->stage=$stage;$db->execute=$execute;$db->rollback_loss=$loss;$db->trace=[];
	$out=(new ProviderEvidenceRouter($db,$t))->retain($input);
	$unknown=$loss!=='none'||$stage==='commit'||$stage==='connection';$label="$state $stage executed=".(int)$execute." rollback=$loss";
	check($out->status===($unknown?'transaction_outcome_unknown':'database_error'),$label.' exact typed outcome');
	check($db->identity_writes===($stage==='inspection'||$stage==='commit'?2:($stage==='identity'||$stage==='connection'?($execute?1:0):0)),$label.' actual identity execution boundary');
	if($unknown){
	 check(!$db->transaction_connection_usable()&&TransactionManager::retained_context_on($db)===null,$label.' authority revoked');
	 if($stage==='descriptor')check($out->recovery===null&&!$out->mutation_possible,$label.' definitely pre-write uncertainty has no fabricated ticket');
	 else check($out->recovery!==null&&$out->mutation_possible&&$out->recovery->charges===2,$label.' complete descriptor predates possible generation/identity DML');
	 $ticket=$out->recovery;$db->trace=[];
	 if($ticket!==null){$again=(new ProviderEvidenceRouter($db,$t))->recover($ticket);check($again->recovery===$ticket&&$again->mutation_possible&&$db->trace===[],$label.' no SQL/replay on poisoned connection');}
	 $db->close();$db=new RecoveryFaultDb($prefix);$t=new TableNames($db);
	 $committed=$stage==='commit'&&$execute;
	 if($ticket!==null){
	  $db->trace=[];$recovered=(new ProviderEvidenceRouter($db,$t))->recover($ticket);
	  check($recovered->status===($committed?'already_present':'reentry_required'),$label.' fresh recovery resolves whole durable outcome');
	  check(!array_filter($db->trace,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),$label.' recovery is read-only');
	 }
	 if($committed){
	  $expected=unserialize($before);$actual=unserialize(baseline_snapshot($db));unset($expected['provider_identities'],$actual['provider_identities'],$expected['commerce_resets'],$actual['commerce_resets']);
	  check(serialize($expected)===serialize($actual)&&$stock===deletion_stock($db),$label.' committed evidence never grants business effects');
	  if($r!==null)check(validation_service($db)->resume($r->operation_identity())->operation()->evidence_generation()===$r->evidence_generation()+2,$label.' exact two-key generation effect');
	 }else check($before===baseline_snapshot($db)&&$stock===deletion_stock($db),$label.' rollback/discard restores evidence and generations atomically');
	}else check($out->recovery===null&&!$out->mutation_possible&&$before===baseline_snapshot($db),$label.' confirmed rollback is known failure');
   }finally{baseline_cleanup($db);}
  }
 }
}

// Deep immutability and structurally invalid uncertain-result combinations.
$db=new DiscoveryDb('c2c_a11_invariants_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f);$db->fault='after';$out=(new ProviderEvidenceRouter($db,$t))->retain($input);$ticket=$out->recovery;
 try{new ProviderEvidenceResult('transaction_outcome_unknown',mutation_possible:true);check(false,'ticketless possible mutation rejected');}catch(InvalidArgumentException $e){check(true,'ticketless possible mutation rejected');}
 try{new ProviderEvidenceResult('retained',recovery:$ticket,mutation_possible:true);check(false,'uncertain flag requires unknown status');}catch(InvalidArgumentException $e){check(true,'uncertain flag requires unknown status');}
 check(!(new ProviderEvidenceResult('transaction_outcome_unknown'))->mutation_possible,'pre-write unknown is a truthful ticketless result');
 try{$ticket->after['session']['environment']='live';check(false,'ticket facts immutable');}catch(Error $e){check(true,'ticket facts immutable');}
 $row=(object)$ticket->after['session'];$copy=new ProviderEvidenceRecovery($input,['session'=>null],['session'=>$row],null,1);$row->environment='live';check($copy->after['session']['environment']==='test','ticket copies mutable source objects to scalar facts');
 $prefix=$db->prefix;$db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);
 $db->bookkeeping_failure='ROLLBACK';$out=(new ProviderEvidenceRouter($db,$t))->recover($ticket);
 check($out->status==='transaction_outcome_unknown'&&$out->recovery===$ticket&&$out->mutation_possible,'uncertain read-only recovery preserves original outstanding mutation descriptor');
 $db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);check((new ProviderEvidenceRouter($db,$t))->recover($ticket)->status==='already_present','descriptor survives failed recovery without replay');
 foreach(['environment','first_seen_at','disposition','observed_epoch_id','claim_id','payload_sha256','event_type','conflict_code','binding_state'] as $field){
  $original=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='cs_discovery'");
  $value=match($field){'environment'=>'live','first_seen_at'=>'2030-01-01 00:00:00','disposition'=>'processed','observed_epoch_id'=>999,'claim_id'=>999,'payload_sha256'=>str_repeat('f',64),'event_type'=>'changed','conflict_code'=>'changed','binding_state'=>'quarantined'};
  sql($db,$db->prepare('UPDATE '.$t->provider_identities().' SET '.$field.'=%s WHERE id=%d',$value,(int)$original->id));$snapshot=baseline_snapshot($db);$out=(new ProviderEvidenceRouter($db,$t))->recover($ticket);
  check(!in_array($out->status,['already_present','reentry_required'],true)&&$snapshot===baseline_snapshot($db),'contradictory '.$field.' cannot prove a recovery outcome or cause writes');
  sql($db,$db->prepare('UPDATE '.$t->provider_identities().' SET '.$field.'='.($original->$field===null?'NULL':'%s').' WHERE id=%d',...($original->$field===null?[(int)$original->id]:[$original->$field,(int)$original->id])));
 }
}finally{baseline_cleanup($db);}

foreach(['adoption','enrichment','quarantine','duplicate'] as $effect)foreach(['before','after'] as $fault){
 $prefix='c2c_a11_effect_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f,'cs_effect');
  if($effect==='quarantine')$input=discovery_webhook($f,event:'evt_effect',session:'cs_effect');
  check((new ProviderEvidenceRouter($db,$t))->retain($input)->status==='retained','effect fixture retained');
  $id=(int)$db->get_var("SELECT id FROM {$t->provider_identities()} WHERE identity_value='cs_effect'");
  if($effect==='adoption')sql($db,"UPDATE {$t->provider_identities()} SET claim_id=NULL,binding_state='unresolved',environment=NULL,disposition='processed' WHERE id=$id");
  if($effect==='enrichment')sql($db,"UPDATE {$t->provider_identities()} SET environment=NULL,disposition='processed' WHERE id=$id");
  if($effect==='quarantine')$input=discovery_webhook($f,event:'evt_effect',session:'cs_effect',hash:'changed');
  $r=validation_start($db);$before=baseline_snapshot($db);$db->fault=$fault;$out=(new ProviderEvidenceRouter($db,$t))->retain($input);$ticket=$out->recovery;
  check($out->status==='transaction_outcome_unknown'&&$ticket!==null&&$out->mutation_possible===($effect!=='duplicate'),'effect descriptor and truthful possible-change flag '.$effect.' '.$fault);
  check($ticket->charges===($effect==='duplicate'?0:1),'exact changed-key effect '.$effect);
  $db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);$snapshot=baseline_snapshot($db);$result=(new ProviderEvidenceRouter($db,$t))->recover($ticket);
  check($result->status===($fault==='after'||$effect==='duplicate'?'already_present':'reentry_required'),'semantic effect recovered '.$effect.' '.$fault);
  check($snapshot===baseline_snapshot($db),'effect recovery never mutates or replays');
  check(validation_service($db)->resume($r->operation_identity())->operation()->evidence_generation()===$r->evidence_generation()+($fault==='after'?$ticket->charges:0),'effect/generation whole outcome '.$effect);
  if($fault==='before')check($before===baseline_snapshot($db),'existing-row before-state and exact ID preserved');
  if($fault==='after'&&in_array($effect,['adoption','enrichment'],true))check($db->get_var('SELECT disposition FROM '.$t->provider_identities().' WHERE id='.$id)==='processed','terminal disposition preserved');
 }finally{baseline_cleanup($db);}
}

$db=new DiscoveryDb('c2c_a11_domain_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);$f=validation_order($db);$r=validation_start($db);$input=discovery_input($db,$f,'cs_domain');$db->fault='after';$out=(new ProviderEvidenceRouter($db,$t))->retain($input);$ticket=$out->recovery;$prefix=$db->prefix;$db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);
 sql($db,'UPDATE '.$t->commerce_resets().' SET evidence_generation=0 WHERE id='.$r->id());$snapshot=baseline_snapshot($db);
 check((new ProviderEvidenceRouter($db,$t))->recover($ticket)->status==='verification_required'&&$snapshot===baseline_snapshot($db),'exact evidence with insufficient original-domain charge cannot prove commit');
 sql($db,'UPDATE '.$t->commerce_resets().' SET evidence_generation=1 WHERE id='.$r->id());
 check((new ProviderEvidenceRouter($db,$t))->retain(discovery_input($db,$f,'cs_other_domain'))->status==='retained','independent legitimate generation advance');
 check((new ProviderEvidenceRouter($db,$t))->recover($ticket)->status==='already_present','sufficient original-domain generation remains corroboration after unrelated advance');
 $db->fault='before';$out=(new ProviderEvidenceRouter($db,$t))->retain(discovery_input($db,$f,'cs_rolled_back_domain'));$ticket=$out->recovery;$db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);
 check((new ProviderEvidenceRouter($db,$t))->retain(discovery_input($db,$f,'cs_further_domain'))->status==='retained','subsequent legitimate evidence advance');$snapshot=baseline_snapshot($db);
 check((new ProviderEvidenceRouter($db,$t))->recover($ticket)->status==='verification_required'&&$snapshot===baseline_snapshot($db),'before-state plus changed original generation cannot prove rollback');
}finally{baseline_cleanup($db);}
echo "All A1.1 uncertainty boundaries and immutable recovery invariants passed.\n";
