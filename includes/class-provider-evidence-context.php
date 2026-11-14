<?php
/** Exact candidate/owner capability DATA; only TransactionManager's live object authorizes. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class ProviderEvidenceContext {
 public function __construct(
  public readonly ProviderDiscoveryCandidate $candidate,
  public readonly ProviderClaimRecord $claim,
  public readonly ProviderDiscoveryResult $truth
 ) {}
}
