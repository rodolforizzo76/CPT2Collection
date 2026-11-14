<?php
/** Immutable local sale-conversion outcome. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class SaleConversionResult {
	public readonly ?OperationalInspectionResult $inspection;
	public static function uncertain(?int $o,?int $s,?int $e,?OperationalInspectionResult $inspection): self {
		$r=new self(false,false,'transaction_outcome_unknown',$o,$s,$e,[],true,$inspection);return $r;
	}

	private bool $confirmed_rollback=false;
	public function has_confirmed_rollback():bool{return $this->confirmed_rollback;}
	private bool $success; private bool $idempotent; private string $code; private ?int $order_id; private ?int $payment_session_id; private ?int $payment_event_id; private array $stocks; private bool $requires_attention;
	/** @param array<int,int> $stocks */
	private function __construct( bool $success, bool $idempotent, string $code, ?int $order_id, ?int $payment_session_id, ?int $payment_event_id, array $stocks, bool $requires_attention, ?OperationalInspectionResult $inspection=null ) { $this->inspection=$inspection; $this->success=$success; $this->idempotent=$idempotent; $this->code=$code; $this->order_id=$order_id; $this->payment_session_id=$payment_session_id; $this->payment_event_id=$payment_event_id; $this->stocks=$stocks; $this->requires_attention=$requires_attention; }
	/** @param array<int,int> $stocks */ public static function success( string $code, int $order_id, int $session_id, int $event_id, array $stocks=array(), bool $idempotent=false ): self { return new self( true, $idempotent, $code, $order_id, $session_id, $event_id, $stocks, false ); }
	public static function failure( string $code, ?int $order_id, ?int $session_id, ?int $event_id, bool $attention=false, bool $confirmed_rollback=false ): self { $r=new self( false, false, $code, $order_id, $session_id, $event_id, array(), $attention );$r->confirmed_rollback=$confirmed_rollback;return $r; }
	public function is_success(): bool{return $this->success;} public function is_idempotent():bool{return $this->idempotent;} public function code():string{return $this->code;} public function order_id():?int{return $this->order_id;} public function payment_session_id():?int{return $this->payment_session_id;} public function payment_event_id():?int{return $this->payment_event_id;} /** @return array<int,int> */ public function stocks():array{return $this->stocks;} public function requires_attention():bool{return $this->requires_attention;}
}
