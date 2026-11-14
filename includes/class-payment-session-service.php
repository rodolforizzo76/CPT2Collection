<?php
/** Generic three-phase payment-session orchestration. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use Throwable;
defined( 'ABSPATH' ) || exit;
final class PaymentSessionService {
	private ProviderResponseEvidence $response_evidence;
	private string $provider_evidence_code='provider_evidence_failed';
	private const STRIPE_SAFE_IDEMPOTENCY_RETRY_SECONDS = 86400;
	private OrderRepository $orders; private ReservationRepository $reservations; private PaymentSessionRepository $sessions; private TransactionManager $transactions; private ReservationSetValidator $validator; private PaymentProviderInterface $provider; private UtcClock $clock; private ?OrderDeletionRegistry $deletions;
	public function __construct( OrderRepository $orders, ReservationRepository $reservations, PaymentSessionRepository $sessions, TransactionManager $transactions, ReservationSetValidator $validator, PaymentProviderInterface $provider, UtcClock $clock, ?OrderDeletionRegistry $deletions = null, ?ProviderResponseEvidence $response_evidence = null ) { $this->response_evidence=$response_evidence??$sessions->response_evidence(); $this->orders = $orders; $this->reservations = $reservations; $this->sessions = $sessions; $this->transactions = $transactions; $this->validator = $validator; $this->provider = $provider; $this->clock = $clock; $this->deletions = $deletions; }

	public function create( int $order_id, string $payment_creation_key, ?CommerceContext $expected = null, ?string $browser_context = null ): PaymentSessionResult {
		$this->provider_evidence_code='provider_evidence_failed';
		if ( $order_id <= 0 || ! $this->valid_creation_key( $payment_creation_key ) || ! $this->valid_provider_name( $this->provider->name() ) ) { return $this->failure( 'order_not_eligible', __( 'The payment session request is invalid.', 'cpt2collection' ), $order_id > 0 ? $order_id : null ); }
		if ( ! $this->transactions->supports_payment_session_persistence() || $this->transactions->is_active() ) { return $this->failure( $this->transactions->is_outcome_uncertain() ? 'transaction_outcome_unknown' : 'database_error', __( 'Payment-session transactions are not supported.', 'cpt2collection' ), $order_id ); }
		$hash = hash( 'sha256', $payment_creation_key );
		$claim = $this->claim( $order_id, $hash, $expected, $browser_context );
		if ( $claim instanceof PaymentSessionResult ) { if($browser_context!==null&&$claim->get_code()==='transaction_outcome_unknown')$this->sessions->checkout_carts()->discard_connection();return $claim; }
		$expected = $claim['context']; $session = $claim['session'];
		return $this->finish_provider_request($order_id,$session,$expected,$claim['request'],$claim['native_claim']);
	}

	/** The captured source travels across the network gap; no current-epoch inference. */
	private function finish_provider_request(int $order_id, PaymentSessionRecord $session, CommerceContext $expected, PaymentProviderSessionRequest $request, ?ProviderClaimRecord $native_claim=null): PaymentSessionResult {
		if($this->provider->name()!==$session->provider())return $this->failure('provider_evidence_failed',__( 'The originating provider does not match the native request.', 'cpt2collection' ),$order_id);
		// Older internal callers can resume a committed request; capture BEFORE HTTP.
		if($native_claim===null && $session->provider()==='stripe_checkout'){
			try {$row=$this->sessions->registry()->claim($session->reference());$native_claim=is_object($row)?ProviderClaimRecord::from_row($row):null;}catch(Throwable $error){}
		}
		try { $provider_result = $this->provider->create_session( $request ); }
		catch ( PaymentProviderException $error ) {
			if ( ( null !== $error->provider_session_id() || null !== $error->provider_payment_id() ) && ! $this->record_identity($session,$expected, $error->provider_session_id(), $error->provider_payment_id(),$native_claim) ) { return $this->failure($this->provider_evidence_code,__( 'Provider evidence could not be recorded.', 'cpt2collection' ),$order_id); }
			$deleted = $this->probe_deleted_after_provider( $order_id, $expected );
			if ( $deleted instanceof PaymentSessionResult ) { return $deleted; }
			if ( PaymentProviderException::REJECTED === $error->failure_type() ) { return $this->fail_attempt( $session, 'provider_rejected', __( 'The payment provider rejected the session request.', 'cpt2collection' ), $expected ); }
			if ( PaymentProviderException::WINDOW_TOO_SHORT === $error->failure_type() ) { return PaymentSessionResult::failure( 'payment_window_too_short', __( 'The remaining reservation window is too short to create a payment session.', 'cpt2collection' ), $order_id, $session ); }
			$code = PaymentProviderException::UNAVAILABLE === $error->failure_type() ? 'provider_unavailable' : 'provider_response_unknown';
			return PaymentSessionResult::failure( $code, __( 'The provider result could not be reconciled. Retry this same payment attempt.', 'cpt2collection' ), $order_id, $session );
		} catch ( Throwable $error ) { return $this->probe_deleted_after_provider( $order_id, $expected ) ?? PaymentSessionResult::failure( 'provider_response_unknown', __( 'The provider result could not be reconciled. Retry this same payment attempt.', 'cpt2collection' ), $order_id, $session ); }
		if ( ! $this->record_identity($session,$expected,$provider_result->provider_session_id(),$provider_result->provider_payment_id(),$native_claim) ) { return $this->failure($this->provider_evidence_code,__( 'Provider evidence could not be recorded.', 'cpt2collection' ),$order_id); }
		return $this->reconcile( $order_id, $session, $provider_result, $expected );
	}

	/** @return array{session:PaymentSessionRecord,order:OrderRecord,items:array<int,OrderItem>,deadline:string}|PaymentSessionResult */
	private function claim( int $order_id, string $hash, ?CommerceContext $expected, ?string $browser_context = null ) {
		if ( ! $this->transactions->begin_commerce_shared($expected) ) { return $this->begin_failure( __( 'The payment-session claim could not start.', 'cpt2collection' ), $order_id ); }
		try {
   if($browser_context!==null){
	// Routing DATA only. Revalidate the parent after Cart -> Order locks below.
	$route=$this->orders->discover_reconciliation_cart($order_id);$carts=$this->sessions->checkout_carts();
	if(false===$route)return $this->rollback_failure('database_error',__( 'The original checkout parent could not be read.', 'cpt2collection' ),$order_id);
	$cart=is_object($route)?$carts->find_for_update((int)$route->cart_id):null;
	if(!$cart instanceof CartRecord||!CheckoutContextToken::verify($browser_context,$cart,$this->transactions->commerce_context(),$this->clock->now_timestamp()))return $this->rollback_failure('stale_checkout_context',__( 'The checkout context changed before payment creation.', 'cpt2collection' ),$order_id);
   }
			$order = $this->orders->find_for_update( $order_id );
   if($browser_context!==null&&(!$order instanceof OrderRecord||$order->cart_id()!==$cart->id()||$order->cart_revision()!==$cart->content_revision()||$order->checkout_generation()!==$cart->checkout_generation()))return $this->rollback_failure('stale_checkout_context',__( 'The order no longer matches this checkout context.', 'cpt2collection' ),$order_id);

			if ( ! $order instanceof OrderRecord ) { $deleted = $this->finish_deleted_in_active_transaction( $order_id ); if ( $deleted instanceof PaymentSessionResult ) { return $deleted; } }
			if ( false === $order ) { return $this->rollback_failure( 'database_error', __( 'The order could not be locked.', 'cpt2collection' ), $order_id ); }
			if ( null === $order ) { return $this->rollback_failure( 'order_not_found', __( 'The requested order does not exist.', 'cpt2collection' ), $order_id ); }
			$existing = $this->sessions->find_by_creation_key( $order_id, $hash );
			if ( false === $existing ) { return $this->rollback_failure( 'database_error', __( 'The payment attempt could not be read.', 'cpt2collection' ), $order_id ); }
			if($existing instanceof PaymentSessionRecord && null!==$existing->reconciliation_required_at()){return $this->rollback_failure('payment_session_reconciliation_required',__( 'The payment attempt requires reconciliation.', 'cpt2collection' ),$order_id,$existing);}
			if ( $existing instanceof PaymentSessionRecord && PaymentSessionStatuses::CREATING === $existing->status() ) {
				$proof=$this->sessions->registry()->claim($existing->reference());
				if(false===$proof){return $this->rollback_failure('database_error',__( 'The durable claim could not be read.', 'cpt2collection' ),$order_id);}
				if(null===$proof||(int)$proof->request_version!==2||null===$proof->request_snapshot){return $this->rollback_failure('payment_identity_conflict',__( 'The original provider request is unavailable; automatic creation retry is blocked.', 'cpt2collection' ),$order_id,$existing);}
			}
			$slot = $this->sessions->find_slot_owner_for_order( $order_id );
			if ( false === $slot ) { return $this->rollback_failure( 'database_error', __( 'The payment-session slot could not be read.', 'cpt2collection' ), $order_id ); }
			$now = $this->clock->now_mysql();
			$slot_owned_by_key = null !== $slot && null !== $existing && $slot->id() === $existing->id();
			if ( null !== $slot && PaymentSessionStatuses::CREATING === $slot->status() && ! $slot_owned_by_key ) { return $this->rollback_failure( 'payment_session_creation_in_progress', __( 'Another payment session is being created for this order. Retry later.', 'cpt2collection' ), $order_id, $slot ); }
			$active_replay = null !== $slot && PaymentSessionStatuses::ACTIVE === $slot->status();
			if ( OrderStatuses::AWAITING_PAYMENT === $order->status() && ! $active_replay ) { return $this->rollback_failure( 'payment_session_conflict', __( 'The awaiting-payment order cannot create a replacement session.', 'cpt2collection' ), $order_id, $slot ); }
			if ( ! $active_replay && OrderStatuses::PENDING !== $order->status() ) { return $this->rollback_failure( 'order_not_eligible', __( 'The order is not eligible for payment-session creation.', 'cpt2collection' ), $order_id, $existing ); }
			if ( ! $active_replay && null !== $existing && PaymentSessionStatuses::CREATING !== $existing->status() ) { return $this->rollback_failure( 'payment_session_conflict', __( 'This payment key belongs to a terminal session.', 'cpt2collection' ), $order_id, $existing ); }
			if ( ! $active_replay && null !== $slot && ! $slot_owned_by_key ) { return $this->rollback_failure( 'payment_session_conflict', __( 'Another payment session owns this order.', 'cpt2collection' ), $order_id, $slot ); }
			if ( ! $active_replay && null !== $existing && 'stripe_checkout' === $existing->provider() && $this->clock->now_timestamp() - $this->clock->mysql_to_timestamp( $existing->created_at() ) >= self::STRIPE_SAFE_IDEMPOTENCY_RETRY_SECONDS ) { return $this->rollback_failure( 'payment_session_reconciliation_required', __( 'This Stripe payment attempt is too old to retry automatically and requires manual reconciliation.', 'cpt2collection' ), $order_id, $existing ); }
			$items = $this->orders->get_items_for_acquisition( $order_id );
			if ( false === $items ) { return $this->rollback_failure( 'database_error', __( 'The order items could not be read.', 'cpt2collection' ), $order_id, $existing ); }
			if ( array() === $items ) { return $this->rollback_failure( 'order_not_eligible', __( 'The order has no immutable items.', 'cpt2collection' ), $order_id, $existing ); }
			if ( ! $this->valid_order_snapshot( $order, $items ) ) { return $this->rollback_failure( 'order_not_eligible', __( 'The immutable order snapshot is invalid.', 'cpt2collection' ), $order_id, $existing ); }
			$reservations = $this->reservations->get_for_order_and_items_for_acquisition( $order_id, array_map( static fn( OrderItem $item ): int => $item->id(), $items ) );
			if ( false === $reservations ) { return $this->rollback_failure( 'database_error', __( 'The reservations could not be read.', 'cpt2collection' ), $order_id, $existing ); }
			$coverage = $this->validator->validate( $order_id, $items, $reservations, $now );
			if ( ! $coverage->is_valid() ) { return $this->rollback_failure( $coverage->code(), __( 'The order does not have an exact active reservation set.', 'cpt2collection' ), $order_id, $existing ); }
			if ( $active_replay ) {
				$registry=$this->sessions->registry();$claim=$registry->claim($slot->reference(),true);
				if(!is_object($claim)||!$registry->matches($claim,$slot,$this->transactions->commerce_context())) { return $this->rollback_failure('payment_identity_conflict',__( 'The provider claim is inconsistent.', 'cpt2collection' ),$order_id); }
				$identity=null===$slot->provider_session_id()?null:$registry->identity($slot->provider(),'session',$slot->provider_session_id(),true);
				if(!is_object($identity)||'bound'!==$identity->binding_state||(int)$identity->claim_id!==(int)$claim->id){return $this->rollback_failure('payment_identity_conflict',__( 'The active provider identity is inconsistent.', 'cpt2collection' ),$order_id);}
				if ( ! $this->valid_active_replay( $slot, $order, $now, (string) $coverage->deadline() ) ) { return $this->rollback_failure( 'payment_session_conflict', __( 'The active payment session is inconsistent with the order or reservation state.', 'cpt2collection' ), $order_id, $slot ); }
				if ( ! $this->transactions->commit() ) { return $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the payment-session replay transaction outcome.', 'cpt2collection' ), $order_id ); }
				return PaymentSessionResult::success( 'payment_session_already_active', __( 'The order already has an active payment session.', 'cpt2collection' ), $slot );
			}
			$new_attempt = null === $existing;
			if ( null === $existing ) {
	if($browser_context!==null){$retained=$this->sessions->retained_attempt_exists($order_id,$hash);if($retained!==false)return $this->rollback_failure($retained===null?'database_error':'payment_session_reconciliation_required',__( 'The original payment attempt cannot be recreated from retained evidence.', 'cpt2collection' ),$order_id);}
				try { $reference = bin2hex( random_bytes( 16 ) ); } catch ( Throwable $error ) { return $this->rollback_failure( 'payment_session_persistence_failed', __( 'A secure payment-session reference could not be generated.', 'cpt2collection' ), $order_id ); }
				$existing = $this->sessions->create_attempt( $reference, $order_id, $this->provider->name(), $hash, $order->currency(), $order->total_minor(), $now );
				if ( false === $existing ) { return $this->rollback_failure( 'payment_session_persistence_failed', __( 'The durable payment attempt could not be created.', 'cpt2collection' ), $order_id ); }
			}
			if ( $existing->provider() !== $this->provider->name() || $existing->currency() !== $order->currency() || $existing->amount_minor() !== $order->total_minor() ) { return $this->rollback_failure( 'payment_session_conflict', __( 'The durable payment attempt does not match the order.', 'cpt2collection' ), $order_id, $existing ); }
			$context = $this->transactions->commerce_context();
			$registry=$this->sessions->registry();
			$durable=$registry->claim($existing->reference());
			if ( false === $durable ) { return $this->rollback_failure('database_error',__( 'The provider registry could not be read.', 'cpt2collection' ),$order_id); }
			if ( ! $new_attempt && (null === $durable || (int)$durable->request_version !== 2 || null === $durable->request_snapshot) ) { return $this->rollback_failure('payment_identity_conflict',__( 'The durable provider request is inconsistent; automatic creation retry is blocked.', 'cpt2collection' ),$order_id,$existing); }
			if ( $new_attempt ) {
				$config=$this->provider instanceof StripeCheckoutProvider ? $this->provider->request_configuration($context) : array();
				// Provider line-item names are customer-facing plain text; keep the immutable order snapshot untouched.
				$request_items=array_map(static fn(OrderItem $i)=>new PaymentProviderLineItem(html_entity_decode($i->item_name(),ENT_QUOTES|ENT_HTML5,'UTF-8'),$i->unit_price_minor(),$i->quantity()),$items);
				$request=new PaymentProviderSessionRequest($existing->reference(),'c2c_ps_'.$existing->reference(),$order->currency(),$order->total_minor(),$request_items,(string)$coverage->deadline(),$config);
				$durable=$registry->create_claim($existing,$context,$request->snapshot(),$config['environment']??null,$now);
			} else {
				$durable=$registry->claim($existing->reference(),true);
				$request=PaymentProviderSessionRequest::from_snapshot($durable->request_snapshot);
			}
			if(!is_object($durable)||!$registry->matches($durable,$existing,$context)||$request->reference()!==$existing->reference()||$request->idempotency_key()!==$durable->idempotency_key||$request->currency()!==$order->currency()||$request->amount_minor()!==$order->total_minor()||$request->reservation_deadline()!==(string)$coverage->deadline()) { return $this->rollback_failure('payment_identity_conflict',__( 'The durable provider claim is inconsistent.', 'cpt2collection' ),$order_id); }

			$native_claim=($existing->provider()==='stripe_checkout')?ProviderClaimRecord::from_row($durable):null;
			if ( ! $this->transactions->commit() ) { return $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the durable payment-attempt transaction outcome.', 'cpt2collection' ), $order_id ); }
			return array( 'native_claim'=>$native_claim, 'request'=>$request, 'context' => $context, 'session' => $existing, 'order' => $order, 'items' => $items, 'deadline' => (string) $coverage->deadline() );
			} catch ( Throwable $error ) { return $this->rollback_failure( 'database_error', __( 'The payment-session claim failed.', 'cpt2collection' ), $order_id ); }
	}

	private function reconcile( int $order_id, PaymentSessionRecord $original, PaymentProviderSessionResult $provider_result, CommerceContext $expected ): PaymentSessionResult {
		$session_id=$original->id();
		if ( ! $this->transactions->begin_commerce_shared( $expected ) ) { return $this->begin_failure( __( 'Payment-session reconciliation could not start.', 'cpt2collection' ), $order_id ); }
		try {
			$order = $this->orders->find_for_update( $order_id ); $session = $this->sessions->find_for_update( $session_id );
			if ( ! $order instanceof OrderRecord || ! $session instanceof PaymentSessionRecord ) { $deleted = $this->finish_deleted_in_active_transaction( $order_id ); if ( $deleted instanceof PaymentSessionResult ) { return $deleted; } }
			if ( false === $order || false === $session ) { return $this->rollback_failure( 'database_error', __( 'Payment-session reconciliation state could not be locked.', 'cpt2collection' ), $order_id ); }
			if ( null === $order || null === $session || $session->order_id() !== $order_id || $session->reference()!==$original->reference() || $session->creation_key_hash()!==$original->creation_key_hash() || $session->provider()!==$original->provider() || $session->epoch_id()!==$original->epoch_id() || $session->currency()!==$original->currency() || $session->amount_minor()!==$original->amount_minor() || null !== $session->reconciliation_required_at() || PaymentSessionStatuses::CREATING !== $session->status() || 1 !== $session->active_slot() || OrderStatuses::PENDING !== $order->status() ) { return $this->rollback_failure( 'payment_session_reconciliation_required', __( 'The payment session no longer matches the expected local state.', 'cpt2collection' ), $order_id, $session instanceof PaymentSessionRecord ? $session : null ); }
			$now = $this->clock->now_mysql(); $items = $this->orders->get_items_for_acquisition( $order_id );
			if ( false === $items ) { return $this->rollback_failure( 'database_error', __( 'The order items could not be reloaded.', 'cpt2collection' ), $order_id, $session ); }
			$reservations = $this->reservations->get_for_order_and_items_for_acquisition( $order_id, array_map( static fn( OrderItem $item ): int => $item->id(), $items ) );
			if ( false === $reservations ) { return $this->rollback_failure( 'database_error', __( 'The reservations could not be reloaded.', 'cpt2collection' ), $order_id, $session ); }
			$coverage = $this->validator->validate( $order_id, $items, $reservations, $now );
			if ( ! $coverage->is_valid() ) { return $this->rollback_failure( $coverage->code(), __( 'Reservation coverage ended before payment-session activation.', 'cpt2collection' ), $order_id, $session ); }
			if ( ! $this->valid_provider_result( $provider_result, $now, (string) $coverage->deadline() ) ) { return $this->rollback_failure( 'provider_response_invalid', __( 'The payment provider returned an unusable session.', 'cpt2collection' ), $order_id, $session ); }

			$registry=$this->sessions->registry();$claim=$registry->claim($session->reference(),true);
			if(!is_object($claim)||!$registry->matches($claim,$session,$expected)){return $this->rollback_failure('payment_identity_conflict',__( 'The durable provider claim is inconsistent.', 'cpt2collection' ),$order_id);}
			foreach(array('payment'=>$provider_result->provider_payment_id(),'session'=>$provider_result->provider_session_id()) as $kind=>$value){
				if(null===$value){continue;}
				$identity=$registry->identity($session->provider(),$kind,$value,true);
				if(!is_object($identity)||'bound'!==$identity->binding_state||(int)$identity->claim_id!==(int)$claim->id){return $this->rollback_failure('payment_identity_conflict',__( 'Provider identity ownership changed before activation.', 'cpt2collection' ),$order_id);}
			}
			if ( ! $this->sessions->activate( $session_id, $provider_result->provider_session_id(), $provider_result->redirect_url(), $provider_result->expires_at(), $now ) || ! $this->orders->update_status( $order_id, OrderStatuses::PENDING, OrderStatuses::AWAITING_PAYMENT, $now ) ) { return $this->rollback_failure( 'payment_session_persistence_failed', __( 'The payment session and order could not be activated atomically.', 'cpt2collection' ), $order_id, $session ); }
			// Capture the activated result while exact operational authority still owns
			// the fence. A reset may legitimately win immediately after COMMIT.
			$active = $this->sessions->find( $session_id );
			if(!$active instanceof PaymentSessionRecord)return $this->rollback_failure('database_error',__( 'The active payment session could not be reloaded.', 'cpt2collection' ),$order_id);
			if ( ! $this->transactions->commit() ) { return $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the payment-session activation transaction outcome.', 'cpt2collection' ), $order_id, $session ); }
			return PaymentSessionResult::success( 'payment_session_created', __( 'The payment session was created.', 'cpt2collection' ), $active );
			} catch ( Throwable $error ) { return $this->rollback_failure( 'database_error', __( 'Payment-session reconciliation failed.', 'cpt2collection' ), $order_id ); }
	}

	/** One policy for success and classified identity-bearing exceptions. */
	private function record_identity(PaymentSessionRecord $session,CommerceContext $source,?string $identity,?string $payment_id=null,?ProviderClaimRecord $captured=null):bool {
		if($session->provider()!=='stripe_checkout')return $this->record_generic_identity($session,$source,$identity,$payment_id);
		// Preserve the existing positive modern-scope exclusion before retained routing.
		// This read is DATA only, and never permits either evidence or continuation.
		$scope=$this->transactions->provider_scope($source->installation_id(),$source->epoch_identity(),$session->epoch_id());
		if($scope!==CommerceBaseline::ELIGIBLE){$this->provider_evidence_code=$scope;return false;}
		if($captured===null)return false;
		try {
			if($captured->installation_id()!==$source->installation_id()||$captured->epoch_identity()!==$source->epoch_identity()||$captured->epoch_id()!==$session->epoch_id()||$captured->order_id()!==$session->order_id()||$captured->payment_session_id()!==$session->id()||$captured->reference()!==$session->reference()||$captured->provider()!==$session->provider())return false;
			$keys=[];
			foreach(['session'=>$identity,'payment'=>$payment_id] as $kind=>$value){
				if($value===null)continue;
				if(!preg_match($kind==='session'?'/\Acs_[A-Za-z0-9_]+\z/':'/\Api_[A-Za-z0-9_]+\z/',$value)||strlen($value)>255)return false;
				$keys[$kind]=$value;
			}
			if(!$keys)return false;
			$candidate=ProviderDiscoveryCandidate::correlated($captured->provider(),$captured->environment(),ProviderClaimLocator::reference($captured->reference()),$keys,$this->clock->now_mysql(),$captured->installation_id(),$captured->epoch_identity(),$captured->epoch_id(),$captured);
			$out=$this->response_evidence->retain($candidate);
		} catch(Throwable $error){return false;}
		if(!in_array($out->status,['retained','duplicate'],true)){
			$this->provider_evidence_code=match($out->status){
				'transaction_outcome_unknown'=>'transaction_outcome_unknown',
				'verification_required'=>'provider_evidence_verification_required',
				'pre_baseline'=>'pre_baseline',
				default=>'provider_evidence_failed'
			};
			return false;
		}
		if($out->truth?->continuation()!=='normal_candidate'){
			$this->provider_evidence_code=$out->truth?->deletion!==null?'order_deleted':'commerce_reset_fenced';
			return false;
		}
		// This boolean only permits ATTEMPTING fresh exact admission in reconcile/
		// fail_attempt. Neither evidence nor this earlier observation authorizes DML.
		return true;
	}

	/** Generic providers retain their operational-only, exact-source contract. */
	private function record_generic_identity(PaymentSessionRecord $session,CommerceContext $source,?string $identity,?string $payment_id=null):bool {
		$scope=$this->transactions->provider_scope($source->installation_id(),$source->epoch_identity(),$session->epoch_id());
		if($scope!==CommerceBaseline::ELIGIBLE){$this->provider_evidence_code=$scope;return false;}
		if(!$this->transactions->begin_commerce_shared($source)){return false;}
		$registry=$this->sessions->registry();$claim=$registry->claim($session->reference(),true);
		// Generic providers never receive A1 historical privileges. Activation remains separate.
		$claim_ok=is_object($claim)&&$claim->installation_id===$source->installation_id()&&$claim->epoch_identity===$source->epoch_identity()&&(int)$claim->order_id===$session->order_id()&&(int)$claim->payment_session_id===$session->id()&&(int)$claim->epoch_id===$session->epoch_id()&&$claim->provider===$session->provider()&&$claim->reference===$session->reference()&&$claim->creation_key_hash===$session->creation_key_hash()&&in_array($claim->disposition,array('open','retired','deleted'),true)&&('deleted'!==$claim->disposition||is_string($claim->tombstoned_at)&&''!==$claim->tombstoned_at);
		if(!$claim_ok){$this->transactions->rollback();return false;}
		$payment_result=null===$payment_id?'bound':$registry->bind($session->provider(),'payment',$payment_id,(int)$claim->id,$this->clock->now_mysql());
		if(!in_array($payment_result,['bound','conflict'],true)){$this->transactions->rollback();return false;}
		$result=null===$identity?'bound':$registry->bind($session->provider(),'session',$identity,(int)$claim->id,$this->clock->now_mysql());
		if('conflict'===$payment_result){$result='conflict';}
		if(!in_array($result,['bound','conflict'],true)){$this->transactions->rollback();return false;}
		return $this->transactions->commit() && 'bound'===$result;
	}

	private function fail_attempt( PaymentSessionRecord $session, string $code, string $message, CommerceContext $expected ): PaymentSessionResult {
		if ( ! $this->transactions->begin_commerce_shared( $expected ) ) { return $this->begin_failure( __( 'The provider rejection could not be persisted.', 'cpt2collection' ), $session->order_id(), $session ); }
		$locked = $this->sessions->find_for_update( $session->id() ); $now = $this->clock->now_mysql();
		if ( ! $locked instanceof PaymentSessionRecord ) { $deleted = $this->finish_deleted_in_active_transaction( $session->order_id() ); if ( $deleted instanceof PaymentSessionResult ) { return $deleted; } }
		if ( ! $locked instanceof PaymentSessionRecord || PaymentSessionStatuses::CREATING !== $locked->status() || ! $this->sessions->mark_failed( $locked->id(), $now ) ) { return $this->rollback_failure( 'payment_session_persistence_failed', __( 'The provider rejection could not be persisted.', 'cpt2collection' ), $session->order_id(), $session ); }
		if ( ! $this->transactions->commit() ) { return $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the provider-rejection transaction outcome.', 'cpt2collection' ), $session->order_id(), $session ); }
		return $this->failure( $code, $message, $session->order_id(), $session );
	}
	private function finish_deleted_in_active_transaction( int $order_id ): ?PaymentSessionResult {
		if ( null === $this->deletions || ! $this->deletions->proves_deleted_order( $order_id ) ) { return null; }
		if ( ! $this->transactions->commit() ) { return $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the payment-session transaction outcome.', 'cpt2collection' ), $order_id ); }
		return $this->failure( 'order_deleted', __( 'The order was permanently deleted while the payment provider request was in progress.', 'cpt2collection' ), $order_id );
	}
	private function probe_deleted_after_provider( int $order_id, CommerceContext $expected ): ?PaymentSessionResult {
		if ( null === $this->deletions || ! $this->transactions->begin_commerce_shared( $expected ) ) { return null; }
		$deleted = $this->finish_deleted_in_active_transaction( $order_id );
		if ( $deleted instanceof PaymentSessionResult ) { return $deleted; }
		return $this->transactions->rollback() ? null : $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the deletion-state probe outcome.', 'cpt2collection' ), $order_id );
	}
	private function valid_provider_result( PaymentProviderSessionResult $result, string $now, string $deadline ): bool { $timestamp = $this->clock->mysql_to_timestamp( $result->expires_at() ); return '' !== trim( $result->provider_session_id() ) && false !== filter_var( $result->redirect_url(), FILTER_VALIDATE_URL ) && 'https' === strtolower( (string) parse_url( $result->redirect_url(), PHP_URL_SCHEME ) ) && $timestamp > 0 && $this->clock->format_mysql( $timestamp ) === $result->expires_at() && $result->expires_at() > $now && $result->expires_at() <= $deadline; }
	private function valid_active_replay( PaymentSessionRecord $session, OrderRecord $order, string $now, string $deadline ): bool {
		$provider_session_id = $session->provider_session_id(); $redirect_url = $session->redirect_url(); $expires_at = $session->expires_at();
		if ( $session->order_id() !== $order->id() || OrderStatuses::AWAITING_PAYMENT !== $order->status() || $session->provider() !== $this->provider->name() || PaymentSessionStatuses::ACTIVE !== $session->status() || 1 !== $session->active_slot() || $session->currency() !== $order->currency() || $session->amount_minor() !== $order->total_minor() ) { return false; }
		if ( null === $provider_session_id || '' === trim( $provider_session_id ) || strlen( $provider_session_id ) > 255 || null === $redirect_url || false === filter_var( $redirect_url, FILTER_VALIDATE_URL ) || 'https' !== strtolower( (string) parse_url( $redirect_url, PHP_URL_SCHEME ) ) || null === $expires_at ) { return false; }
		$timestamp = $this->clock->mysql_to_timestamp( $expires_at );
		if ( $timestamp <= 0 || $this->clock->format_mysql( $timestamp ) !== $expires_at || $expires_at <= $now || $expires_at > $deadline ) { return false; }
		return null === $session->completed_at() && null === $session->cancelled_at() && null === $session->failed_at() && null === $session->provider_payment_id() && null === $session->provider_event_id() && null === $session->payment_confirmed_at() && null === $session->confirmed_currency() && null === $session->confirmed_amount_minor() && null === $session->expired_at() && null === $session->reconciliation_required_at() && null === $session->reconciliation_code() && null === $session->reconciliation_event_id();
	}
	/** @param OrderItem[] $items */
	private function valid_order_snapshot( OrderRecord $order, array $items ): bool {
		$total = 0;
		foreach ( $items as $item ) { if ( ! $item instanceof OrderItem || $item->order_id() !== $order->id() || $item->currency() !== $order->currency() || $item->quantity() < 1 || $item->unit_price_minor() < 1 || $item->quantity() > intdiv( PHP_INT_MAX, $item->unit_price_minor() ) ) { return false; } $line = $item->unit_price_minor() * $item->quantity(); if ( $item->line_total_minor() !== $line || $item->line_subtotal_minor() !== $line || $line > PHP_INT_MAX - $total ) { return false; } $total += $line; }
		return $total > 0 && $total === $order->total_minor() && $total === $order->subtotal_minor();
	}
	private function valid_creation_key( string $key ): bool { return strlen( $key ) >= 32 && strlen( $key ) <= 128 && 1 === preg_match( '/\A[A-Za-z0-9_-]+\z/', $key ); }
	private function valid_provider_name( string $name ): bool { return 1 === preg_match( '/\A[a-z0-9_]{1,32}\z/', $name ); }
	private function rollback_failure( string $code, string $message, ?int $order_id = null, ?PaymentSessionRecord $session = null ): PaymentSessionResult { if ( ! $this->transactions->rollback() || $this->transactions->is_outcome_uncertain() ) { return $this->failure( 'transaction_outcome_unknown', __( 'The database did not confirm the payment-session transaction outcome.', 'cpt2collection' ), $order_id, $session ); } return $this->failure( $code, $message, $order_id, $session ); }
	private function begin_failure( string $message, ?int $order_id = null, ?PaymentSessionRecord $session = null ): PaymentSessionResult { return $this->failure( $this->transactions->is_outcome_uncertain() ? 'transaction_outcome_unknown' : 'database_error', $message, $order_id, $session ); }
	private function failure( string $code, string $message, ?int $order_id = null, ?PaymentSessionRecord $session = null ): PaymentSessionResult { return PaymentSessionResult::failure( $code, __( $message, 'cpt2collection' ), $order_id, $session ); }
}
