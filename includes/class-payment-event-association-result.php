<?php
/** Typed immutable payment-event association result. @package CPT2Collection */
declare( strict_types=1 ); namespace RodolfoRizzo\Cpt2Collection; defined( 'ABSPATH' ) || exit;
final class PaymentEventAssociationResult {
	public const BOUND='bound'; public const ALREADY_BOUND='already_bound'; public const CONFLICT='conflict'; public const STATE_CONFLICT='state_conflict'; public const NOT_FOUND='not_found'; public const DATABASE_FAILURE='database_failure'; public const INVALID_INPUT='invalid_input';
	private string $outcome; private function __construct(string $outcome){$this->outcome=$outcome;} public static function result(string $outcome):self{return new self($outcome);} public function outcome():string{return $this->outcome;} public function is_success():bool{return in_array($this->outcome,array(self::BOUND,self::ALREADY_BOUND),true);}
}
