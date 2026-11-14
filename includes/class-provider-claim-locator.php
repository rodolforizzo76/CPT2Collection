<?php
/** Fixed retained claim key supplied by an already-correlated internal caller. No authority. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ProviderClaimLocator {
	private function __construct( public readonly string $kind, public readonly array $values ) {}
	public static function reference( string $reference ): self { self::hex($reference,32); return new self('reference',[$reference]); }
	public static function id( int $id ): self { self::positive($id); return new self('id',[$id]); }
	public static function idempotency( string $key ): self {
		if (!preg_match('/\Ac2c_ps_[0-9a-f]{32}\z/',$key)) throw new \InvalidArgumentException('Invalid native request key.');
		return new self('idempotency',[$key]);
	}
	public static function attempt( string $installation, int $epoch, int $order, string $hash ): self {
		self::hex($installation,32); self::positive($epoch); self::positive($order); self::hex($hash,64);
		return new self('attempt',[$installation,$epoch,$order,$hash]);
	}
	public static function payment_session( int $id ): self { self::positive($id); return new self('payment_session',[$id]); }
	private static function positive(int $id):void { if($id<1) throw new \InvalidArgumentException('Invalid retained ID.'); }
	private static function hex(string $value,int $size):void { if(!preg_match('/\A[0-9a-f]{'.$size.'}\z/',$value)) throw new \InvalidArgumentException('Invalid retained key.'); }
}
