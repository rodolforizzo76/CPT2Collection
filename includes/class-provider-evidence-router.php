<?php
/** Owned retained evidence only. No runtime ingress calls this primitive in A1. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-provider-evidence-result.php';
require_once __DIR__.'/class-provider-evidence-recovery.php';
final class ProviderEvidenceRouter {
 public function __construct(private \wpdb $db,private TableNames $tables) {}
 private function identities(ProviderDiscoveryCandidate $i):array {
  $keys=$i->keys;ksort($keys,SORT_STRING);$rows=[];
  foreach($keys as $kind=>$value){
   $this->db->last_error='';
   $rows[$kind]=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->tables->provider_identities().' FORCE INDEX(identity) WHERE provider=%s AND identity_kind=%s AND identity_value=%s LIMIT 1',$i->provider,$kind,$value));
   if($this->db->last_error!=='')throw new \RuntimeException('identity inspection failed');
  }
  return $rows;
 }
 public function retain(ProviderDiscoveryCandidate $input):ProviderEvidenceResult {
  $tx=new TransactionManager($this->db,$this->tables);$truth=$tx->begin_retained_evidence($input);
  $c=TransactionManager::retained_context_on($this->db);
  if($c===null)return new ProviderEvidenceResult($tx->admission_error()?:($truth?->status??'database_error'),$truth);
  $ticket=null;$mutation_possible=false;
  try {
   $before=$this->identities($input);
   [$expected,$charges]=ProviderRegistry::retained_postconditions($c,$before);
   $ticket=new ProviderEvidenceRecovery($input,$before,$expected,$truth->active_reset,$charges);
   // Complete immutable descriptor exists BEFORE generation/identity DML can be dispatched.
   $mutation_possible=$charges>0;
   $bindings=(new ProviderRegistry($this->db,$this->tables))->bind_retained($c);
   if($bindings['status']==='database_error'){
	if($tx->rollback())return new ProviderEvidenceResult('database_error',$truth);
	return new ProviderEvidenceResult('transaction_outcome_unknown',$truth,[],$ticket,$mutation_possible);
   }
   if(!$ticket->matches($this->identities($input),true))throw new \RuntimeException('Retained postconditions differ.');
   if(!$tx->commit())return new ProviderEvidenceResult($tx->is_outcome_uncertain()?'transaction_outcome_unknown':'database_error',$truth,$bindings,$ticket,$tx->is_outcome_uncertain()&&$mutation_possible);
   return new ProviderEvidenceResult($bindings['status']==='conflict'?'conflict':($charges===0?'duplicate':'retained'),$truth,$bindings);
  } catch(\Throwable $e){
   if($tx->rollback())return new ProviderEvidenceResult('database_error',$truth);
   return new ProviderEvidenceResult('transaction_outcome_unknown',$truth,[],$ticket,$mutation_possible);
  }
 }
 /** Fresh locked inspection, no automatic replay. Ambiguity stays verification_required.
  * The capability re-entry independently proves ownership; identity before/after
  * discriminates safe idempotent re-entry. Generations are corroboration, not receipts.
  */
 public function recover(ProviderEvidenceRecovery $ticket):ProviderEvidenceResult {
  $tx=new TransactionManager($this->db,$this->tables);$truth=$tx->begin_retained_evidence($ticket->candidate,$ticket->domain);
  if(TransactionManager::retained_context_on($this->db)===null){$status=$tx->admission_error()?:'verification_required';return new ProviderEvidenceResult($status,$truth,[],$ticket,$status==='transaction_outcome_unknown'&&$ticket->charges>0);}
  try {
   $rows=$this->identities($ticket->candidate);$status='verification_required';
   if($ticket->matches($rows,true))$status='already_present';
   elseif($ticket->matches($rows,false))$status='reentry_required';
   if($ticket->domain!==null){
	// Exclusive control fences all generation writers; no new lock after provider rows.
	$this->db->last_error='';
	$row=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->tables->commerce_resets().' FORCE INDEX(source_epoch) WHERE installation_id=%s AND source_epoch_id=%d LIMIT 1',$ticket->domain->installation_id(),$ticket->domain->source_epoch_id()));
	if($this->db->last_error!==''||!is_object($row))throw new \RuntimeException('recovery domain unavailable');
	$r=CommerceResetRecord::from_row($row);
	$generation=$ticket->domain->evidence_generation();
	if($r->operation_identity()!==$ticket->domain->operation_identity()||($status==='already_present'&&($ticket->charges>PHP_INT_MAX-$generation||$r->evidence_generation()<$generation+$ticket->charges))||($status==='reentry_required'&&$r->evidence_generation()!==$generation))$status='verification_required';
   }
   if($tx->rollback())return new ProviderEvidenceResult($status,$truth);
   return new ProviderEvidenceResult('transaction_outcome_unknown',$truth,[],$ticket,$ticket->charges>0);
  } catch(\Throwable $e){
   if($tx->rollback())return new ProviderEvidenceResult('verification_required',$truth);
   return new ProviderEvidenceResult('transaction_outcome_unknown',$truth,[],$ticket,$ticket->charges>0);
  }
 }
}
