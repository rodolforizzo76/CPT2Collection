<?php
/** Typed retained-only transaction outcome; never operational success. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class ProviderEvidenceResult {
 public function __construct(
  public readonly string $status,
  public readonly ?ProviderDiscoveryResult $truth=null,
  public readonly array $bindings=[],
  public readonly ?ProviderEvidenceRecovery $recovery=null,
  public readonly bool $mutation_possible=false
 ) {
  if($mutation_possible&&($status!=='transaction_outcome_unknown'||$recovery===null))throw new \InvalidArgumentException('Possible uncertain mutation requires recovery DATA.');
 }
}
