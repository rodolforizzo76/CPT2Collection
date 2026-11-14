<?php
/** Typed pure Stripe event-normalization result. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class StripeEventNormalizationResult {
	public const PAYMENT_SUCCESS = 'payment_success'; public const LIFECYCLE = 'lifecycle'; public const EXPIRATION = 'expiration'; public const UNSUPPORTED = 'unsupported'; public const DETERMINISTIC_ATTENTION = 'deterministic_attention'; public const INVALID_EVENT = 'invalid_event';
	private string $outcome; private string $code; private ?PaymentEventReceipt $receipt;
	private function __construct( string $outcome, string $code, ?PaymentEventReceipt $receipt ) { $this->outcome=$outcome; $this->code=$code; $this->receipt=$receipt; }
	public static function result( string $outcome, string $code, ?PaymentEventReceipt $receipt ): self { return new self( $outcome, $code, $receipt ); }
	public function outcome():string{return $this->outcome;} public function code():string{return $this->code;} public function receipt():?PaymentEventReceipt{return $this->receipt;} public function has_receipt():bool{return null!==$this->receipt;}
}
