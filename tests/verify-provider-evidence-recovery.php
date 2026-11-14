<?php
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderEvidenceRouter,TransactionManager,TableNames};
foreach(['before','after'] as $fault){
 $prefix='c2c_a1_recover_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);$r=validation_start($db);$input=discovery_input($db,$f,'cs_uncertain');
  $db->fault=$fault;$out=(new ProviderEvidenceRouter($db,$t))->retain($input);
  check($out->status==='transaction_outcome_unknown'&&$out->recovery!==null,'lost COMMIT '.$fault.' produces typed recovery DATA');
  check(TransactionManager::retained_context_on($db)===null&&!$db->transaction_connection_usable(),'uncertainty revokes capability and poisons connection');
  check((new ProviderEvidenceRouter($db,$t))->recover($out->recovery)->status==='transaction_outcome_unknown','poisoned connection cannot recover');
  $db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);$fresh=(new ProviderEvidenceRouter($db,$t))->recover($out->recovery);
  check($fresh->status===($fault==='after'?'already_present':'reentry_required'),'fresh locked inspection discriminates durable whole outcome '.$fault);
  $actual=validation_service($db)->resume($r->operation_identity())->operation();check($actual->evidence_generation()===$r->evidence_generation()+($fault==='after'?1:0),'identity and active generation both or neither committed');
  $again=(new ProviderEvidenceRouter($db,$t))->retain($input);check($again->status===($fault==='after'?'duplicate':'retained'),'independent safe idempotent re-entry');
  check(validation_service($db)->resume($r->operation_identity())->operation()->evidence_generation()===$r->evidence_generation()+1,'no lost or repeated generation charge');
 }finally{baseline_cleanup($db);}
}
foreach(['START TRANSACTION','ROLLBACK'] as $failure)foreach([false,true] as $after){
 $db=new DiscoveryDb('c2c_a1_failure_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f);$db->bookkeeping_failure=$failure;$db->bookkeeping_after=$after;
  if($failure==='ROLLBACK')sql($db,'UPDATE '.$t->provider_claims().' SET request_version=1');
  $out=(new ProviderEvidenceRouter($db,$t))->retain($input);check($out->status==='transaction_outcome_unknown','lost '.$failure.' '.($after?'after':'before').' fails closed');check(!$db->transaction_connection_usable()&&TransactionManager::retained_context_on($db)===null,'unknown bookkeeping poisons and revokes');
  check($out->recovery===null&&!$out->mutation_possible,'pre-write bookkeeping uncertainty fabricates no mutation descriptor');
  $prefix=$db->prefix;$db->close();$db=new DiscoveryDb($prefix);
 }finally{baseline_cleanup($db);}
}
echo "All A1 recovery and bookkeeping checks passed.\n";
