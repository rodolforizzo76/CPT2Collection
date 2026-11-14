<?php
/** All physical Session/receipt PKs are covered; only coherently scoped modern evidence is materialized. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceResetProviderCoverage {
	public function __construct(private \wpdb $db,private TableNames $tables,private CommerceResetRecord $operation){
	}

	/** Read-only original-owner derivation; the registry also verifies the exact stored row. */
	public static function origin(\wpdb $db,TableNames $tables,CommerceResetRecord $operation,string $stage,object $row):?object{
		$v=new CommerceResetRetainedEvidence($db,$tables,$operation);
		$check=static function(bool $ok,string $code):void{
			if(!$ok)throw new \InvalidArgumentException('coverage_'.$code);
		};
		$payload=$stage==='e'?json_decode($row->normalized_payload??'',true):null;
		if($stage==='s'){
			$epoch=$row->epoch_id;
		} else{
			$e=is_array($payload)&&CommerceBaseline::identity($payload['epoch_identity']??null)?$v->point('commerce_epochs',$db->prepare('identity=%s',$payload['epoch_identity'])):null;
			$epoch=$e?->id??$row->epoch_id;
			$check($epoch!==null,'event_scope');
		}
		if(!$v->modern_epoch($epoch))return null;
		$claim=$v->point('provider_claims',$db->prepare('reference=%s',$row->local_reference??$row->reference??''));
		$check($claim!==null&&$v->claim($claim),'owner');
		$check((string)$claim->epoch_id===(string)$epoch&&$claim->provider===$row->provider,'owner');
		$request=PaymentProviderSessionRequest::from_snapshot($claim->request_snapshot);
		$observed_at=$stage==='s'?$row->created_at:$row->received_at;
		$check(ProviderClaimRecord::valid_date($observed_at),'observation_date');
		if($stage==='s'){
			try{
				PaymentSessionRecord::from_row($row);
			} catch(\Throwable $e){
				throw new \InvalidArgumentException('coverage_session_shape');
			}
			$check((string)$claim->payment_session_id===(string)$row->id&&(string)$claim->order_id===(string)$row->order_id&&$claim->reference===$row->reference&&$claim->creation_key_hash===$row->creation_key_hash&&$claim->disposition!=='deleted','session_owner');
			$order=$v->id('orders',$row->order_id);
			$check($order!==null&&(string)$order->epoch_id===(string)$row->epoch_id&&$order->currency===$row->currency&&(string)$order->total_minor===(string)$row->amount_minor,'session_order');
			$check($request->currency()===$row->currency&&$request->amount_minor()===CommerceResetRetainedEvidence::integer($row->amount_minor),'session_request');
			if($row->reconciliation_event_id!==null){
				$event=$v->id('payment_events',$row->reconciliation_event_id);
				$check($event!==null&&(string)$event->payment_session_id===(string)$row->id&&$event->provider===$row->provider&&$event->local_reference===$row->reference,'reconciliation');
			}
		} else{
			try{
				PaymentEventRecord::from_row($row);
			} catch(\Throwable $e){
				throw new \InvalidArgumentException('coverage_event_shape');
			}
			$check(is_array($payload)&&($payload['schema']??null)===2&&($payload['integration']??null)==='cpt2collection'&&($payload['identity_invalid']??true)===false&&($payload['installation_id']??null)===$claim->installation_id&&($payload['epoch_identity']??null)===$claim->epoch_identity&&$row->local_reference!==null&&(($payload['client_reference_id']??null)===$row->local_reference||($payload['metadata_payment_session_reference']??null)===$row->local_reference),'event_payload');
			$check(in_array((string)$row->livemode,['0','1'],true)&&$claim->environment===((int)$row->livemode?'live':'test'),'event_environment');
			$check(in_array($row->ownership_state,['bound','unresolved','quarantined'],true)&&($row->ownership_state!=='unresolved'||$row->epoch_id===null&&$row->payment_session_id===null)&&($row->ownership_state!=='bound'||(string)$row->epoch_id===(string)$claim->epoch_id),'event_ownership');
			if($row->payment_session_id!==null){
				$session=$v->id('payment_sessions',$row->payment_session_id);
				$check($session!==null&&(string)$session->id===(string)$claim->payment_session_id&&$session->reference===$claim->reference&&$session->provider===$row->provider&&(string)$session->epoch_id===(string)$row->epoch_id&&($row->currency===null||$session->currency===$row->currency)&&($row->amount_minor===null||(string)$session->amount_minor===(string)$row->amount_minor),'event_session');
			}
		}
		foreach(['session'=>'provider_session_id','payment'=>'provider_payment_id','event'=>'provider_event_id'] as $field)if($row->$field!==null)$check(preg_match('/\A[\x21-\x7e]{1,255}\z/D',$row->$field)===1,'provider_value');
		return $claim;
	}

	public function batch(int $size,string $now):array{
		$owned=TransactionManager::reset_context_on($this->db);
		if($owned===null||!$owned->operation()->equals($this->operation)||$size<1||$size>1000||!in_array($this->operation->checkpoint(),['s1_s','s1_e'],true))throw new \RuntimeException('Unowned coverage.');
		$stage=substr($this->operation->checkpoint(),3);
		$table=$stage==='s'?'payment_sessions':'payment_events';
		$v=new CommerceResetRetainedEvidence($this->db,$this->tables,$this->operation);
		$cursor=$this->operation->cursor();
		$rows=$v->rows($this->db->prepare('SELECT * FROM '.$this->tables->$table().' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT %d FOR UPDATE',$cursor,$size));
		foreach($rows as $row){
			$current=TransactionManager::reset_context_on($this->db)->operation();
			if(!(new ProviderRegistry($this->db,$this->tables))->cover_reset_record($current,$stage,$row,$now))throw new \RuntimeException('Coverage mutation failed.');
			$cursor=CommerceResetRetainedEvidence::integer($row->id,1);
		}
		return $rows?[$this->operation->checkpoint(),$cursor]:[$stage==='s'?'s1_e':'s1_v_v_carts',0];
	}
}
