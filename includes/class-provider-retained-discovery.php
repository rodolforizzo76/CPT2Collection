<?php
/** A0 read-only discovery and shared bounded retained parser; A1 requires owned locking admission. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-provider-claim-locator.php';
require_once __DIR__ . '/class-provider-discovery-candidate.php';
require_once __DIR__ . '/class-provider-identity-discovery.php';
require_once __DIR__ . '/class-provider-owner-discovery.php';
require_once __DIR__ . '/class-provider-discovery-result.php';
require_once __DIR__ . '/class-commerce-reset-history.php';

final class ProviderRetainedDiscovery {
	private ?array $lock_inventory = null;
	private ?array $locked_reads = null;
	public function __construct(private \wpdb $db,private TableNames $tables) {}
	public function discover(ProviderDiscoveryCandidate $input):ProviderDiscoveryResult {
		$tx=new TransactionManager($this->db,$this->tables);
		if(!$this->db instanceof CommerceConnection||!$this->db->transaction_connection_usable())return new ProviderDiscoveryResult('transaction_outcome_unknown','connection_unusable');
		try { if(!$tx->supports_retained_discovery()||!$this->indexes_valid())return new ProviderDiscoveryResult('database_error','retained_storage_unavailable'); }
		catch(\Throwable $e){return new ProviderDiscoveryResult('database_error','storage_read_failed');}
		if(!$tx->begin_read_snapshot())return new ProviderDiscoveryResult($tx->is_outcome_uncertain()?'transaction_outcome_unknown':'database_error','snapshot_begin_failed');
		try { $result=$this->classify($input); }
		catch(\InvalidArgumentException $e){$result=new ProviderDiscoveryResult(str_starts_with($e->getMessage(),'ambiguous_')?'ambiguous':'corrupt',$e->getMessage());}
		catch(\Throwable $e){$result=new ProviderDiscoveryResult('database_error','retained_read_failed');}
		return $tx->rollback()?$result:new ProviderDiscoveryResult('transaction_outcome_unknown','snapshot_rollback_unknown');
	}
	/** D1 internal read under an already-owned normal fence or inspection fence.
	 * No transaction/admission is created; no retained row locks or writes are taken.
	 * The caller owns termination. A0 discover() semantics remain unchanged.
	 */
	public function read_for_continuation(ProviderDiscoveryCandidate $input):ProviderDiscoveryResult {
		if (!TransactionManager::admitted_on($this->db) && !TransactionManager::continuation_inspection_on($this->db)) {
			throw new \LogicException('Fresh continuation fence required.');
		}
		try {
			if (!(new TransactionManager($this->db,$this->tables))->supports_retained_discovery() || !$this->indexes_valid()) return new ProviderDiscoveryResult('database_error','retained_storage_unavailable');
			return $this->classify($input);
		} catch(\InvalidArgumentException $e) {
			return new ProviderDiscoveryResult(str_starts_with($e->getMessage(),'ambiguous_')?'ambiguous':'corrupt',$e->getMessage());
		} catch(\Throwable $e) { return new ProviderDiscoveryResult('database_error','retained_read_failed'); }
	}
	/** Internal A1 admission. Exclusive control drains every cooperating writer first.
	 * Discovery below is lookup material only; acquire epochs BEFORE reset/provider rows,
	 * then independently classify again while all recorded predicates remain locked.
	 */
	public function lock_and_classify(ProviderDiscoveryCandidate $input,?CommerceResetRecord $recovery_domain=null):ProviderDiscoveryResult {
		if(!TransactionManager::retained_admission_on($this->db))throw new \LogicException('Owned A1 admission required.');
		$control_query='SELECT * FROM '.$this->tables->commerce_control().' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 2';
		$control_rows=$this->rows($control_query.' FOR UPDATE');
		$this->lock_inventory=[];
		try {
			$preliminary=$this->classify($input);
			if($recovery_domain!==null){
				$row=$this->point('commerce_resets','source_epoch',$this->db->prepare('installation_id=%s AND source_epoch_id=%d',$recovery_domain->installation_id(),$recovery_domain->source_epoch_id()));
				self::check($row!==null,'recovery_domain_missing');$r=CommerceResetRecord::from_row($row);
				self::check($r->operation_identity()===$recovery_domain->operation_identity(),'recovery_domain_conflict');
				$source=$this->epoch($r->source_epoch_id(),$r->source_epoch_identity());
				$target=$r->target_epoch_id()===null?null:$this->epoch($r->target_epoch_id(),$r->target_epoch_identity());
				$control=$this->rows('SELECT * FROM '.$this->tables->commerce_control().' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 2')[0];
				self::check($r->status()==='completed'?CommerceResetHistory::completed($r,$control->installation_id,(int)$control->current_epoch_id,$source,$target):($preliminary->active_reset!==null&&$preliminary->active_reset->equals($r)),'recovery_domain_noncanonical');
			}
			$inventory=$this->lock_inventory;
		}finally{$this->lock_inventory=null;}
		$epochs=[];$claims=[];
		foreach($inventory as $q=>$rows){
			if(str_contains($q,'FROM '.$this->tables->commerce_epochs().' '))foreach($rows as $row)$epochs[(int)$row->id]=true;
			if(str_contains($q,'FROM '.$this->tables->provider_claims().' '))foreach($rows as $row)$claims[(int)$row->id]=true;
		}
		ksort($epochs,SORT_NUMERIC);
		foreach($epochs as $id=>$_)$this->rows('SELECT * FROM '.$this->tables->commerce_epochs().' FORCE INDEX(PRIMARY) WHERE id='.$id.' LIMIT 1 FOR UPDATE');
		// Existing identity points only revisit held epochs; absent points lock gaps.
		$locked=[$control_query=>$control_rows];
		foreach(['commerce_epochs','commerce_resets','provider_claims','order_deletions','provider_identities'] as $method){
			if($method==='provider_claims'){
				ksort($claims,SORT_NUMERIC);foreach($claims as $id=>$_)$this->rows('SELECT * FROM '.$this->tables->provider_claims().' FORCE INDEX(PRIMARY) WHERE id='.$id.' LIMIT 1 FOR UPDATE');
			}
			foreach($inventory as $q=>$rows)if(str_contains($q,'FROM '.$this->tables->$method().' ')){
				$locked[$q]=$this->rows($q.' FOR UPDATE');
				self::check(serialize($locked[$q])===serialize($rows),'locked_state_changed');
			}
		}
		$this->locked_reads=$locked;
		try{return $this->classify($input);}finally{$this->locked_reads=null;}
	}
	/** Fixed schema metadata, bounded by the expected key's <=4 columns plus one sentinel. */
	public function indexes_valid(bool $mutation=false):bool {
		$keys=[
			'commerce_control'=>['PRIMARY'=>['id']], 'commerce_epochs'=>['PRIMARY'=>['id'],'identity'=>['identity']],
			'commerce_resets'=>['source_epoch'=>['installation_id','source_epoch_id'],'active_slot'=>['active_slot']],
			'provider_claims'=>['PRIMARY'=>['id'],'reference'=>['reference'],'provider_idempotency'=>['provider','idempotency_key'],'attempt'=>['installation_id','epoch_id','order_id','creation_key_hash'],'payment_session_id'=>['payment_session_id']],
			'provider_identities'=>['identity'=>['provider','identity_kind','identity_value']],
			'order_deletions'=>['owned_order'=>['installation_id','epoch_id','original_order_id'],'original_order_id'=>['original_order_id']]
		];
		if($mutation){$keys['commerce_resets']['PRIMARY']=['id'];$keys['provider_identities']['PRIMARY']=['id'];}
		foreach($keys as $method=>$indexes)foreach($indexes as $name=>$columns){
			$rows=$this->rows($this->db->prepare('SELECT COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE,SUB_PART,INDEX_TYPE,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s LIMIT 5',$this->tables->$method(),$name));
			if(count($rows)!==count($columns))return false;
			usort($rows,fn($a,$b)=>(int)$a->SEQ_IN_INDEX<=>(int)$b->SEQ_IN_INDEX);
			foreach($rows as $n=>$row)if($row->COLUMN_NAME!==$columns[$n]||(int)$row->SEQ_IN_INDEX!==$n+1||(int)$row->NON_UNIQUE!==(in_array($name,['payment_session_id','original_order_id'],true)?1:0)||$row->SUB_PART!==null||$row->INDEX_TYPE!=='BTREE'||$row->COLLATION!=='A')return false;
		}
		return true;
	}
	private function rows(string $query):array {
		if($this->locked_reads!==null){self::check(array_key_exists($query,$this->locked_reads),'locked_dependency_missing');return $this->locked_reads[$query];}
		$this->db->last_error='';$rows=$this->db->get_results($query);
		if($this->db->last_error!==''||!is_array($rows))throw new \RuntimeException('Retained read failed.');
		if($this->lock_inventory!==null && str_starts_with($query,'SELECT ') && !str_contains($query,'information_schema.'))$this->lock_inventory[$query]=$rows;
		return $rows;
	}
	private function point(string $method,string $index,string $where):?object {
		$rows=$this->rows('SELECT * FROM '.$this->tables->$method().' FORCE INDEX('.$index.') WHERE '.$where.' LIMIT 1');
		return $rows[0]??null;
	}
	private static function check(bool $ok,string $reason):void { if(!$ok)throw new \InvalidArgumentException($reason); }
	private static function integer(mixed $value,int $min=1):int { return CommerceResetRetainedEvidence::integer($value,$min); }
	private function epoch(int $id,?string $identity=null):object {
		$e=$this->point('commerce_epochs','PRIMARY',$this->db->prepare('id=%d',$id));
		self::check($e!==null&&self::integer($e->id)===$id&&CommerceBaseline::identity($e->identity),'epoch_missing_or_invalid');
		self::check(ProviderClaimRecord::valid_date($e->created_at)&&($e->retired_at===null||ProviderClaimRecord::valid_date($e->retired_at)&&$e->retired_at>=$e->created_at),'epoch_dates');
		if($identity!==null){
			$by_identity=$this->point('commerce_epochs','identity',$this->db->prepare('identity=%s',$identity));
			self::check($e->identity===$identity&&$by_identity!==null&&self::integer($by_identity->id)===$id,'epoch_identity_conflict');
		}
		return $e;
	}
	/** Pure native parsing; malformed JSON/types are corruption, never database absence. */
	private static function native_claim(object $row):ProviderClaimRecord {
		try {
			$claim=ProviderClaimRecord::from_row($row);
			$request=PaymentProviderSessionRequest::from_snapshot($row->request_snapshot);
			self::check(ProviderClaimRecord::valid_date($request->reservation_deadline()),'claim_deadline');
			$sum=0;
			foreach($request->line_items() as $line){
				self::check($line->unit_amount_minor()<=intdiv(PHP_INT_MAX,$line->quantity()),'claim_amount_overflow');
				$amount=$line->unit_amount_minor()*$line->quantity();self::check($amount<=PHP_INT_MAX-$sum,'claim_amount_overflow');$sum+=$amount;
			}
			self::check($sum===$request->amount_minor(),'claim_amount_sum');
			return $claim;
		} catch(\Throwable $e){throw new \InvalidArgumentException('claim_native_shape',0,$e);}
	}
	private function locate(ProviderDiscoveryCandidate $i):?object {
		$l=$i->locator;if($l===null)return null;$v=$l->values;
		[$index,$where]=match($l->kind){
			'id'=>['PRIMARY',$this->db->prepare('id=%d',$v[0])],
			'reference'=>['reference',$this->db->prepare('reference=%s',$v[0])],
			'idempotency'=>['provider_idempotency',$this->db->prepare('provider=%s AND idempotency_key=%s',$i->provider,$v[0])],
			'attempt'=>['attempt',$this->db->prepare('installation_id=%s AND epoch_id=%d AND order_id=%d AND creation_key_hash=%s',...$v)],
			'payment_session'=>['payment_session_id',$this->db->prepare('payment_session_id=%d',$v[0])]
		};
		if($l->kind!=='payment_session')return $this->point('provider_claims',$index,$where);
		$r=$this->rows('SELECT * FROM '.$this->tables->provider_claims().' FORCE INDEX(payment_session_id) WHERE '.$where.' ORDER BY id LIMIT 2');
		self::check(count($r)<=1,'ambiguous_session_owner');return $r[0]??null;
	}
	private function active(object $control,object $current):?CommerceResetRecord {
		$rows=$this->rows('SELECT * FROM '.$this->tables->commerce_resets().' FORCE INDEX(active_slot) WHERE active_slot IS NOT NULL LIMIT 2');
		self::check(count($rows)<=1,'active_reset_ambiguous');
		if($control->state==='ready'){self::check(!$rows,'ready_has_active_reset');return null;}
		self::check($control->state==='resetting'&&count($rows)===1,'active_reset_missing');
		$r=CommerceResetRecord::from_row($rows[0]);
		self::check(CommerceResetLifecycle::active($r)&&$r->installation_id()===$control->installation_id&&$r->source_epoch_id()===(int)$current->id&&$r->source_epoch_identity()===$current->identity&&$current->created_at<=$r->started_at(),'active_reset_scope');
		self::check($r->validation_generation()===null||$r->validation_generation()<=$r->evidence_generation(),'active_reset_generation');
		self::check($this->point('commerce_epochs','identity',$this->db->prepare('identity=%s',$r->target_epoch_identity()))===null,'active_target_allocated');
		self::check(self::active_position($r),'active_reset_position');
		return $r;
	}
	private static function validation_position(?string $cp,int $cursor):bool {
		if($cp==='v_complete')return $cursor===0;
		if(is_string($cp)&&in_array(substr($cp,2),CommerceResetValidation::STAGES,true)&&str_starts_with($cp,'v_'))return true;
		if(!is_string($cp)||!preg_match('/\Av_(?:sum_([0-9]+)_([0-9]+)|dc_([0-9]+)|req_([0-9]+)_([0-9]+))\z/',$cp,$m))return false;
		foreach(array_slice($m,1) as $n)if($n!=='')self::integer($n,0);
		return $cursor>0;
	}
	private static function active_position(CommerceResetRecord $r):bool {
		$cp=$r->checkpoint();$cursor=$r->cursor();
		if($r->phase()==='validating'){
			// Pre-pass orchestration tokens are supported by existing progress(), not certificates.
			return $r->validation_generation()===null?($cp===null||!str_starts_with($cp,'v_')):self::validation_position($cp,$cursor);
		}
		if(in_array($r->phase(),['deleting','finalizing'],true))return CommerceResetDeletion::position($r)!==null&&$r->validation_generation()!==null;
		if($r->phase()==='terminalizing_events'&&is_string($cp)&&str_starts_with($cp,'s1_')){
			$s=substr($cp,3);
			if(str_starts_with($s,'v_'))return self::validation_position(substr($s,2),$cursor);
			if(preg_match('/\Adc_([0-9a-z]+)\z/',$s,$m)){CommerceResetRetirement::decode_cursor($m[1]);return $cursor>0;}
			return in_array($s,['s','e','c','d','i','done'],true)&&($s!=='done'||$cursor===0)&&$r->validation_generation()!==null;
		}
		try{[$stage,,$v]=CommerceResetRetirement::position($r);return (($r->phase()==='retiring_claims')===($stage==='c'))&&($v===null||self::validation_position($v,$cursor))&&$r->validation_generation()!==null;}catch(\InvalidArgumentException $e){return false;}
	}
	private function classify(ProviderDiscoveryCandidate $i):ProviderDiscoveryResult {
		// First consistent read: fixed singleton. All following points share this MVCC snapshot.
		$c=$this->rows('SELECT * FROM '.$this->tables->commerce_control().' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 2');
		self::check(count($c)===1&&(string)$c[0]->id==='1','control_invalid');$c=$c[0];
		self::check(CommerceBaseline::identity($c->installation_id)&&ProviderClaimRecord::valid_date($c->updated_at),'control_shape');
		$b=json_decode($c->baseline_contract??'',true);
		self::check(is_array($b)&&array_keys($b)===['version','installation_id','identity','first_epoch_id','first_epoch_identity','status']&&$b['version']===1&&$b['installation_id']===$c->installation_id&&CommerceBaseline::identity($b['identity'])&&CommerceBaseline::identity($b['first_epoch_identity'])&&is_int($b['first_epoch_id'])&&$b['first_epoch_id']>0&&in_array($b['status'],['prepared','completed'],true),'baseline_invalid');
		$current=$this->epoch(self::integer($c->current_epoch_id));
		if($b['status']==='prepared'){
			self::check($c->state==='baseline_pending'&&$current->retired_at===null&&(int)$current->id<$b['first_epoch_id'],'prepared_baseline_control');
			self::check($this->point('commerce_epochs','PRIMARY',$this->db->prepare('id=%d',$b['first_epoch_id']))===null&&$this->point('commerce_epochs','identity',$this->db->prepare('identity=%s',$b['first_epoch_identity']))===null,'prepared_baseline_allocated');
			$high=$this->rows('SELECT id FROM '.$this->tables->commerce_epochs().' FORCE INDEX(PRIMARY) ORDER BY id DESC LIMIT 1');
			self::check(count($high)===1&&self::integer($high[0]->id)===$b['first_epoch_id']-1,'prepared_baseline_high');
			return new ProviderDiscoveryResult('unsupported','baseline_pending');
		}
		$first=$this->epoch($b['first_epoch_id'],$b['first_epoch_identity']);
		self::check($current->retired_at===null&&(int)$current->id>=$b['first_epoch_id']&&((int)$current->id===$b['first_epoch_id']||$first->retired_at!==null),'baseline_epoch_lifecycle');
		$active=$this->active($c,$current);
		if($i->provider!=='stripe_checkout'||$i->scope!=='managed'||($i->event_type!==null&&!in_array($i->event_type,['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],true)))return new ProviderDiscoveryResult('unsupported','provider_or_scope');
		if($i->installation!==null&&$i->installation!==$c->installation_id)return new ProviderDiscoveryResult('foreign','foreign_installation');
		if($i->epoch_identity!==null){
			$e=$this->point('commerce_epochs','identity',$this->db->prepare('identity=%s',$i->epoch_identity));
			self::check($e!==null,'captured_epoch_missing');$e=$this->epoch(self::integer($e->id),$i->epoch_identity);
			self::check($i->epoch_id===null||$i->epoch_id===(int)$e->id,'captured_epoch_mismatch');
			if((int)$e->id<$b['first_epoch_id'])return new ProviderDiscoveryResult('pre_baseline','captured_pre_modern_scope');
		}
		$raw=[];$owner_rows=[];$selected=$this->locate($i);
		if($selected!==null)$owner_rows[self::integer($selected->id)]=$selected;
		$keys=$i->keys;ksort($keys,SORT_STRING);
		foreach($keys as $kind=>$value){
			$row=$this->point('provider_identities','identity',$this->db->prepare('provider=%s AND identity_kind=%s AND identity_value=%s',$i->provider,$kind,$value));$raw[$kind]=$row;
			if($row!==null&&$row->claim_id!==null){$id=self::integer($row->claim_id);$claim=$owner_rows[$id]??$this->point('provider_claims','PRIMARY',$this->db->prepare('id=%d',$id));self::check($claim!==null,'identity_owner_missing');$owner_rows[$id]=$claim;}
		}
		ksort($owner_rows,SORT_NUMERIC);$claims=[];
		foreach($owner_rows as $id=>$row){
			self::check(CommerceBaseline::identity($row->installation_id),'claim_installation_invalid');
			if($row->installation_id!==$c->installation_id)return new ProviderDiscoveryResult('foreign','retained_foreign_owner');
			$e=$this->epoch(self::integer($row->epoch_id),$row->epoch_identity);
			if((int)$e->id<$b['first_epoch_id'])return new ProviderDiscoveryResult('pre_baseline','retained_pre_modern_owner');
			self::check((int)$e->id<=(int)$current->id&&((int)$e->id===(int)$current->id?$e->retired_at===null:$e->retired_at!==null),'claim_epoch_lifecycle');
			$claim=self::native_claim($row);self::check($claim->provider()===$i->provider&&$claim->created_at()>=$e->created_at&&($e->retired_at===null||$claim->created_at()<=$e->retired_at),'claim_provider_dates');
			$duplicates=$this->rows($this->db->prepare('SELECT id FROM '.$this->tables->provider_claims().' FORCE INDEX(payment_session_id) WHERE payment_session_id=%d ORDER BY id LIMIT 2',$claim->payment_session_id()));
			self::check(count($duplicates)===1&&(int)$duplicates[0]->id===$id,'ambiguous_session_owner');$claims[$id]=$claim;
		}
		$identities=[];$conflict=false;$duplicate=count($keys)>0;
		foreach($raw as $kind=>$row){
			if($row===null){$identities[$kind]=new ProviderIdentityDiscovery($kind,$keys[$kind],'missing');$duplicate=false;continue;}
			$observed=$this->epoch(self::integer($row->observed_epoch_id));
			if((int)$observed->id<$b['first_epoch_id'])return new ProviderDiscoveryResult('pre_baseline','retained_pre_modern_identity');
			self::check((int)$observed->id<=(int)$current->id&&((int)$observed->id===(int)$current->id?$observed->retired_at===null:$observed->retired_at!==null),'identity_epoch_lifecycle');
			self::check($row->provider===$i->provider&&$row->identity_kind===$kind&&$row->identity_value===$keys[$kind]&&$row->evidence_state==='native'&&in_array($row->binding_state,['bound','unresolved','quarantined'],true)&&($row->binding_state!=='bound'||$row->claim_id!==null)&&($row->binding_state!=='unresolved'||$row->claim_id===null)&&($row->binding_state==='quarantined'||$row->conflict_code===null),'identity_shape');
			self::check(ProviderClaimRecord::valid_date($row->first_seen_at)&&CommerceResetLifecycle::token_valid($row->disposition)&&($row->conflict_code===null||CommerceResetLifecycle::token_valid($row->conflict_code)),'identity_lifecycle');
			self::check($row->environment===null||in_array($row->environment,['test','live'],true),'identity_environment');
			self::check(($row->payload_sha256===null||preg_match('/\A[0-9a-f]{64}\z/',$row->payload_sha256))&&($row->event_type===null||preg_match('/\A[a-zA-Z0-9_.]{1,128}\z/',$row->event_type)),'identity_event_facts');
			if($row->claim_id!==null){$claim=$claims[(int)$row->claim_id];self::check($claim->epoch_id()<=(int)$observed->id&&($row->environment===null||$claim->environment()===$row->environment),'identity_owner_facts');}
			if($kind==='event')self::check(in_array($row->disposition,['pending','processed','ignored','attention'],true)||str_starts_with($row->disposition,'neutralized_'),'identity_event_disposition');
			$mismatch=($row->environment!==null&&$row->environment!==$i->environment)||($kind==='event'&&(($row->payload_sha256!==null&&$row->payload_sha256!==$i->event_hash)||($row->event_type!==null&&$row->event_type!==$i->event_type)));
			$classification=$row->binding_state==='quarantined'?'quarantined':($mismatch?'contradiction':($row->claim_id===null?'unresolved':'compatible'));
			$conflict=$conflict||in_array($classification,['quarantined','contradiction'],true);
			$duplicate=$duplicate&&$classification==='compatible'&&$row->environment===$i->environment&&($kind!=='event'||$row->payload_sha256===$i->event_hash&&$row->event_type===$i->event_type);
			$identities[$kind]=new ProviderIdentityDiscovery($kind,$keys[$kind],$classification,self::integer($row->id),$row->claim_id===null?null:self::integer($row->claim_id),$row->binding_state,$row->evidence_state,(int)$observed->id,$row->first_seen_at,$row->environment,$row->payload_sha256,$row->event_type,$row->disposition,$row->conflict_code);
		}
		if(count($claims)>1)return new ProviderDiscoveryResult('ambiguous','cross_key_owners',identities:$identities,active_reset:$active);
		if(!$claims)return new ProviderDiscoveryResult($conflict?'conflict':(array_filter($identities,fn($k)=>$k->classification==='unresolved')?'unresolved':'unattributable'),'no_native_owner',identities:$identities,active_reset:$active);
		$claim=reset($claims);
		if($i->locator!==null&&$selected===null)$conflict=true;
		if($i->observed_at<$claim->created_at())$conflict=true;
		if(($i->installation!==null&&($claim->installation_id()!==$i->installation||$claim->epoch_identity()!==$i->epoch_identity||$i->epoch_id!==null&&$claim->epoch_id()!==$i->epoch_id))||$claim->environment()!==$i->environment||($i->captured_claim!==null&&!$claim->same_request_as($i->captured_claim)))$conflict=true;
		foreach($identities as $key)if($key->claim_id===null&&$key->observed_epoch_id!==null&&$claim->epoch_id()>$key->observed_epoch_id)$conflict=true;
		$source=$this->epoch($claim->epoch_id(),$claim->epoch_identity());$completed=null;
		if($source->retired_at!==null){
			$r=$this->point('commerce_resets','source_epoch',$this->db->prepare('installation_id=%s AND source_epoch_id=%d',$claim->installation_id(),$claim->epoch_id()));
			self::check($r!==null,'completed_history_missing');$completed=CommerceResetRecord::from_row($r);
			$target=$completed->target_epoch_id()===null?null:$this->epoch($completed->target_epoch_id(),$completed->target_epoch_identity());
			self::check(CommerceResetHistory::completed($completed,$c->installation_id,(int)$current->id,$source,$target),'completed_history_noncanonical');
			$fence=$active===null?'completed_retired':'older_retired_during_active_reset';
		}else{
			self::check($claim->epoch_id()===(int)$current->id,'noncurrent_unretired_source');$fence=$active===null?'current_ready':'active_reset_source';
		}
		if($active!==null){
			self::check($claim->created_at()<=$active->started_at(),'claim_after_reset_start');
			if($fence==='active_reset_source'&&in_array($active->phase(),['deleting','finalizing'],true))self::check($claim->disposition()!=='open','sealed_source_open_claim');
		}
		$d=$this->point('order_deletions','owned_order',$this->db->prepare('installation_id=%s AND epoch_id=%d AND original_order_id=%d',$claim->installation_id(),$claim->epoch_id(),$claim->order_id()));
		$global=$this->rows($this->db->prepare('SELECT id FROM '.$this->tables->order_deletions().' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2',$claim->order_id()));
		self::check(count($global)<=1,'ambiguous_deletion_owner');$deletion=null;
		if($d!==null){
			$deletion=OrderDeletionRecord::from_row($d);self::check(count($global)===1&&(int)$global[0]->id===$deletion->id()&&$deletion->epoch_identity()===$claim->epoch_identity()&&$claim->disposition()==='deleted'&&$deletion->deleted_at()===$claim->tombstoned_at()&&$deletion->deleted_at()>=$claim->created_at()&&($source->retired_at===null||$deletion->deleted_at()<=$source->retired_at),'deletion_claim_conflict');
		}else self::check(!$global&&$claim->disposition()!=='deleted','deleted_evidence_missing_or_foreign');
		if($claim->disposition()==='retired'){
			$operation=$completed??$active;self::check($operation!==null&&$claim->tombstoned_at()>=$operation->started_at()&&$claim->tombstoned_at()<=$operation->updated_at(),'retirement_dates');
		}
		if($fence==='current_ready')self::check($claim->disposition()!=='retired','ready_retired_claim');
		$owner=new ProviderOwnerDiscovery($claim->id(),$claim->reference(),$claim->provider(),$claim->installation_id(),$claim->epoch_id(),$claim->epoch_identity(),$claim->order_id(),$claim->payment_session_id(),$claim->environment(),$deletion!==null?'permanently_deleted':($claim->disposition()==='retired'||$completed!==null?'reset_retired':'open'),$claim->disposition(),$claim->tombstoned_at(),$owner_rows[$claim->id()]->creation_key_hash,$owner_rows[$claim->id()]->idempotency_key,hash('sha256',$owner_rows[$claim->id()]->request_snapshot));
		return new ProviderDiscoveryResult($conflict?'conflict':'attributed',$conflict?'envelope_contradiction':'coherent',$owner,$fence,$active,$completed,$deletion,$identities,!$conflict&&$duplicate);
	}
}
