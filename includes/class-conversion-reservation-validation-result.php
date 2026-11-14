<?php
/** Conversion reservation-set validation outcome. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class ConversionReservationValidationResult {
	private bool $valid;
	private string $code;
	/** @var array<int,int> */
	private array $quantities;
	/** @var int[] */
	private array $reservation_ids;

	/** @param array<int,int> $quantities @param int[] $reservation_ids */
	private function __construct( bool $valid, string $code, array $quantities, array $reservation_ids ) {
		$this->valid           = $valid;
		$this->code            = $code;
		$this->quantities      = $quantities;
		$this->reservation_ids = $reservation_ids;
	}

	/** @param array<int,int> $quantities @param int[] $reservation_ids */
	public static function valid( array $quantities, array $reservation_ids ): self { return new self( true, 'reservation_convertible', $quantities, $reservation_ids ); }
	public static function invalid( string $code ): self { return new self( false, $code, array(), array() ); }
	public function is_valid(): bool { return $this->valid; }
	public function code(): string { return $this->code; }
	/** @return array<int,int> */
	public function quantities(): array { return $this->quantities; }
	/** @return int[] */
	public function reservation_ids(): array { return $this->reservation_ids; }
}
