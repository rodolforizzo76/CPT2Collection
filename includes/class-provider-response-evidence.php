<?php
/** Synchronous correlated evidence orchestration; never operational authority. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-provider-evidence-router.php';
final class ProviderResponseEvidence {
 private \Closure $connections;
 /** Factory must preserve installation/table resolution and return a new private connection. */
 public function __construct(callable $connections, private bool $owns_connection=true) {
  $this->connections=\Closure::fromCallable($connections);
 }
 public function retain(ProviderDiscoveryCandidate $candidate):ProviderEvidenceResult {
  $db=null;
  try {
   $db=($this->connections)();
   if(!$db instanceof \wpdb||!$db instanceof CommerceConnection||!$db->transaction_connection_usable())return new ProviderEvidenceResult('database_error');
   $tables=new TableNames($db);
   $discovery=(new ProviderRetainedDiscovery($db,$tables))->discover($candidate);
   // Positive native discovery selects the retained path, never a failed normal admission.
   if(!in_array($discovery->status,['attributed','conflict'],true)||$discovery->owner===null)return new ProviderEvidenceResult($discovery->status,$discovery);
   $out=(new ProviderEvidenceRouter($db,$tables))->retain($candidate);
   if($out->status!=='transaction_outcome_unknown'||$out->recovery===null)return $out;
   $poisoned=$db;
   // Discard drains an uncommitted owner before any new connection attempts recovery.
   $db=null;$poisoned->close();
   $fresh=($this->connections)();
   if($fresh===$poisoned)return $out;
   $db=$fresh instanceof \wpdb?$fresh:null;
   if($db===null||!$db instanceof CommerceConnection||!$db->transaction_connection_usable())return $out;
   $recovered=(new ProviderEvidenceRouter($db,new TableNames($db)))->recover($out->recovery);
   if($recovered->status==='already_present'){
	$conflict=$out->bindings['status']??null;
	return new ProviderEvidenceResult($conflict==='conflict'||$recovered->truth?->status==='conflict'?'conflict':'retained',$recovered->truth);
   }
   // A proved rollback is safe but this call never retries binding. A later request
   // must obtain new native admission. Contradictory/unresolved recovery stays closed.
   return $recovered;
  } catch(\Throwable $error){
   return isset($out)&&$out->status==='transaction_outcome_unknown'?$out:new ProviderEvidenceResult('database_error');
  } finally {
   if($db instanceof \wpdb&&($this->owns_connection||!$db->transaction_connection_usable()))$db->close();
  }
 }
}
