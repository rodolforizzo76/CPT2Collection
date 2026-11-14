<?php
/** Permanent provider ownership evidence. No operational row is a foreign-key parent. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
final class ProviderRegistry {
	public function __construct( private \wpdb $db, private TableNames $tables ) {}
	/** Scope eligibility only; callers must still corroborate local evidence. */
	public function scope(mixed $installation, mixed $epoch, ?int $id=null): string {
		try { return (new CommerceBaseline($this->db,$this->tables))->scope($installation,$epoch,$id); } catch(\Throwable $error) { return CommerceBaseline::ERROR; }
	}
	private function claim_eligible(object $claim): bool {
		if(CommerceBaseline::ELIGIBLE !== $this->scope($claim->installation_id,$claim->epoch_identity,(int)$claim->epoch_id)||$claim->evidence_state!=='native')return false;
		try { ProviderClaimRecord::from_row($claim); return true; } catch(\Throwable $error){return false;}
	}
	public function event_eligibility(PaymentEventReceipt|PaymentEventRecord $event): string {
		$p=json_decode($event->normalized_payload()??'{}',true);
		if($event->provider()!=='stripe_checkout'||!in_array($event->event_type(),['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],true))return 'unsupported_event_type';
		$scope=$this->scope($p['installation_id']??null,$p['epoch_identity']??null);
		if($scope!==CommerceBaseline::ELIGIBLE)return $scope;
		if(!empty($p['identity_invalid'])||($p['integration']??null)!=='cpt2collection'||$event->local_reference()===null)return CommerceBaseline::INVALID;
		return CommerceBaseline::ELIGIBLE;
	}
	public function claim( string $reference, bool $lock = false ): object|false|null {
		return $this->one( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_claims() . ' WHERE reference = %s' . ( $lock ? ' FOR UPDATE' : '' ), $reference ) );
	}
	public function claim_by_id( int $id, bool $lock = false ): object|false|null {
		return $this->one( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_claims() . ' WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $id ) );
	}
	public function identity( string $provider, string $kind, string $value, bool $lock = false ): object|false|null {
		return $this->one( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_identities() . ' WHERE provider = %s AND identity_kind = %s AND identity_value = %s' . ( $lock ? ' FOR UPDATE' : '' ), $provider, $kind, $value ) );
	}
	private function one( string $sql ): object|false|null {
		$this->db->last_error = '';
		$row = $this->db->get_row( $sql );
		return '' === $this->db->last_error ? $row : false;
	}
	/** Caller has finished acquiring operational locks. Snapshot is allowlisted by its producer. */
	public function create_claim( PaymentSessionRecord $session, CommerceContext $context, ?string $snapshot, ?string $environment, string $now ): object|false {
		if ( ! TransactionManager::admitted_on( $this->db ) || ! $context->equals(TransactionManager::context_on($this->db)) || CommerceBaseline::ELIGIBLE !== $this->scope($context->installation_id(),$context->epoch_identity(),$context->epoch_id()) || $session->epoch_id() !== $context->epoch_id() ) { return false; }
		$existing = $this->claim( $session->reference(), true );
		if ( false === $existing ) { return false; }
		if ( null !== $existing ) { return $this->matches( $existing, $session, $context ) ? $existing : false; }
		$data = array( 'installation_id'=>$context->installation_id(), 'epoch_id'=>$context->epoch_id(), 'epoch_identity'=>$context->epoch_identity(), 'provider'=>$session->provider(), 'reference'=>$session->reference(), 'order_id'=>$session->order_id(), 'payment_session_id'=>$session->id(), 'creation_key_hash'=>$session->creation_key_hash(), 'idempotency_key'=>'c2c_ps_'.$session->reference(), 'request_version'=>2, 'request_snapshot'=>$snapshot, 'environment'=>$environment, 'evidence_state'=>'native', 'disposition'=>'open', 'created_at'=>$now, 'tombstoned_at'=>null );
		if ( false === $this->db->insert( $this->tables->provider_claims(), $data, array('%s','%d','%s','%s','%s','%d','%d','%s','%s','%d','%s','%s','%s','%s','%s',null) ) ) { return false; }
		return $this->claim( $session->reference() ) ?: false;
	}
	public function matches( object $claim, PaymentSessionRecord $session, CommerceContext $context ): bool {
		return 'native' === $claim->evidence_state && 2 === (int)$claim->request_version && is_string($claim->request_snapshot) && $claim->installation_id === $context->installation_id() && (int)$claim->epoch_id === $context->epoch_id() && $claim->epoch_identity === $context->epoch_identity() && $session->epoch_id() === $context->epoch_id() && $claim->provider === $session->provider() && $claim->reference === $session->reference() && (int)$claim->order_id === $session->order_id() && (int)$claim->payment_session_id === $session->id() && $claim->creation_key_hash === $session->creation_key_hash() && 'open' === $claim->disposition;
	}
	/** Immutable binding. A conflict is a successful evidence write, not an operational authorization. */
	public function bind( string $provider, string $kind, string $value, ?int $claim_id, string $now, ?string $hash = null, ?string $type = null, ?string $environment = null ): string {
		if ( ! TransactionManager::admitted_on( $this->db ) || ! in_array($kind,array('session','payment','event'),true) || '' === $value || strlen($value)>255 || ! preg_match('/\A[\x21-\x7e]+\z/D',$value) ) { return 'database_error'; }
		return $this->bind_identity( $provider, $kind, $value, $claim_id, $now, $hash, $type, $environment, TransactionManager::context_on( $this->db )->epoch_id() );
	}
	/**
	 * Only an owned evidence transaction can append to its exact historical claim.
	 * No discovery, lifecycle changes, terminalization or operational receipts.
	 * Caller commits conflict evidence, rolls back database_error, and handles uncertainty.
	 */
	public function bind_historical( CommerceEvidenceContext $expected, string $provider, string $kind, string $value, string $now, string $environment, ?string $hash = null, ?string $type = null ): string {
		$owned = TransactionManager::evidence_context_on( $this->db );
		if ( null === $owned || ! TransactionManager::owns_evidence_context( $this->db, $expected ) || $provider !== $owned->claim()->provider() || ! in_array( $kind, array( 'session', 'payment', 'event' ), true ) || ! preg_match( '/\A[\x21-\x7e]{1,255}\z/D', $value ) || ! ProviderClaimRecord::valid_date( $now ) || $now < $owned->claim()->created_at() || ! in_array( $environment, array( 'test', 'live' ), true ) ) { return 'database_error'; }
		if ( $environment !== $owned->claim()->environment() ) { return 'conflict'; }
		if ( 'event' === $kind ) {
			if ( null === $hash || ! preg_match( '/\A[0-9a-f]{64}\z/', $hash ) || null === $type || ! preg_match( '/\A[a-zA-Z0-9_.]{1,128}\z/', $type ) ) { return 'database_error'; }
		} elseif ( null !== $hash || null !== $type ) { return 'database_error'; }
		return $this->bind_identity( $provider, $kind, $value, $owned->claim()->id(), $now, $hash, $type, $environment, $owned->claim()->epoch_id(), true );
	}
	/** Only the exact admitted envelope; no arbitrary registry keys, claims or terminal writes. */
	public function bind_retained(ProviderEvidenceContext $expected):array {
		if(TransactionManager::retained_context_on($this->db)!==$expected)return ['status'=>'database_error'];
		$i=$expected->candidate;$keys=$i->keys;ksort($keys,SORT_STRING);$out=['status'=>'retained'];
		foreach($keys as $kind=>$value){
			$r=$this->bind_identity($i->provider,$kind,$value,$expected->claim->id(),$i->observed_at,$kind==='event'?$i->event_hash:null,$kind==='event'?$i->event_type:null,$i->environment,$expected->claim->epoch_id(),false,true,false,true);
			$out[$kind]=$r;
			if(!in_array($r,['bound','conflict'],true)){ TransactionManager::fail_retained_on($this->db);$out['status']='database_error';return $out; }
			if($r==='conflict')$out['status']='conflict';
		}
		return $out;
	}
	private function bind_identity( string $provider, string $kind, string $value, ?int $claim_id, string $now, ?string $hash, ?string $type, ?string $environment, int $observed_epoch, bool $historical = false, bool $maintenance = false, bool $coverage = false, bool $retained = false ): string {
		if(null===$claim_id)return 'scope_rejected';
		$owner=$this->claim_by_id($claim_id);
		if(!is_object($owner)||$owner->provider!==$provider)return 'database_error';
		if($retained){$c=TransactionManager::retained_context_on($this->db);if($c===null||!$c->claim->equals(ProviderClaimRecord::from_row($owner)))return 'database_error';}
		elseif(!$this->claim_eligible($owner))return 'scope_rejected';
		if(null!==$environment&&$owner->environment!==$environment)return 'conflict';
		$row = $this->identity($provider,$kind,$value,true);
		if ( false === $row ) { return 'database_error'; }
		if(is_object($row)&&$row->claim_id!==null){$prior=$this->claim_by_id((int)$row->claim_id);if(!is_object($prior))return 'database_error';if($retained){if((int)$prior->id!==$claim_id)return 'database_error';}elseif(!$this->claim_eligible($prior))return 'scope_rejected';}
		$plan=self::identity_plan($row,$provider,$kind,$value,$claim_id,$now,$hash,$type,$environment,$observed_epoch,(int)$owner->epoch_id,$historical,$maintenance);
		if($row!==null&&isset($plan['data']['claim_id'])){
			// Preserve the existing adoption re-read; the retained planner already owns this point.
			$claim=$this->claim_by_id($claim_id);
			$plan=self::identity_plan($row,$provider,$kind,$value,$claim_id,$now,$hash,$type,$environment,$observed_epoch,is_object($claim)?(int)$claim->epoch_id:null,$historical,$maintenance);
		}
		$data=$plan['data'];
		if($plan['insert']){
			if(!$this->write_identity($historical,$coverage,$retained,fn():bool=>false!==$this->db->insert($this->tables->provider_identities(),$data,array('%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s',null))))return 'database_error';
		}elseif($data&&!$this->write_identity($historical,$coverage,$retained,fn():bool=>false!==$this->db->update($this->tables->provider_identities(),$data,['id'=>(int)$row->id],array_map(static fn(string $key):string=>'claim_id'===$key?'%d':'%s',array_keys($data)),['%d'])))return 'database_error';
		return $plan['status'];
	}

	/** Pure immutable binder decision; used for both writes and pre-write recovery DATA. */
	private static function identity_plan(?object $row,string $provider,string $kind,string $value,?int $claim_id,string $now,?string $hash,?string $type,?string $environment,int $observed_epoch,?int $owner_epoch,bool $historical,bool $maintenance):array {
		if($row===null)return ['status'=>'bound','insert'=>true,'data'=>array('provider'=>$provider,'identity_kind'=>$kind,'identity_value'=>$value,'claim_id'=>$claim_id,'binding_state'=>'bound','evidence_state'=>'native','observed_epoch_id'=>$observed_epoch,'first_seen_at'=>$now,'payload_sha256'=>$hash,'event_type'=>$type,'environment'=>$environment,'disposition'=>'pending','conflict_code'=>null)];
		$conflict = ( ( $historical || $maintenance ) && ( null !== $row->conflict_code || ! in_array( $row->binding_state, array( 'bound', 'unresolved', 'quarantined' ), true ) || ( 'bound' === $row->binding_state && null === $row->claim_id ) || ( 'unresolved' === $row->binding_state && null !== $row->claim_id ) ) ) || 'quarantined' === $row->binding_state || (null!==$row->claim_id && null!==$claim_id && (int)$row->claim_id!==$claim_id) || (null!==$hash && null!==$row->payload_sha256 && $hash!==$row->payload_sha256) || (null!==$type && null!==$row->event_type && $type!==$row->event_type) || (null!==$environment && null!==$row->environment && $environment!==$row->environment);
		if ( ! $conflict && null===$row->claim_id && null!==$claim_id ) {
			$claim_epoch=$owner_epoch;
			// Unknown evidence observed before a claim's epoch can never migrate forward.
			$conflict=$claim_epoch===null || $claim_epoch > (int)$row->observed_epoch_id;
		}
		if($conflict){
			$code=($historical||$maintenance)?($row->conflict_code??'identity_conflict'):'identity_conflict';
			return ['status'=>'conflict','insert'=>false,'data'=>'quarantined'===$row->binding_state&&$row->conflict_code===$code?[]:['binding_state'=>'quarantined','conflict_code'=>$code]];
		}
		$data = array();
		if ( null === $row->claim_id && null !== $claim_id ) { $data['claim_id'] = $claim_id; $data['binding_state'] = 'bound'; }
		foreach ( array( 'payload_sha256'=>$hash, 'event_type'=>$type, 'environment'=>$environment ) as $key=>$value ) {
			if ( null === $row->$key && null !== $value ) { $data[$key] = $value; }
		}
		return ['status'=>'bound','insert'=>false,'data'=>$data];
	}
	/** DATA only; the router supplies its independently locked owned before-state. */
	public static function retained_postconditions(ProviderEvidenceContext $context,array $before):array {
		$i=$context->candidate;$after=[];$charges=0;$keys=$i->keys;ksort($keys,SORT_STRING);
		foreach($keys as $kind=>$value){
			$row=$before[$kind];
			$plan=self::identity_plan($row,$i->provider,$kind,$value,$context->claim->id(),$i->observed_at,$kind==='event'?$i->event_hash:null,$kind==='event'?$i->event_type:null,$i->environment,$context->claim->epoch_id(),$context->claim->epoch_id(),false,true);
			$after[$kind]=$row===null?$plan['data']:array_replace(get_object_vars($row),$plan['data']);
			if($plan['data']!==[])++$charges;
		}
		return [$after,$charges];
	}

	/** Exactly one reservation for one state-changing invocation, including multi-column changes. */
	private function write_identity( bool $historical, bool $coverage, bool $retained, callable $mutation ): bool {
		if ( $retained ) { return TransactionManager::with_retained_identity_mutation($this->db,$mutation); }
		if ( $coverage ) { return TransactionManager::with_reset_coverage_identity_mutation( $this->db, $mutation ); }
		if ( ! $historical ) { return $mutation(); }
		$owned = TransactionManager::evidence_context_on( $this->db );
		return null !== $owned && TransactionManager::with_historical_identity_mutation( $this->db, $owned, $mutation );
	}
	/** Nonlocking routing discovery. Mutating callers revalidate after operational locks. */
	public function route( PaymentEventReceipt|PaymentEventRecord $event, CommerceContext $context ): array {
		$eligibility=$this->event_eligibility($event);if($eligibility!==CommerceBaseline::ELIGIBLE)return ['status'=>'scope_rejected','code'=>$eligibility];
		$candidates=array();
		$event_identity=$this->identity($event->provider(),'event',$event->provider_event_id());
		if(false===$event_identity){return array('status'=>'database_error');}
		if(is_object($event_identity)){
			if('quarantined'===$event_identity->binding_state|| (null!==$event_identity->payload_sha256 && $event_identity->payload_sha256!==$event->payload_sha256())){return array('status'=>'conflict');}
			if(null!==$event_identity->claim_id){$candidates[]=(int)$event_identity->claim_id;}
		}
		$payload=json_decode($event->normalized_payload()??'{}',true);
		if(!empty($payload['identity_invalid'])){return array('status'=>'unresolved');}
		if(null!==$event->local_reference()){
			$ref=$this->claim($event->local_reference());if(false===$ref){return array('status'=>'database_error');}if(null!==$ref){$candidates[]=(int)$ref->id;}
		}
		foreach(array('payment'=>$event->provider_payment_id(),'session'=>$event->provider_session_id()) as $kind=>$value){
			if(null===$value){continue;}$identity=$this->identity($event->provider(),$kind,$value);
			if(false===$identity){return array('status'=>'database_error');}
			if(is_object($identity)){if('quarantined'===$identity->binding_state){return array('status'=>'conflict');}if(null!==$identity->claim_id){$candidates[]=(int)$identity->claim_id;}}
		}
		$candidates=array_unique($candidates);
		if(count($candidates)>1){return array('status'=>'conflict');}
		if(!$candidates){return array('status'=>'unresolved');}
		$claim=$this->claim_by_id((int)reset($candidates));if(!is_object($claim)){return array('status'=>'database_error');}
		if(!$this->claim_eligible($claim))return ['status'=>'conflict'];
		$payload=json_decode($event->normalized_payload()??'{}',true);
		if($claim->provider!==$event->provider()||(null!==$event->local_reference()&&$claim->reference!==$event->local_reference())||(null!==$claim->environment&&$claim->environment!==($event->livemode()?'live':'test'))||(isset($payload['installation_id'])&&$payload['installation_id']!==$claim->installation_id)||(isset($payload['epoch_identity'])&&$payload['epoch_identity']!==$claim->epoch_identity)) {return array('status'=>'conflict');}
		if($claim->installation_id!==$context->installation_id()||(int)$claim->epoch_id!==$context->epoch_id()||$claim->epoch_identity!==$context->epoch_identity()||'open'!==$claim->disposition){return array('status'=>'retired','claim'=>$claim);}
		$row=$this->one($this->db->prepare('SELECT s.id, s.provider, s.provider_session_id, s.provider_payment_id, s.currency, s.amount_minor FROM '.$this->tables->payment_sessions().' s JOIN '.$this->tables->orders().' o ON o.id=s.order_id AND o.epoch_id=s.epoch_id WHERE s.id=%d AND s.reference=%s AND s.epoch_id=%d AND s.order_id=%d',$claim->payment_session_id,$claim->reference,$claim->epoch_id,$claim->order_id));
		if(false===$row){return array('status'=>'database_error');}
		if(is_object($row)&&($row->provider!==$event->provider()||(null!==$row->provider_session_id&&null!==$event->provider_session_id()&&$row->provider_session_id!==$event->provider_session_id())||(null!==$row->provider_payment_id&&null!==$event->provider_payment_id()&&$row->provider_payment_id!==$event->provider_payment_id())||(null!==$event->currency()&&$row->currency!==$event->currency())||(null!==$event->amount_minor()&&(int)$row->amount_minor!==$event->amount_minor()))){return array('status'=>'conflict');}
		return array('status'=>null===$row?'absent':'active','claim'=>$claim);
	}
	/** Must be the last lock group in an association transaction. */
	public function bind_event( PaymentEventReceipt|PaymentEventRecord $event, ?object $claim, string $now ): string {
		if($this->event_eligibility($event)!==CommerceBaseline::ELIGIBLE||null===$claim)return 'scope_rejected';
		if(null!==$claim){$locked=$this->claim_by_id((int)$claim->id,true);if(!is_object($locked)||$locked->reference!==$claim->reference||$locked->disposition!==$claim->disposition){return 'database_error';}}
		$payload=json_decode($event->normalized_payload()??'{}',true);
		$identities=!empty($payload['identity_invalid'])?array('event'=>$event->provider_event_id()):array('event'=>$event->provider_event_id(),'payment'=>$event->provider_payment_id(),'session'=>$event->provider_session_id());
		$result='bound';foreach($identities as $kind=>$value){
			if(null===$value){continue;}
			$r=$this->bind($event->provider(),$kind,$value,null===$claim?null:(int)$claim->id,$now,'event'===$kind?$event->payload_sha256():null,'event'===$kind?$event->event_type():null,$event->livemode()?'live':'test');
			if(!in_array($r,['bound','conflict'],true)){return $r;}if('conflict'===$r){$result=$r;}
		}return $result;
	}
	/** Exact stored operational observation, corroborated before erasure. */
	public function cover_reset_record( CommerceResetRecord $expected, string $stage, object $row, string $now ): bool {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || 'terminalizing_events' !== $expected->phase() || $expected->checkpoint() !== 's1_' . $stage || ! in_array( $stage, array( 's', 'e' ), true ) || ! ProviderClaimRecord::valid_date( $now ) ) { return false; }
		if ( false === ( new CommerceResetRepository( $this->db, $this->tables ) )->find_for_maintenance( $owned ) ) { return false; }
		$table = 's' === $stage ? $this->tables->payment_sessions() : $this->tables->payment_events();
		$stored = $this->one( $this->db->prepare( 'SELECT * FROM ' . $table . ' WHERE id=%d FOR UPDATE', $row->id ?? 0 ) );
		if ( ! is_object( $stored ) || get_object_vars( $stored ) !== get_object_vars( $row ) ) { return false; }
		$claim = CommerceResetProviderCoverage::origin( $this->db, $this->tables, $expected, $stage, $stored );
		if ( null === $claim ) { return true; }
		foreach ( array( 'session'=>'provider_session_id', 'payment'=>'provider_payment_id', 'event'=>'provider_event_id' ) as $kind=>$field ) {
			if ( null === $stored->$field ) { continue; }
			$result = $this->bind_identity( $stored->provider, $kind, $stored->$field, (int) $claim->id, 's' === $stage ? $stored->created_at : $stored->received_at, 'e' === $stage && 'event' === $kind ? $stored->payload_sha256 : null, 'e' === $stage && 'event' === $kind ? $stored->event_type : null, $claim->environment, (int) $claim->epoch_id, false, true, true );
			if ( ! in_array( $result, array( 'bound', 'conflict' ), true ) ) { return false; }
		}
		return true;
	}
	/** Durable-only terminal catch-up; pending Events never acquire operational authority. */
	public function terminalize_sealed_evidence( CommerceResetRecord $expected, object $identity ): bool {
		$owned = TransactionManager::reset_context_on( $this->db );
		$valid = 'terminalizing_events' === $expected->phase() ? 's1_i' === $expected->checkpoint() : in_array( $expected->phase(), array( 'deleting', 'finalizing' ), true ) && 'i' === ( CommerceResetDeletion::position( $expected )[2] ?? null );
		if ( ! $valid || null === $owned || ! $owned->operation()->equals( $expected ) || $expected->evidence_generation() !== $expected->validation_generation() || 'event' !== $identity->identity_kind ) { return false; }
		if ( false === ( new CommerceResetRepository( $this->db, $this->tables ) )->find_for_maintenance( $owned ) ) { return false; }
		$row = $this->identity( $identity->provider, 'event', $identity->identity_value, true );
		if ( ! is_object( $row ) || get_object_vars( $row ) !== get_object_vars( $identity ) ) { return false; }
		if ( ! ( new CommerceResetRetainedEvidence( $this->db, $this->tables, $expected ) )->identity( $row ) ) { return false; }
		if ( 'quarantined' === $row->binding_state || 'pending' !== $row->disposition ) { return true; }
		return 1 === $this->db->update( $this->tables->provider_identities(), array( 'disposition'=>'neutralized_retired' ), array( 'id'=>(int) $row->id, 'disposition'=>'pending' ), array( '%s' ), array( '%d', '%s' ) );
	}
	/** Maintenance-only terminal evidence; never grants operational identity authority. */
	public function terminalize_reset_receipt( CommerceResetRecord $expected, object $receipt ): bool {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || 'terminalizing_events' !== $expected->phase() || ( null !== $expected->checkpoint() && ! in_array( $expected->checkpoint(), array( 'b_e', 'b_i' ), true ) ) || $expected->validation_generation() !== $expected->evidence_generation() ) { return false; }
		if ( false === ( new CommerceResetRepository( $this->db, $this->tables ) )->find_for_maintenance( $owned ) ) { return false; }
		$stored = $this->one( $this->db->prepare( 'SELECT * FROM ' . $this->tables->payment_events() . ' WHERE id=%d FOR UPDATE', $receipt->id ?? 0 ) );
		if ( ! is_object( $stored ) || get_object_vars( $stored ) !== get_object_vars( $receipt ) ) { return false; }
		$payload = json_decode( $receipt->normalized_payload ?? '', true );
		if ( ! is_array( $payload ) || ( $payload['installation_id'] ?? null ) !== $expected->installation_id() || ( $payload['epoch_identity'] ?? null ) !== $expected->source_epoch_identity() ) { return false; }
		$claim = $this->claim( $receipt->local_reference ?? '', true );
		// Do not invent ownership for unresolved or conflicting observations.
		if ( false === $claim ) { return false; }
		if ( null === $claim ) { throw new \InvalidArgumentException( 'retirement_event_owner' ); }
		if ( $claim->installation_id !== $expected->installation_id() || (int) $claim->epoch_id !== $expected->source_epoch_id() || $claim->epoch_identity !== $expected->source_epoch_identity() || ! in_array( $claim->disposition, array( 'retired', 'deleted' ), true ) ) { throw new \InvalidArgumentException( 'retirement_event_owner' ); }
		foreach ( array( 'event'=>'provider_event_id', 'session'=>'provider_session_id', 'payment'=>'provider_payment_id' ) as $kind=>$field ) {
			if ( null === $receipt->$field ) { continue; }
			$code = $this->bind_identity( $receipt->provider, $kind, $receipt->$field, (int) $claim->id, $receipt->received_at, 'event' === $kind ? $receipt->payload_sha256 : null, 'event' === $kind ? $receipt->event_type : null, (int) $receipt->livemode ? 'live' : 'test', $expected->source_epoch_id(), false, true );
			if ( ! in_array( $code, array( 'bound', 'conflict' ), true ) ) { return false; }
		}
		$identity = $this->identity( $receipt->provider, 'event', $receipt->provider_event_id, true );
		return is_object( $identity ) && $this->terminalize_reset_identity( $expected, $identity );
	}
	public function terminalize_reset_identity( CommerceResetRecord $expected, object $identity ): bool {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || 'terminalizing_events' !== $expected->phase() || ( null !== $expected->checkpoint() && ! in_array( $expected->checkpoint(), array( 'b_e', 'b_i' ), true ) ) || $expected->validation_generation() !== $expected->evidence_generation() || 'event' !== $identity->identity_kind ) { return false; }
		if ( false === ( new CommerceResetRepository( $this->db, $this->tables ) )->find_for_maintenance( $owned ) ) { return false; }
		$row = $this->identity( $identity->provider, 'event', $identity->identity_value, true );
		if ( ! is_object( $row ) || get_object_vars( $row ) !== get_object_vars( $identity ) ) { return false; }
		$claim = null === $row->claim_id ? null : $this->claim_by_id( (int) $row->claim_id );
		if ( false === $claim ) { return false; }
		if ( (int) $row->observed_epoch_id !== $expected->source_epoch_id() && ( ! is_object( $claim ) || $claim->installation_id !== $expected->installation_id() || (int) $claim->epoch_id !== $expected->source_epoch_id() || $claim->epoch_identity !== $expected->source_epoch_identity() ) ) { return false; }
		if ( in_array( $row->disposition, array( 'processed', 'ignored', 'attention' ), true ) || str_starts_with( $row->disposition, 'neutralized_' ) || 'quarantined' === $row->binding_state ) { return true; }
		if ( 'pending' !== $row->disposition ) { throw new \InvalidArgumentException( 'retirement_identity_disposition' ); }
		return 1 === $this->db->update( $this->tables->provider_identities(), array( 'disposition'=>'neutralized_retired' ), array( 'id'=>(int) $row->id, 'disposition'=>'pending' ), array( '%s' ), array( '%d', '%s' ) );
	}
	public function terminal( string $provider, string $event_id, string $disposition ): bool {
		if(!TransactionManager::admitted_on($this->db)){return false;}
		$event=$this->identity($provider,'event',$event_id);
		if(!is_object($event)||$event->claim_id===null)return false;
		$claim=$this->claim_by_id((int)$event->claim_id);
		if(!is_object($claim)||!$this->claim_eligible($claim))return false;
		return false!==$this->db->update($this->tables->provider_identities(),array('disposition'=>$disposition),array('provider'=>$provider,'identity_kind'=>'event','identity_value'=>$event_id),array('%s'),array('%s','%s','%s'));
	}
}
