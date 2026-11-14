<?php
/** Immutable provider line-item snapshot. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use InvalidArgumentException;
defined( 'ABSPATH' ) || exit;
final class PaymentProviderLineItem {
	private string $name; private int $unit_amount_minor; private int $quantity;
	public function __construct( string $name, int $unit_amount_minor, int $quantity ) { if ( '' === trim( $name ) || $unit_amount_minor < 0 || $quantity < 1 ) { throw new InvalidArgumentException( 'Provider line item is invalid.' ); } $this->name = $name; $this->unit_amount_minor = $unit_amount_minor; $this->quantity = $quantity; }
	public function name(): string { return $this->name; } public function unit_amount_minor(): int { return $this->unit_amount_minor; } public function quantity(): int { return $this->quantity; }
}
