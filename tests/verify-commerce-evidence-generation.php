<?php
/** v14 binding/generation atomicity, preservation, snapshots, failure and recovery. */
declare(strict_types=1);
require __DIR__.'/support/historical-evidence.php';
use RodolfoRizzo\Cpt2Collection\{ProviderRegistry,TransactionManager,TableNames,CommerceResetRecord,CommerceResetRepository,CommerceEvidenceContext};
class GenerationFailureDb extends wpdb {
 public string $fail=''; public bool $throw=false; public bool $lose_commit=false;
 public function query(string $query):int|false {
  if($this->fail!=='' && str_contains($query,$this->fail)){ $this->last_error='injected statement failure'; if($this->throw)throw new RuntimeException($this->last_error); return false; }
  $r=parent::query($query);if($this->lose_commit && $query==='COMMIT'){$this->last_error='lost acknowledgement';return false;}return $r;
 }
}
$prefix='c2c_a0gen_'.bin2hex(random_bytes(5)).'_';$db=new GenerationFailureDb($prefix);$t=new TableNames($db);
function generation(wpdb $db):int { return (int)$db->get_var('SELECT evidence_generation FROM '.$db->prefix.'c2c_commerce_resets WHERE id=1'); }
function generation_preserved(wpdb $db):string { $rows=unserialize(maintenance_snapshot($db));unset($rows['provider_identities']);foreach($rows['commerce_resets'] as $r){unset($r->evidence_generation);}return serialize($rows); }
try {
 $entry=evidence_fixture($db);$tx=new TransactionManager($db,$t);$registry=new ProviderRegistry($db,$t);$at='2026-01-02 00:00:00';
 $bind=fn($ctx,$kind,$value,$env='test',$hash=null,$type=null)=>$registry->bind_historical($ctx,'stripe_checkout',$kind,$value,$at,$env,$hash,$type);
 $before=generation_preserved($db);
 check($entry->operation()->evidence_generation()===0 && $entry->operation()->validation_generation()===null,'new v14 baseline is zero/NULL');
 $raw=$db->get_row('SELECT * FROM '.$t->commerce_resets());
 foreach(['evidence_generation','validation_generation'] as $field){foreach([-1,'01','18446744073709551615',1.5,'bad'] as $v){$r=clone $raw;$r->$field=$v;$reject=false;try{CommerceResetRecord::from_row($r);}catch(InvalidArgumentException $e){$reject=true;}check($reject,$field.' rejects malformed/range value');}$r=clone $raw;unset($r->$field);$reject=false;try{CommerceResetRecord::from_row($r);}catch(InvalidArgumentException $e){$reject=true;}check($reject,$field.' required even when nullable');$r=clone $raw;$r->$field=1;check(!CommerceResetRecord::from_row($r)->equals($entry->operation()),$field.' participates in exact snapshot');}
 check($tx->begin_historical_evidence($entry),'evidence admitted');
 check(false===(new CommerceResetRepository($db,$t))->increment_evidence_generation($entry),'naked increment denied outside binding unit');
 check($bind($entry,'session','cs_gen')==='bound' && generation($db)===1,'insert advances exactly once');
 check($tx->evidence_context()->operation()->evidence_generation()===1,'live owner advances its snapshot');
 check($bind($entry,'session','cs_gen')==='bound' && generation($db)===1,'entry snapshot supports no-op in same owner');
 check($bind($entry,'event','evt_gen','test',str_repeat('a',64),'probe')==='bound' && generation($db)===2,'second mutation same transaction advances once');
 check($bind($tx->evidence_context(),'event','evt_gen','test',str_repeat('b',64),'probe')==='conflict' && generation($db)===3,'new quarantine advances once using live context');
 check($bind($entry,'event','evt_gen','test',str_repeat('b',64),'probe')==='conflict' && generation($db)===3,'unchanged quarantine does not advance');
 check($bind($entry,'session','cs_mismatch','live')==='conflict' && generation($db)===3,'nonwriting environment conflict does not advance');
 check($tx->commit(),'binding unit commits');
 check(!$tx->begin_historical_evidence($entry),'independent stale generation snapshot rejected');
 check($before===generation_preserved($db),'only identity and evidence-generation state changed');
 // Existing bindable native unresolved row, including three missing evidence fields.
 sql($db,"INSERT INTO {$t->provider_identities()} (provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,disposition) VALUES('stripe_checkout','event','evt_enrich',NULL,'unresolved','native',1,'2026-01-01','pending')");
 $entry=evidence_context($db);check($tx->begin_historical_evidence($entry),'enrichment admitted');
 check($bind($entry,'event','evt_enrich','test',str_repeat('c',64),'probe')==='bound' && generation($db)===4,'owner plus hash/type/environment advances once, not per column');
 check($tx->rollback() && generation($db)===3 && $registry->identity('stripe_checkout','event','evt_enrich')->claim_id===null,'rollback restores generation and all identity columns');
 check($tx->begin_historical_evidence($entry) && $bind($entry,'event','evt_enrich','test',str_repeat('c',64),'probe')==='bound' && $tx->commit(),'enrichment commits together');
 check(generation($db)===4,'committed enrichment generation');
 // A later statement failure must prohibit committing prior binding work too.
 foreach(['SET evidence_generation','INSERT INTO','UPDATE `'.$t->provider_identities().'`'] as $failure){foreach([false,true] as $throw){
  $entry=evidence_context($db);$g=generation($db);$identities=serialize($db->get_results('SELECT * FROM '.$t->provider_identities().' ORDER BY id'));
  check($tx->begin_historical_evidence($entry),'failure unit admitted');
  check($bind($entry,'payment','pi_prior_'.$g.'_'.(int)$throw)==='bound','earlier change in same transaction');
  $db->fail=$failure;$db->throw=$throw;
  $r=str_starts_with($failure,'UPDATE')?$bind($entry,'event','evt_enrich','test',str_repeat('d',64),'probe'):$bind($entry,'session','cs_failure');
  check($r==='database_error','failed reservation/identity write reports database error');$db->fail='';$db->throw=false;
  check(null===$tx->evidence_context() && !$tx->commit() && !$tx->is_active() && !$tx->is_outcome_uncertain(),'failed binding permits only confirmed rollback, never partial commit');
  check(generation($db)===$g && $identities===serialize($db->get_results('SELECT * FROM '.$t->provider_identities().' ORDER BY id')),'generation and every identity change rolled back');
 }}
 sql($db,'UPDATE '.$t->commerce_resets().' SET evidence_generation='.PHP_INT_MAX);
 $entry=evidence_context($db);check($tx->begin_historical_evidence($entry),'maximum generation admits inspection/no-op');
 check($bind($entry,'session','cs_gen')==='bound' && generation($db)===PHP_INT_MAX,'no-op at capacity is permitted');
 check($bind($entry,'session','cs_overflow')==='database_error' && !$tx->commit(),'capacity fails without wrap or untracked write');
 check(generation($db)===PHP_INT_MAX && $registry->identity('stripe_checkout','session','cs_overflow')===null,'overflow preserves evidence');
 sql($db,"UPDATE {$t->commerce_resets()} SET evidence_generation=4,validation_generation=2,status='blocked',error_code='integrity_conflict',checkpoint='fixture',`cursor`=8,revision=7");
 // Exercise infrastructure lifecycle writes with nonzero generation fields (no validation semantics).
 sql($db,"UPDATE {$t->commerce_resets()} SET status='running',error_code=NULL");
 // A1 owns a captured validating pass; metadata cannot alter or certify it.
 $operation=evidence_context($db)->operation();check($tx->begin_reset_maintenance($operation),'captured A1 metadata guard admitted');
 check(false===(new CommerceResetRepository($db,$t))->progress($operation,'validating','fixture',8,null,$at)&&$tx->rollback(),'generic lifecycle cannot overwrite captured validation');
 // Explicit future-phase fixture, never a production A1 certification.
 sql($db,"UPDATE {$t->commerce_resets()} SET phase='retiring_claims',checkpoint=NULL,`cursor`=0");
 foreach([['retiring_claims','fixture',8,null],['retiring_claims',null,0,null],['retiring_claims',null,0,'integrity_conflict']] as [$phase,$checkpoint,$cursor,$error]){
  $operation=evidence_context($db)->operation();check($tx->begin_reset_maintenance($operation),'generation-preserving lifecycle admission');
  $next=(new CommerceResetRepository($db,$t))->progress($operation,$phase,$checkpoint,$cursor,$error,$at);
  if(null===$error){check(false===$next&&$tx->rollback(),'generic B progress cannot bypass generation revalidation');}
  else{check($next instanceof CommerceResetRecord && $next->evidence_generation()===4 && $next->validation_generation()===2 && $tx->commit(),'blocking preserves both generation fields');}
 }
 $before=generation_preserved($db);$entry=evidence_context($db);
 check($tx->begin_historical_evidence($entry) && $bind($entry,'session','cs_blocked')==='bound' && $tx->commit(),'blocked operation retains evidence admission');
 check(generation($db)===5 && $before===generation_preserved($db),'blocked metadata and captured validation generation preserved');
 // Actual server COMMIT with lost acknowledgement, followed by independent recovery.
 $entry=evidence_context($db);check($tx->begin_historical_evidence($entry) && $bind($entry,'session','cs_commit_loss')==='bound','lost-ack fixture writes generation and evidence');
 $db->lose_commit=true;check(!$tx->commit() && $tx->is_outcome_uncertain() && !$tx->begin_historical_evidence($entry),'unacknowledged commit poisons owner');
 $fresh=new wpdb($prefix);check(generation($fresh)===6 && is_object((new ProviderRegistry($fresh,new TableNames($fresh)))->identity('stripe_checkout','session','cs_commit_loss')),'fresh connection sees both committed effects');$fresh->close();
} finally {try{$db->close();}catch(Throwable $e){}maintenance_cleanup(new wpdb($prefix));}
echo "All evidence-generation checks passed.\n";
