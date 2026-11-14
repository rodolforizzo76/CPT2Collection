<?php
/** Administrative stock outcome; quantities are present only on confirmed success. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class AdminStockResult {
	private function __construct( private string $code, private ?int $stock = null ) {}
	public static function success( int $stock, string $code = 'stock_updated' ): self { return new self( $code, $stock ); }
	public static function failure( string $code ): self { return new self( $code ); }
	public function is_success(): bool { return null !== $this->stock; }
	public function code(): string { return $this->code; }
	public function stock(): ?int { return $this->stock; }
}
