<?php
/** Durable inbound payment-event persistence. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use wpdb;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
require_once __DIR__ . '/class-provider-registry.php';
require_once __DIR__ . '/class-epoch-ownership.php';
require_once __DIR__ . '/class-payment-event-continuation.php';
final class PaymentEventRepository {
	private const COLUMNS='epoch_id, ownership_state, id, provider, provider_event_id, event_type, provider_created_at, livemode, payment_session_id, provider_session_id, local_reference, provider_payment_id, currency, amount_minor, payment_status, session_status, processing_status, result_code, attempt_count, received_at, last_attempt_at, processed_at, attention_at, error_code, error_detail, payload_sha256, normalized_payload';
	private const MAX_ERROR_DETAIL_BYTES=500; private const MAX_NORMALIZED_PAYLOAD_BYTES=65535;
	public function registry(): ProviderRegistry { return new ProviderRegistry($this->wpdb,$this->tables); }
	public function continuation(?callable $connections=null): PaymentEventContinuation { return new PaymentEventContinuation($this->wpdb,$this->tables,$connections); }
	/** Read-only predicate on this connection; caller owns the exact normal fence. */
	public function inspect_financial(CommittedOperation $operation, PaymentFinancialStageInspection $stage): OperationalInspectionResult { return $stage->inspect_current($operation,$this->wpdb,$this->tables); }

	private wpdb $wpdb; private TableNames $tables;
	public function __construct(wpdb $wpdb,TableNames $tables){$this->wpdb=$wpdb;$this->tables=$tables;}
	public function insert_receipt(string $provider,string $provider_event_id,string $event_type,?string $provider_created_at,bool $livemode,string $received_at,string $payload_sha256,?string $normalized_payload=null):PaymentEventInsertResult {
		return PaymentEventInsertResult::invalid_input(); // Incomplete receipts cannot establish managed scope.

	}
	/** Inserts the complete immutable normalized webhook receipt in one statement. */
	public function insert_complete_receipt( PaymentEventReceipt $receipt, string $received_at ): PaymentEventReceiptResult {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return PaymentEventReceiptResult::result( PaymentEventReceiptResult::DATABASE_FAILURE ); }
		if (!in_array($this->registry()->route($receipt,TransactionManager::context_on($this->wpdb))['status'],['active','retired','absent'],true)) return PaymentEventReceiptResult::result(PaymentEventReceiptResult::INVALID_INPUT);
		if ( '' === $received_at ) { return PaymentEventReceiptResult::result( PaymentEventReceiptResult::INVALID_INPUT ); }
		$this->wpdb->last_error = '';
		$result = $this->wpdb->insert( $this->tables->payment_events(), array(
			'provider'=>$receipt->provider(), 'provider_event_id'=>$receipt->provider_event_id(), 'event_type'=>$receipt->event_type(), 'provider_created_at'=>$receipt->provider_created_at(), 'livemode'=>$receipt->livemode()?1:0,
			'payment_session_id'=>null, 'provider_session_id'=>$receipt->provider_session_id(), 'local_reference'=>$receipt->local_reference(), 'provider_payment_id'=>$receipt->provider_payment_id(), 'currency'=>$receipt->currency(), 'amount_minor'=>$receipt->amount_minor(), 'payment_status'=>$receipt->payment_status(), 'session_status'=>$receipt->session_status(),
			'processing_status'=>PaymentEventStatuses::RECEIVED, 'result_code'=>null, 'attempt_count'=>0, 'received_at'=>$received_at, 'last_attempt_at'=>null, 'processed_at'=>null, 'attention_at'=>null, 'error_code'=>null, 'error_detail'=>null, 'payload_sha256'=>$receipt->payload_sha256(), 'normalized_payload'=>$receipt->normalized_payload(),
		), array('%s','%s','%s',null,'%d',null,null=== $receipt->provider_session_id()?null:'%s',null=== $receipt->local_reference()?null:'%s',null=== $receipt->provider_payment_id()?null:'%s',null=== $receipt->currency()?null:'%s',null=== $receipt->amount_minor()?null:'%d',null=== $receipt->payment_status()?null:'%s',null=== $receipt->session_status()?null:'%s','%s',null,'%d','%s',null,null,null,null,null,'%s',null=== $receipt->normalized_payload()?null:'%s') );
		if ( false !== $result && $this->wpdb->insert_id > 0 ) { $event=$this->find((int)$this->wpdb->insert_id); return $event instanceof PaymentEventRecord ? PaymentEventReceiptResult::result(PaymentEventReceiptResult::INSERTED,$event) : PaymentEventReceiptResult::result(PaymentEventReceiptResult::DATABASE_FAILURE); }
		if ( ! $this->is_duplicate_error() ) { return PaymentEventReceiptResult::result(PaymentEventReceiptResult::DATABASE_FAILURE); }
		$event=$this->find_by_provider_event_id($receipt->provider(),$receipt->provider_event_id());
		if ( false === $event ) { return PaymentEventReceiptResult::result(PaymentEventReceiptResult::DATABASE_FAILURE); }
		if ( ! $event instanceof PaymentEventRecord ) { return PaymentEventReceiptResult::result(PaymentEventReceiptResult::DATABASE_FAILURE); }
		$coherent=$event->provider()===$receipt->provider()&&$event->provider_event_id()===$receipt->provider_event_id()&&$event->event_type()===$receipt->event_type()&&$event->livemode()===$receipt->livemode()&&hash_equals($event->payload_sha256(),$receipt->payload_sha256());
		return PaymentEventReceiptResult::result($coherent?PaymentEventReceiptResult::DUPLICATE_COHERENT:PaymentEventReceiptResult::DUPLICATE_INTEGRITY_CONFLICT,$event);
	}
	/** @return PaymentEventRecord|false|null */ public function find(int $id){return $this->read_one($id>0?$this->wpdb->prepare('SELECT '.self::COLUMNS.' FROM '.$this->tables->payment_events().' WHERE id = %d LIMIT 1',$id):'');}
	/** @return PaymentEventRecord|false|null */ public function find_for_update(int $id){return $this->read_one($id>0?$this->wpdb->prepare('SELECT '.self::COLUMNS.' FROM '.$this->tables->payment_events().' WHERE id = %d LIMIT 1 FOR UPDATE',$id):'');}
	/** @return PaymentEventRecord|false|null */ public function find_by_provider_event_id(string $provider,string $event_id){return $this->read_one($this->valid_token($provider,32)&&$this->valid_text($event_id,255)?$this->wpdb->prepare('SELECT '.self::COLUMNS.' FROM '.$this->tables->payment_events().' WHERE provider = %s AND provider_event_id = %s LIMIT 1',$provider,$event_id):'');}
	/** @return PaymentEventRecord|false|null */ public function find_by_provider_event_id_for_update(string $provider,string $event_id){return $this->read_one($this->valid_token($provider,32)&&$this->valid_text($event_id,255)?$this->wpdb->prepare('SELECT '.self::COLUMNS.' FROM '.$this->tables->payment_events().' WHERE provider = %s AND provider_event_id = %s LIMIT 1 FOR UPDATE',$provider,$event_id):'');}
	/** A verified expiration is represented by the processed event produced by PaymentExpirationService. @return bool|null */
	public function has_processed_expiration_for_session( int $session_id ): ?bool {
		if ( $session_id <= 0 ) { return false; }
		$this->wpdb->last_error = '';
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT 1 FROM ' . $this->tables->payment_events() . ' WHERE payment_session_id = %d AND event_type = %s AND processing_status = %s AND result_code IN (%s, %s) LIMIT 1', $session_id, 'checkout.session.expired', PaymentEventStatuses::PROCESSED, 'checkout_session_expired', 'already_expired' ) );
		return '' !== $this->wpdb->last_error ? null : null !== $value;
	}
	public function bind_epoch(int $id,int $epoch):bool {
		$context=TransactionManager::context_on($this->wpdb);if(null===$context||$context->epoch_id()!==$epoch){return false;}
		return false!==$this->wpdb->query($this->wpdb->prepare("UPDATE ".$this->tables->payment_events()." SET epoch_id=%d, ownership_state='bound' WHERE id=%d AND ownership_state IN ('unresolved','bound') AND (epoch_id IS NULL OR epoch_id=%d)",$epoch,$id,$epoch));
	}
	public function quarantine_ownership(int $id):bool {
		return TransactionManager::admitted_on($this->wpdb)&&false!==$this->wpdb->update($this->tables->payment_events(),array('ownership_state'=>'quarantined'),array('id'=>$id),array('%s'),array('%d'));
	}
	/** @deprecated Webhook code must use bind_association(), which never overwrites identity. */
	public function bind_mapping(int $id,?int $payment_session_id,?string $provider_session_id,?string $local_reference,?string $provider_payment_id,?string $currency,?int $amount_minor,?string $payment_status,?string $session_status):bool {
		// Overwriting receipt identity is no longer supported. Use bind_association().
		return false;
	}

	public function bind_association( int $id, int $payment_session_id, ?string $provider_session_id, ?string $local_reference, ?string $provider_payment_id, ?string $currency, ?int $amount_minor ): PaymentEventAssociationResult {
		$context=TransactionManager::context_on($this->wpdb);$event=$this->find($id);
		if(null===$context||!$event instanceof PaymentEventRecord){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::CONFLICT);}
		$route=$this->registry()->route($event,$context);
		if('active'!==$route['status']||(int)$route['claim']->payment_session_id!==$payment_session_id){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::CONFLICT);}

		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->mutable_event($id) || ! EpochOwnership::owns($this->wpdb,$this->tables,$this->tables->payment_sessions(),$payment_session_id) ) { return PaymentEventAssociationResult::result( PaymentEventAssociationResult::DATABASE_FAILURE ); }
		if($id<=0||$payment_session_id<=0||(null!==$provider_session_id&&!$this->valid_text($provider_session_id,255))||(null!==$local_reference&&1!==preg_match('/\A[0-9a-f]{32}\z/',$local_reference))||(null!==$provider_payment_id&&!$this->valid_text($provider_payment_id,255))||(null!==$currency&&!isset(Money::get_currencies()[$currency]))||(null!==$amount_minor&&$amount_minor<0)){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::INVALID_INPUT);}
		$current=$this->find($id); if(false===$current){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::DATABASE_FAILURE);} if(null===$current){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::NOT_FOUND);} if(!in_array($current->processing_status(),array(PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED),true)){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::STATE_CONFLICT);}
		$source_status=$current->processing_status();
		$requested=array('payment_session_id'=>$payment_session_id,'provider_session_id'=>$provider_session_id,'local_reference'=>$local_reference,'provider_payment_id'=>$provider_payment_id,'currency'=>$currency,'amount_minor'=>$amount_minor);
		$existing=array('payment_session_id'=>$current->payment_session_id(),'provider_session_id'=>$current->provider_session_id(),'local_reference'=>$current->local_reference(),'provider_payment_id'=>$current->provider_payment_id(),'currency'=>$current->currency(),'amount_minor'=>$current->amount_minor()); $data=array(); $formats=array();
		foreach($requested as $field=>$value){if(null===$value){continue;}if(null!==$existing[$field]&&$existing[$field]!==$value){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::CONFLICT);}if(null===$existing[$field]){$data[$field]=$value;$formats[]=in_array($field,array('payment_session_id','amount_minor'),true)?'%d':'%s';}}
		if(array()===$data){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::ALREADY_BOUND);}
		$where=array('id'=>$id,'processing_status'=>$source_status);$where_formats=array('%d','%s');foreach(array_keys($data)as$field){$where[$field]=null;$where_formats[]=null;}
		$result=$this->wpdb->update($this->tables->payment_events(),$data,$where,$formats,$where_formats);
		if(false===$result){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::DATABASE_FAILURE);} if($result>0){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::BOUND);}
		$after=$this->find($id); if(false===$after){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::DATABASE_FAILURE);} if(null===$after){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::NOT_FOUND);} if($source_status!==$after->processing_status()){return PaymentEventAssociationResult::result(PaymentEventAssociationResult::STATE_CONFLICT);} $same=$after->payment_session_id()===$payment_session_id;foreach(array('provider_session_id'=>$provider_session_id,'local_reference'=>$local_reference,'provider_payment_id'=>$provider_payment_id,'currency'=>$currency,'amount_minor'=>$amount_minor)as$method=>$value){if(null!==$value&&$after->$method()!==$value){$same=false;}}return PaymentEventAssociationResult::result($same?PaymentEventAssociationResult::ALREADY_BOUND:PaymentEventAssociationResult::CONFLICT);
	}
	/** Terminalizes a source snapshot only after a caller has proved a confirmed conversion rollback. */
	public function mark_attention_after_confirmed_rollback( int $id, string $expected_status, int $expected_attempt_count, ?string $expected_last_attempt_at, string $expected_payload_sha256, string $result_code, string $attention_at ): PaymentEventAttentionResult {
		return $this->attention_snapshot_cas($id,$expected_status,$expected_attempt_count,$expected_last_attempt_at,$expected_payload_sha256,$result_code,$attention_at);
	}
	private function attention_snapshot_cas( int $id, string $expected_status, int $expected_attempt_count, ?string $expected_last_attempt_at, string $expected_payload_sha256, string $result_code, string $attention_at ): PaymentEventAttentionResult {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->mutable_event($id) ) { return PaymentEventAttentionResult::result( PaymentEventAttentionResult::DATABASE_FAILURE ); }
		if($id<=0||!in_array($expected_status,array(PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED),true)||$expected_attempt_count<0||(null!==$expected_last_attempt_at&&''===$expected_last_attempt_at)||1!==preg_match('/\A[0-9a-f]{64}\z/',$expected_payload_sha256)||!$this->valid_token($result_code,64)||''===$attention_at){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::INVALID_INPUT);}
		$current=$this->find($id);if(false===$current){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::DATABASE_FAILURE);}if(null===$current){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::NOT_FOUND);}
		if(PaymentEventStatuses::ATTENTION===$current->processing_status()&&$current->result_code()===$result_code&&$current->attempt_count()===$expected_attempt_count&&$current->last_attempt_at()===$expected_last_attempt_at&&hash_equals($current->payload_sha256(),$expected_payload_sha256)){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::ALREADY_ATTENTION);}
		if($current->processing_status()!==$expected_status||$current->attempt_count()!==$expected_attempt_count||$current->last_attempt_at()!==$expected_last_attempt_at||!hash_equals($current->payload_sha256(),$expected_payload_sha256)){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::STATE_CONFLICT);}
		$result=$this->wpdb->update($this->tables->payment_events(),array('processing_status'=>PaymentEventStatuses::ATTENTION,'result_code'=>$result_code,'processed_at'=>$attention_at,'attention_at'=>$attention_at,'error_code'=>null,'error_detail'=>null),array('id'=>$id,'processing_status'=>$expected_status,'attempt_count'=>$expected_attempt_count,'last_attempt_at'=>$expected_last_attempt_at,'payload_sha256'=>$expected_payload_sha256),array('%s','%s','%s','%s',null,null),array('%d','%s','%d',null===$expected_last_attempt_at?null:'%s','%s'));
		if(false===$result){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::DATABASE_FAILURE);}if($result>0){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::TRANSITIONED);}return PaymentEventAttentionResult::result(PaymentEventAttentionResult::STATE_CONFLICT);
	}
	/** Terminalizes deterministic pre-conversion failures; processing is deliberately excluded. */
	public function mark_source_attention( int $id, string $expected_status, int $expected_attempt_count, ?string $expected_last_attempt_at, string $expected_payload_sha256, string $result_code, string $attention_at ): PaymentEventAttentionResult {
		return $this->source_attention_cas($id,$expected_status,$expected_attempt_count,$expected_last_attempt_at,$expected_payload_sha256,$result_code,$attention_at);
	}
	private function source_attention_cas(int $id,string $expected_status,int $attempts,?string $last,string $hash,string $code,string $at):PaymentEventAttentionResult {
		if(!in_array($expected_status,array(PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED),true)){return PaymentEventAttentionResult::result(PaymentEventAttentionResult::INVALID_INPUT);}
		return $this->attention_snapshot_cas($id,$expected_status,$attempts,$last,$hash,$code,$at);
	}
	/** Exceptional quarantine only; this is not a retry or lease-recovery transition. */
	public function quarantine_anomalous_processing(int $id,string $result_code,string $at):bool {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->mutable_event($id) ) { return false; }
		if($id<=0||!$this->valid_token($result_code,64)||''===$at){return false;}
		$r=$this->wpdb->update($this->tables->payment_events(),array('processing_status'=>PaymentEventStatuses::ATTENTION,'result_code'=>$result_code,'processed_at'=>$at,'attention_at'=>$at),array('id'=>$id,'processing_status'=>PaymentEventStatuses::PROCESSING),array('%s','%s','%s','%s'),array('%d','%s'));
		return false!==$r&&$r>0;
	}
	public function mark_processing(int $id,string $expected_status,string $attempted_at):bool {
		$owned=$this->find($id);if(!$owned instanceof PaymentEventRecord||'bound'!==$owned->ownership_state()){return false;}
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->mutable_event($id) ) { return false; }
		if($id<=0||!in_array($expected_status,array(PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED),true)||''===$attempted_at){return false;}
		$result=$this->wpdb->query($this->wpdb->prepare('UPDATE '.$this->tables->payment_events().' SET processing_status = %s, attempt_count = attempt_count + 1, last_attempt_at = %s, error_code = NULL, error_detail = NULL WHERE id = %d AND processing_status = %s',PaymentEventStatuses::PROCESSING,$attempted_at,$id,$expected_status));
		return false!==$result&&$result>0;
	}
	public function mark_terminal(int $id,string $expected_status,string $terminal_status,string $result_code,string $processed_at):bool {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->mutable_event($id) ) { return false; }
		if($id<=0||!in_array($expected_status,array(PaymentEventStatuses::RECEIVED,PaymentEventStatuses::PROCESSING,PaymentEventStatuses::RETRYABLE_FAILED),true)||!PaymentEventStatuses::is_terminal($terminal_status)||!$this->valid_token($result_code,64)||''===$processed_at){return false;}
		$data=array('processing_status'=>$terminal_status,'result_code'=>$result_code,'processed_at'=>$processed_at,'error_code'=>null,'error_detail'=>null); $formats=array('%s','%s','%s',null,null);
		if(PaymentEventStatuses::ATTENTION===$terminal_status){$data['attention_at']=$processed_at;$formats[]='%s';}
		$result=$this->wpdb->update($this->tables->payment_events(),$data,array('id'=>$id,'processing_status'=>$expected_status),$formats,array('%d','%s'));
		return false!==$result&&$result>0;
	}
	public function mark_retryable_failed(int $id,string $error_code,string $error_detail,string $attempted_at):bool {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->mutable_event($id) ) { return false; }
		if($id<=0||!$this->valid_token($error_code,64)||''===$attempted_at){return false;}
		$detail=$this->safe_error_detail($error_detail); $result=$this->wpdb->update($this->tables->payment_events(),array('processing_status'=>PaymentEventStatuses::RETRYABLE_FAILED,'error_code'=>$error_code,'error_detail'=>$detail,'last_attempt_at'=>$attempted_at),array('id'=>$id,'processing_status'=>PaymentEventStatuses::PROCESSING),array('%s','%s','%s','%s'),array('%d','%s'));
		return false!==$result&&$result>0;
	}
	/** @return PaymentEventRecord|false|null */ private function read_one(string $query){if(''===$query){return null;}$this->wpdb->last_error='';$row=$this->wpdb->get_row($query);if(''!==$this->wpdb->last_error){return false;}return null===$row?null:PaymentEventRecord::from_row($row);}
	private function mutable_event(int $id):bool {
		$context=TransactionManager::context_on($this->wpdb);if(null===$context){return false;}
		$event=$this->find($id);if(!$event instanceof PaymentEventRecord){return false;}
		if(null!==$event->epoch_id()){return $event->epoch_id()===$context->epoch_id();}
		$identity=$this->registry()->identity($event->provider(),'event',$event->provider_event_id());
		return is_object($identity)&&(int)$identity->observed_epoch_id===$context->epoch_id();
	}
	private function is_duplicate_error():bool {if(isset($this->wpdb->last_errno)&&(int)$this->wpdb->last_errno===1062){return true;}return false!==stripos((string)$this->wpdb->last_error,'duplicate');}
	private function valid_text(string $value,int $max):bool{return ''!==trim($value)&&strlen($value)<=$max;} private function valid_token(string $value,int $max):bool{return strlen($value)<=$max&&1===preg_match('/\A[a-z0-9_]+\z/',$value);}
	private function valid_normalized_payload(?string $payload):bool {return null===$payload||(strlen($payload)<=self::MAX_NORMALIZED_PAYLOAD_BYTES&&is_array(json_decode($payload,true))&&JSON_ERROR_NONE===json_last_error());}
	private function safe_error_detail(string $detail):string {$detail=strip_tags($detail);$detail=(string)preg_replace(array('/sk_(?:test|live)_[A-Za-z0-9_-]+/i','/whsec_[A-Za-z0-9_-]+/i','/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]+/i','/[A-Za-z0-9+\/_=-]{40,}/'),array('[redacted]','[redacted]','[redacted]','[redacted]'),$detail);$detail=trim((string)preg_replace('/[\x00-\x1F\x7F]+/',' ',$detail));return strlen($detail)>self::MAX_ERROR_DETAIL_BYTES?substr($detail,0,self::MAX_ERROR_DETAIL_BYTES-3).'...':$detail;}
}
