<?php
/** Immutable admission information, not a surviving transaction lock. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class CommerceContext {
	public function __construct( private string $installation_id, private int $epoch_id, private string $epoch_identity ) {}
	public function installation_id(): string { return $this->installation_id; }
	public function epoch_id(): int { return $this->epoch_id; }
	public function epoch_identity(): string { return $this->epoch_identity; }
	public function equals( self $other ): bool {
		return $this->installation_id === $other->installation_id && $this->epoch_id === $other->epoch_id && $this->epoch_identity === $other->epoch_identity;
	}
}
