<?php
/** Provider-neutral webhook HTTP result. @package CPT2Collection */
declare(strict_types=1); namespace RodolfoRizzo\Cpt2Collection; defined('ABSPATH')||exit;
final class WebhookHttpDisposition {
	private int $status; private string $code;
	private function __construct(int $status,string $code,public readonly ?OperationalInspectionResult $inspection=null, public readonly bool $paid_committed=false){$this->status=$status;$this->code=$code;}
	/** Inspection DATA never dispatches a writer, replay or next workflow stage. */
	public static function inspected(OperationalInspectionResult $inspection):self{return new self(500,'transaction_outcome_unknown',$inspection);}
	public static function accepted(string $code='accepted'):self{return new self(200,$code);}
	public static function bad_request(string $code='invalid_request'):self{return new self(400,$code);}
	public static function failure(string $code='delivery_failed',bool $unavailable=false):self{return new self($unavailable?503:500,$code);}
	public function with_paid_commit(): self { return new self( $this->status, $this->code, $this->inspection, true ); }
	public function status():int{return $this->status;} public function code():string{return $this->code;} public function is_accepted():bool{return $this->status>=200&&$this->status<300;}
}
