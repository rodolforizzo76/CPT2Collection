<?php
/** A1.1 positive regression for the eight B prerequisite post-write uncertainty cases.
 * Recovery only: no runtime ingress or automatic replay.
 */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderDiscoveryCandidate,ProviderEvidenceRouter,TableNames,TransactionManager};

final class ResponseRecoveryAuditDb extends DiscoveryDb {
 public string $audit_fault='';
 public bool $rollback_executed=false;
 public int $successful_identity_writes=0;
 public int $generation_writes=0;
 public function query(string $q):int|false {
  if($this->audit_fault!==''&&$q==='ROLLBACK'){
   if($this->rollback_executed)parent::query($q);
   $this->last_error='injected rollback acknowledgement loss';return false;
  }
  $identity_insert=str_starts_with($q,'INSERT INTO `'.$this->prefix.'c2c_provider_identities`');
  if($this->audit_fault==='second_write'&&$identity_insert&&str_contains($q,"'cs_b_recovery_gap'")){
   $this->last_error='injected second identity write failure';return false;
  }
  $result=parent::query($q);
  if($this->audit_fault!==''&&$result!==false){
   if($identity_insert)++$this->successful_identity_writes;
   if(str_starts_with($q,'UPDATE '.$this->prefix.'c2c_commerce_resets SET evidence_generation='))++$this->generation_writes;
  }
  return $result;
 }
 public function get_results(string $q):?array {
  if($this->audit_fault==='after_inspection'&&$this->successful_identity_writes===2&&str_starts_with($q,'SELECT * FROM '.$this->prefix.'c2c_provider_identities FORCE INDEX(identity)')&&!str_ends_with($q,'FOR UPDATE')){
   $this->last_error='injected post-write identity inspection failure';return null;
  }
  return parent::get_results($q);
 }
}

foreach(['ready','resetting'] as $state)foreach(['second_write','after_inspection'] as $fault)foreach([false,true] as $after){
 $prefix='c2c_b_prereq_'.bin2hex(random_bytes(5)).'_';$db=new ResponseRecoveryAuditDb($prefix);
 try {
  validation_schema($db);$f=validation_order($db);$t=new TableNames($db);
  $r=$state==='resetting'?validation_start($db):null;
  $candidate=ProviderDiscoveryCandidate::response(discovery_claim($db,$f),'cs_b_recovery_gap','pi_b_recovery_gap',gmdate('Y-m-d H:i:s'));
  $before=baseline_snapshot($db);$stock=deletion_stock($db);
  $db->audit_fault=$fault;$db->rollback_executed=$after;
  $out=(new ProviderEvidenceRouter($db,$t))->retain($candidate);
  $label="$state $fault rollback ".($after?'after':'before').' server execution';
  check($db->successful_identity_writes===($fault==='second_write'?1:2),$label.' reached actual identity writes');
  check($db->generation_writes===($state==='resetting'?2:0),$label.' active generation reservations observed');
  check($out->status==='transaction_outcome_unknown',$label.' retains typed uncertainty');
  check($out->truth?->owner?->claim_id===$f['claim'],$label.' native ownership truth retained');
  check($out->recovery!==null&&$out->mutation_possible,$label.' complete pre-write recovery descriptor returned');
  $ticket=$out->recovery;
  check($ticket->charges===2&&$ticket->domain?->operation_identity()===$r?->operation_identity(),$label.' expected whole effect and original generation domain captured');
  $db->captured=[];$db->capture=true;
  $poisoned=(new ProviderEvidenceRouter($db,$t))->recover($ticket);$db->capture=false;
  check($poisoned->status==='transaction_outcome_unknown'&&$poisoned->recovery===$ticket&&$db->captured===[],$label.' poisoned connection cannot inspect and preserves ticket');
  check(TransactionManager::retained_context_on($db)===null&&!$db->transaction_connection_usable(),$label.' authority revoked and connection poisoned');
  // No alternate route/replay or operational continuation. Discard before fresh inspection.
  $db->close();$db=new ResponseRecoveryAuditDb($prefix);$t=new TableNames($db);
  $db->capture=true;$db->captured=[];$recovered=(new ProviderEvidenceRouter($db,$t))->recover($ticket);$db->capture=false;
  check($recovered->status==='reentry_required',$label.' fresh locked recovery proves rolled back');
  check(!array_filter($db->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),$label.' fresh recovery never attempts DML');
  check($before===baseline_snapshot($db)&&$stock===deletion_stock($db),$label.' discard drains uncommitted evidence/generation with complete state preserved');
  check($db->successful_identity_writes===0,$label.' fresh audit performed no replay');
 }finally{baseline_cleanup($db);}
}
echo "A1.1 closes eight B recovery prerequisite cases; B ingress remains NOT IMPLEMENTED.\n";
