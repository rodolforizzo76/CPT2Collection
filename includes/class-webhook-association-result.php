<?php
/** Typed webhook-to-payment-session association result. @package CPT2Collection */
declare(strict_types=1); namespace RodolfoRizzo\Cpt2Collection; defined('ABSPATH')||exit;
final class WebhookAssociationResult {
	public const ASSOCIATED='associated', ALREADY_ASSOCIATED='already_associated', ORDER_DELETED='order_deleted', UNMATCHED='unmatched', IDENTITY_CONFLICT='identity_conflict', LIFECYCLE_CONFLICT='lifecycle_state_conflict', DATABASE_FAILURE='database_failure', OUTCOME_UNKNOWN='transaction_outcome_unknown';
	private string $outcome; private ?int $session_id; private ?int $order_id;
	private function __construct(string $o,?int $s,?int $r,public readonly ?OperationalInspectionResult $inspection=null){$this->outcome=$o;$this->session_id=$s;$this->order_id=$r;}
	public static function result(string $o,?int $s=null,?int $r=null,?OperationalInspectionResult $inspection=null):self{return new self($o,$s,$r,$inspection);}
	public function outcome():string{return $this->outcome;} public function session_id():?int{return $this->session_id;} public function order_id():?int{return $this->order_id;}
}
