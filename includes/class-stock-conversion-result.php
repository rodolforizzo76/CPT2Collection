<?php
/** Stock conversion primitive outcome. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class StockConversionResult {
	private bool $success;
	private string $code;
	/** @var array<int,int> */
	private array $stocks;
	/** @var array<int,string> Reservation ID to locked expected status. */
	private array $reservations;
	/** @param array<int,int> $stocks @param array<int,string> $reservations */
	private function __construct( bool $success, string $code, array $stocks, array $reservations ) { $this->success = $success; $this->code = $code; $this->stocks = $stocks; $this->reservations = $reservations; }
	/** @param array<int,int> $stocks @param array<int,string> $reservations */
	public static function success( array $stocks, array $reservations ): self { return new self( true, 'stock_decremented', $stocks, $reservations ); }
	public static function failure( string $code ): self { return new self( false, $code, array(), array() ); }
	public function is_success(): bool { return $this->success; }
	public function code(): string { return $this->code; }
	/** Raw post IDs/new values for post-commit cache invalidation by the caller. @return array<int,int> */
	public function stocks(): array { return $this->stocks; }
	/** Exact structurally validated reservation IDs and statuses read under lock. @return array<int,string> */
	public function reservations(): array { return $this->reservations; }
}
