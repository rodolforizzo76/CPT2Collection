<?php
/** Result of durable payment-event receipt insertion. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class PaymentEventInsertResult {
	public const INSERTED='inserted'; public const DUPLICATE='duplicate'; public const INVALID_INPUT='invalid_input'; public const DATABASE_FAILURE='database_failure';
	private string $outcome; private ?PaymentEventRecord $event;
	private function __construct(string $outcome,?PaymentEventRecord $event){$this->outcome=$outcome;$this->event=$event;}
	public static function inserted(PaymentEventRecord $event):self{return new self(self::INSERTED,$event);} public static function duplicate(?PaymentEventRecord $event):self{return new self(self::DUPLICATE,$event);} public static function invalid_input():self{return new self(self::INVALID_INPUT,null);} public static function database_failure():self{return new self(self::DATABASE_FAILURE,null);}
	public function outcome():string{return $this->outcome;} public function event():?PaymentEventRecord{return $this->event;} public function is_inserted():bool{return self::INSERTED===$this->outcome;} public function is_duplicate():bool{return self::DUPLICATE===$this->outcome;}
}
