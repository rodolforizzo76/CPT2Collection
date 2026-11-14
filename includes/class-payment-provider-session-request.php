<?php
/** Immutable payment-provider session request. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use InvalidArgumentException;
defined( 'ABSPATH' ) || exit;
final class PaymentProviderSessionRequest {
	private string $reference; private string $idempotency_key; private string $currency; private int $amount_minor; private array $line_items; private string $reservation_deadline;
	/** @param PaymentProviderLineItem[] $line_items */
	public function __construct( string $reference, string $idempotency_key, string $currency, int $amount_minor, array $line_items, string $reservation_deadline, private array $configuration = array() ) {
		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $reference ) || '' === $idempotency_key || ! isset( Money::get_currencies()[ $currency ] ) || $amount_minor < 0 || array() === $line_items || '' === $reservation_deadline ) { throw new InvalidArgumentException( 'Provider session request is invalid.' ); }
		foreach ( $line_items as $item ) { if ( ! $item instanceof PaymentProviderLineItem ) { throw new InvalidArgumentException( 'Provider line items are invalid.' ); } }
		$this->reference = $reference; $this->idempotency_key = $idempotency_key; $this->currency = $currency; $this->amount_minor = $amount_minor; $this->line_items = array_values( $line_items ); $this->reservation_deadline = $reservation_deadline;
	}
	public function reference(): string { return $this->reference; } public function idempotency_key(): string { return $this->idempotency_key; }
	public function currency(): string { return $this->currency; } public function amount_minor(): int { return $this->amount_minor; }
	/** @return PaymentProviderLineItem[] */ public function line_items(): array { return $this->line_items; }
	public function reservation_deadline(): string { return $this->reservation_deadline; }
	public function configuration(): array { return $this->configuration; }
	public function snapshot(): string {
		return json_encode( array( 'version'=>2, 'reference'=>$this->reference, 'idempotency_key'=>$this->idempotency_key, 'currency'=>$this->currency, 'amount_minor'=>$this->amount_minor, 'deadline'=>$this->reservation_deadline, 'items'=>array_map( static fn(PaymentProviderLineItem $i)=>array('name'=>$i->name(),'unit_amount'=>$i->unit_amount_minor(),'quantity'=>$i->quantity()), $this->line_items ), 'configuration'=>$this->configuration ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}
	public static function from_snapshot( string $json ): self {
		$data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
		if(!is_array($data)||($data['version']??null)!==2){throw new InvalidArgumentException('Invalid provider request snapshot.');}
		$items=array_map(static fn(array $i)=>new PaymentProviderLineItem($i['name'],$i['unit_amount'],$i['quantity']),$data['items']);
		return new self($data['reference'],$data['idempotency_key'],$data['currency'],$data['amount_minor'],$items,$data['deadline'],$data['configuration']);
	}
}
