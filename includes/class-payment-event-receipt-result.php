<?php
/** Typed complete payment-event receipt result. @package CPT2Collection */
declare( strict_types=1 ); namespace RodolfoRizzo\Cpt2Collection; defined( 'ABSPATH' ) || exit;
final class PaymentEventReceiptResult {
	public const INSERTED='inserted'; public const DUPLICATE_COHERENT='duplicate_coherent'; public const DUPLICATE_INTEGRITY_CONFLICT='duplicate_integrity_conflict'; public const INVALID_INPUT='invalid_input'; public const DATABASE_FAILURE='database_failure';
	private string $outcome; private ?PaymentEventRecord $event;
	private function __construct(string $outcome,?PaymentEventRecord $event){$this->outcome=$outcome;$this->event=$event;}
	public static function result(string $outcome,?PaymentEventRecord $event=null):self{return new self($outcome,$event);} public function outcome():string{return $this->outcome;} public function event():?PaymentEventRecord{return $this->event;}
}
