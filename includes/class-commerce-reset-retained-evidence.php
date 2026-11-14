<?php
/** Durable-only provider predicates. Operational business relationships belong to pre-seal A1. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceResetRetainedEvidence {
	private array $baseline;
	public function __construct(private \wpdb $db,private TableNames $tables,private CommerceResetRecord $operation){
		$b=(new CommerceBaseline($db,$tables))->read('FOR UPDATE');
		self::check($b!==null&&$b['status']==='completed'&&$b['installation_id']===$operation->installation_id(),'baseline');
		$this->baseline=$b;
	}

	public static function check(bool $ok,string $reason):void{
		if(!$ok)throw new \InvalidArgumentException('retained_'.$reason);
	}

	public static function integer(mixed $v,int $min=0):int{
		$n=is_int($v)?$v:(is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/',$v)?filter_var($v,FILTER_VALIDATE_INT):false);
		self::check(is_int($n)&&$n>=$min,'integer');
		return $n;
	}

	public function rows(string $q):array{
		$this->db->last_error='';
		$r=$this->db->get_results($q);
		if($this->db->last_error!==''||!is_array($r))throw new \RuntimeException('Retained read failed.');
		return $r;
	}

	public function point(string $table,string $where):?object{
		$r=$this->rows('SELECT * FROM '.$this->tables->$table().' WHERE '.$where.' LIMIT 2');
		self::check(count($r)<=1,'duplicate_point');
		return $r[0]??null;
	}

	public function id(string $table,mixed $id):?object{
		return $this->point($table,$this->db->prepare('id=%d',self::integer($id,1)));
	}

	/** Known pre-baseline epochs are excluded; absence/malformed scope is not exclusion. */
	public function modern_epoch(mixed $id,?string $identity=null):bool{
		$e=$this->id('commerce_epochs',$id);
		self::check($e!==null&&CommerceBaseline::identity($e->identity)&&($identity===null||$identity===$e->identity),'epoch');
		self::check(ProviderClaimRecord::valid_date($e->created_at)&&($e->retired_at===null||ProviderClaimRecord::valid_date($e->retired_at)&&$e->retired_at>=$e->created_at),'epoch_dates');
		$n=self::integer($e->id,1);
		self::check($n<=$this->operation->source_epoch_id()&&($n===$this->operation->source_epoch_id()?$e->retired_at===null:$e->retired_at!==null),'epoch_lifecycle');
		return $n>=$this->baseline['first_epoch_id'];
	}

	public function claim(object $row):bool{
		if(!$this->modern_epoch($row->epoch_id,$row->epoch_identity))return false;
		self::check($row->installation_id===$this->operation->installation_id(),'claim_scope');
		try{
			ProviderClaimRecord::from_row($row);
			$request=PaymentProviderSessionRequest::from_snapshot($row->request_snapshot);
		} catch(\Throwable $e){
			throw new \InvalidArgumentException('retained_claim_shape');
		}
		self::check($row->created_at<=$this->operation->started_at()&&ProviderClaimRecord::valid_date($request->reservation_deadline()),'claim_dates');
		$sum=0;
		foreach($request->line_items() as $line){
			self::check($line->unit_amount_minor()<=intdiv(PHP_INT_MAX,$line->quantity()),'request_amount');
			$amount=$line->unit_amount_minor()*$line->quantity();
			self::check($amount<=PHP_INT_MAX-$sum,'request_amount');
			$sum+=$amount;
		}
		self::check($sum===$request->amount_minor(),'request_amount');
		$owners=$this->rows($this->db->prepare('SELECT id FROM '.$this->tables->provider_claims().' FORCE INDEX(payment_session_id) WHERE payment_session_id=%d ORDER BY id LIMIT 2',$row->payment_session_id));
		self::check(count($owners)===1,'session_owners');
		if((int)$row->epoch_id===$this->operation->source_epoch_id())self::check(in_array($row->disposition,['retired','deleted'],true),'source_claim_disposition');
		if($row->disposition==='retired'&&(int)$row->epoch_id===$this->operation->source_epoch_id())self::check($row->tombstoned_at>=$this->operation->started_at()&&$row->tombstoned_at<=$this->operation->updated_at(),'retirement_dates');
		if($row->disposition==='deleted'){
			$d=$this->point('order_deletions',$this->db->prepare('installation_id=%s AND epoch_id=%d AND original_order_id=%d',$row->installation_id,$row->epoch_id,$row->order_id));
			self::check($d!==null,'claim_deletion');
			$this->deletion($d);
			self::check($d->epoch_identity===$row->epoch_identity&&$d->deleted_at===$row->tombstoned_at,'claim_deletion');
		}
		return true;
	}

	public function deletion(object $d):bool{
		if(!$this->modern_epoch($d->epoch_id,$d->epoch_identity))return false;
		self::check($d->installation_id===$this->operation->installation_id(),'deletion_scope');
		try{
			OrderDeletionRecord::from_row($d);
		} catch(\Throwable $e){
			throw new \InvalidArgumentException('retained_deletion_shape');
		}
		$r=$this->rows($this->db->prepare('SELECT id FROM '.$this->tables->order_deletions().' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2',$d->original_order_id));
		self::check(count($r)===1,'deletion_owners');
		return true;
	}

	public function identity(object $row):bool{
		$modern=$this->modern_epoch($row->observed_epoch_id);
		$claim=$row->claim_id===null?null:$this->id('provider_claims',$row->claim_id);
		if(!$modern){
			self::check($claim===null||!$this->modern_epoch($claim->epoch_id,$claim->epoch_identity),'observation_epoch');
			return false;
		}
		self::check($row->evidence_state==='native'&&in_array($row->identity_kind,['session','payment','event'],true)&&preg_match('/\A[\x21-\x7e]{1,255}\z/D',$row->identity_value)===1&&preg_match('/\A[a-z0-9_]{1,32}\z/',$row->provider)===1,'identity_shape');
		self::check(in_array($row->binding_state,['bound','unresolved','quarantined'],true)&&($row->binding_state!=='bound'||$row->claim_id!==null)&&($row->binding_state!=='unresolved'||$row->claim_id===null)&&($row->binding_state==='quarantined'||$row->conflict_code===null),'identity_binding');
		self::check($row->conflict_code===null||CommerceResetLifecycle::token_valid($row->conflict_code),'identity_conflict');
		self::check($row->environment===null||in_array($row->environment,['test','live'],true),'identity_environment');
		self::check($row->payload_sha256===null||preg_match('/\A[0-9a-f]{64}\z/',$row->payload_sha256)===1,'identity_hash');
		self::check($row->event_type===null||preg_match('/\A[a-zA-Z0-9_.]{1,128}\z/',$row->event_type)===1,'identity_type');
		self::check(ProviderClaimRecord::valid_date($row->first_seen_at)&&CommerceResetLifecycle::token_valid($row->disposition),'identity_dates');
		if($row->claim_id!==null){
			self::check($claim!==null&&$this->claim($claim)&&$claim->provider===$row->provider&&(int)$claim->epoch_id<=(int)$row->observed_epoch_id&&($row->environment===null||$row->environment===$claim->environment),'identity_owner');
		}
		if($row->identity_kind==='event')self::check(in_array($row->disposition,['pending','processed','ignored','attention'],true)||str_starts_with($row->disposition,'neutralized_'),'identity_disposition');
		return true;
	}

	/** Exact maintenance snapshot; no operational table is referenced in this pass. */
	public function batch(int $size,string $prefix):array{
		$owned=TransactionManager::reset_context_on($this->db);
		if($owned===null||!$owned->operation()->equals($this->operation)||$this->operation->validation_generation()!==$this->operation->evidence_generation()||$size<1||$size>1000)throw new \RuntimeException('Unowned retained pass.');
		$cp=$this->operation->checkpoint();
		self::check(is_string($cp)&&str_starts_with($cp,$prefix),'position');
		$stage=substr($cp,strlen($prefix));
		$cursor=$this->operation->cursor();
		if(preg_match('/\Adc_([0-9a-z]+)\z/',$stage,$m)){
			$d=$this->id('order_deletions',$cursor);
			self::check($d!==null&&$this->deletion($d),'deletion_owner');
			$last=CommerceResetRetirement::decode_cursor($m[1]);
			$rows=$this->rows($this->db->prepare('SELECT * FROM '.$this->tables->provider_claims().' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d ORDER BY id LIMIT %d',$d->original_order_id,$last,$size));
			foreach($rows as $claim){
				self::check($this->claim($claim)&&$claim->installation_id===$d->installation_id&&(string)$claim->epoch_id===(string)$d->epoch_id&&$claim->epoch_identity===$d->epoch_identity&&$claim->disposition==='deleted'&&$claim->tombstoned_at===$d->deleted_at,'deleted_claim_owner');
				$last=self::integer($claim->id,1);
			}
			return [$prefix.($rows?'dc_'.CommerceResetRetirement::encode_cursor($last):'d'),$cursor];
		}
		self::check(in_array($stage,['c','d','i'],true),'position');
		$table=['c'=>'provider_claims','d'=>'order_deletions','i'=>'provider_identities'][$stage];
		$rows=$this->rows($this->db->prepare('SELECT * FROM '.$this->tables->$table().' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT %d',$cursor,$stage==='d'?1:$size));
		foreach($rows as $row){
			$cursor=self::integer($row->id,1);
			if($stage==='c')$this->claim($row);
			elseif($stage==='d'){
				if($this->deletion($row))return [$prefix.'dc_0',$cursor];
			} elseif($this->identity($row)&&$row->identity_kind==='event'){
				if(!(new ProviderRegistry($this->db,$this->tables))->terminalize_sealed_evidence($this->operation,$row))throw new \RuntimeException('Retained terminalization failed.');
			}
		}
		return $rows?[$cp,$cursor]:[$prefix.(['c'=>'d','d'=>'i','i'=>'done'][$stage]),0];
	}
}
