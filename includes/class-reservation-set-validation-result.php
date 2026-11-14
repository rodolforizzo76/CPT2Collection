<?php
/** Exact reservation-set validation outcome. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ReservationSetValidationResult {
	private bool $valid; private string $code; private ?string $deadline;
	private function __construct( bool $valid, string $code, ?string $deadline ) { $this->valid = $valid; $this->code = $code; $this->deadline = $deadline; }
	public static function valid( string $deadline ): self { return new self( true, 'reservation_active', $deadline ); }
	public static function invalid( string $code ): self { return new self( false, $code, null ); }
	public function is_valid(): bool { return $this->valid; } public function code(): string { return $this->code; } public function deadline(): ?string { return $this->deadline; }
}
