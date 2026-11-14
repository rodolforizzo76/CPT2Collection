<?php
/**
 * Transaction and storage-engine primitives.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-connection.php';
require_once __DIR__ . '/class-commerce-control-repository.php';

require_once __DIR__ . '/class-commerce-baseline.php';
require_once __DIR__ . '/class-provider-evidence-context.php';
require_once __DIR__ . '/class-provider-retained-discovery.php';
final class TransactionManager {
	private const STATE_INACTIVE  = 'inactive';
	private const STATE_ACTIVE    = 'active';
	private const STATE_UNCERTAIN = 'uncertain';
	private wpdb $wpdb;
	private TableNames $tables;
	private string $state = self::STATE_INACTIVE;
	private ?CommerceContext $context = null;
	private ?CommerceContext $reset_control_context = null;
	private string $reset_control_state = '';
	private bool $reset_inspection = false;
	/** Locked inspection uses the existing lock order but grants NO writer capability. */
	public function begin_reset_inspection(): bool { return $this->admit( 'FOR UPDATE', null, null, null, true, false, false, true ); }
	public static function reset_inspection_on( wpdb $db ): ?CommerceContext {
		$o = self::$owners[$db] ?? null;
		return $o instanceof self && $o->reset_inspection && self::STATE_ACTIVE === $o->state && $db instanceof CommerceConnection && $db->transaction_connection_usable() ? $o->reset_control_context : null;
	}
	public static function reset_inspection_state_on( wpdb $db ): ?string {
		return null !== self::reset_inspection_on( $db ) ? self::$owners[$db]->reset_control_state : null;
	}
	/** Internal control-only authority for atomic start and locked state inspection. */
	public function begin_reset_control(): bool { return $this->admit( 'FOR UPDATE', null, null, null, true ); }
	public static function reset_control_on( wpdb $db ): ?CommerceContext {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self && ! $owner->reset_inspection && self::STATE_ACTIVE === $owner->state && $db instanceof CommerceConnection && $db->transaction_connection_usable() ? $owner->reset_control_context : null;
	}
	public static function reset_control_state_on( wpdb $db ): ?string {
		return null !== self::reset_control_on( $db ) ? self::$owners[$db]->reset_control_state : null;
	}
	private ?CommerceResetContext $reset_context = null;
	private ?CommerceEvidenceContext $evidence_context = null;
	private ?CommerceEvidenceContext $evidence_entry_context = null;
	private ?ProviderEvidenceContext $retained_context = null;
	private ?CommerceResetRecord $retained_generation = null;
	private bool $retained_admission = false;
	private bool $retained_mutating = false;
	/** Internal parser access only during admission; no writer accepts this flag. */
	public static function retained_admission_on(wpdb $db):bool {
		$o=self::$owners[$db]??null;
		return $o instanceof self && $o->retained_admission && self::STATE_ACTIVE===$o->state && $db instanceof CommerceConnection && $db->transaction_connection_usable();
	}
	public static function retained_context_on(wpdb $db):?ProviderEvidenceContext {
		$o=self::$owners[$db]??null;
		return $o instanceof self && !$o->rollback_only && self::STATE_ACTIVE===$o->state && $db instanceof CommerceConnection && $db->transaction_connection_usable() ? $o->retained_context : null;
	}
	private function retained_storage_safe():bool {
		$names=[$this->tables->provider_identities(),$this->tables->commerce_resets()];
		foreach([
			$this->wpdb->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN (%s,%s) LIMIT 1',...$names),
			$this->wpdb->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN (%s,%s) OR REFERENCED_TABLE_NAME IN (%s,%s)) LIMIT 1',...$names,...$names)
		] as $q){$this->wpdb->last_error='';$rows=$this->wpdb->get_results($q);if($this->wpdb->last_error!==''||!is_array($rows)||$rows)return false;}
		return true;
	}
	public function begin_retained_evidence(ProviderDiscoveryCandidate $input, ?CommerceResetRecord $recovery_domain=null):?ProviderDiscoveryResult {
		$this->admission_error='';
		if(!$this->wpdb instanceof CommerceConnection || !$this->wpdb->transaction_connection_usable()){ $this->admission_error='transaction_outcome_unknown';return null; }
		try {
			$reader=new ProviderRetainedDiscovery($this->wpdb,$this->tables);
			if(!$this->supports_retained_discovery()||!$reader->indexes_valid(true)||!$this->retained_storage_safe()){ $this->admission_error='retained_storage_unavailable';return null; }
			if(!$this->begin()){ $this->admission_error=$this->is_outcome_uncertain()?'transaction_outcome_unknown':'transaction_busy';return null; }
			$this->retained_admission=true;
			try {$truth=$reader->lock_and_classify($input,$recovery_domain);}finally{$this->retained_admission=false;}
			if($truth->owner===null||!in_array($truth->status,['attributed','conflict'],true)){
				$this->admission_error=$truth->status; if(!$this->rollback())$this->admission_error='transaction_outcome_unknown';return $truth;
			}
			$row=$this->wpdb->get_row('SELECT * FROM '.$this->tables->provider_claims().' FORCE INDEX(PRIMARY) WHERE id='.$truth->owner->claim_id.' LIMIT 1');
			if($this->wpdb->last_error!==''||!is_object($row))throw new \RuntimeException('claim reread');
			$claim=ProviderClaimRecord::from_row($row);
			$locator_ok=true;
			if($input->locator!==null){$v=$input->locator->values;$locator_ok=match($input->locator->kind){
				'id'=>$v[0]===$claim->id(), 'reference'=>$v[0]===$claim->reference(),
				'idempotency'=>$v[0]===$row->idempotency_key,
				'payment_session'=>$v[0]===$claim->payment_session_id(),
				'attempt'=>$v===[$claim->installation_id(),$claim->epoch_id(),$claim->order_id(),$row->creation_key_hash], default=>false
			};}
			// Identity contradictions may quarantine; request/scope contradictions never grant a capability.
			if(!$locator_ok||($input->captured_claim!==null&&!$claim->same_request_as($input->captured_claim))||$claim->environment()!==$input->environment||$input->observed_at<$claim->created_at()||($input->installation!==null&&($input->installation!==$claim->installation_id()||$input->epoch_identity!==$claim->epoch_identity()||$input->epoch_id!==null&&$input->epoch_id!==$claim->epoch_id()))){
				$this->admission_error='candidate_mismatch';if(!$this->rollback())$this->admission_error='transaction_outcome_unknown';return $truth;
			}
			$this->retained_context=new ProviderEvidenceContext($input,$claim,$truth);
			$this->retained_generation=$truth->active_reset;
			return $truth;
		} catch(\InvalidArgumentException $e){$this->admission_error='corrupt';}
		catch(\Throwable $e){$this->admission_error='database_error';}
		if(!$this->rollback())$this->admission_error='transaction_outcome_unknown';return null;
	}
	public static function fail_retained_on(wpdb $db):void {
		if(self::retained_context_on($db)!==null)self::$owners[$db]->rollback_only=true;
	}
	/** Same established per-changed-key generation unit, independent of original owner. */
	public static function with_retained_identity_mutation(wpdb $db,callable $mutation):bool {
		$c=self::retained_context_on($db);if($c===null)return false;$o=self::$owners[$db];
		if($o->retained_mutating)return false;$o->retained_mutating=true;
		try {
			$r=$o->retained_generation;
			if($r!==null){
				if(!CommerceResetLifecycle::active($r)||$r->evidence_generation()===PHP_INT_MAX)throw new \RuntimeException('generation exhausted');
				// The active row lock was acquired before ALL provider locks; no new reset acquisition.
				if(1!==$db->query($db->prepare('UPDATE '.$o->tables->commerce_resets().' SET evidence_generation=%d WHERE id=%d AND active_slot=1 AND evidence_generation=%d',$r->evidence_generation()+1,$r->id(),$r->evidence_generation())))throw new \RuntimeException('generation CAS');
				$row=$db->get_row('SELECT * FROM '.$o->tables->commerce_resets().' FORCE INDEX(source_epoch) WHERE installation_id=\''.$r->installation_id().'\' AND source_epoch_id='.$r->source_epoch_id().' LIMIT 1');
				if($db->last_error!==''||!is_object($row))throw new \RuntimeException('generation read');
				$o->retained_generation=CommerceResetRecord::from_row($row);
			}
			if(true!==$mutation())throw new \RuntimeException('identity write');return true;
		} catch(\Throwable $e){$o->rollback_only=true;return false;}
		finally{$o->retained_mutating=false;}
	}
	private bool $evidence_mutating = false;
	private bool $rollback_only = false;
	private bool $coverage_mutating = false;
	private bool $reset_deletion = false;
	private ?CommerceResetRecord $reset_finalization = null;
	private ?CommerceContext $expected_workflow_context = null;
	public function with_commerce_context( CommerceContext $context, callable $operation ): mixed {
		$previous = $this->expected_workflow_context;
		$this->expected_workflow_context = $context;
		try { return $operation(); } finally { $this->expected_workflow_context = $previous; }
	}
	private string $admission_error = '';
	private ?int $admission_errno = null;
	private static ?\WeakMap $owners = null;

	/** Returns only the context owned by the active transaction on this connection. */
	public static function context_on( wpdb $db ): ?CommerceContext {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self ? $owner->commerce_context() : null;
	}
	public static function admitted_on( wpdb $db ): bool {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self && $owner->is_commerce_admitted();
	}
	public function commerce_context(): ?CommerceContext { return $this->is_commerce_admitted() ? $this->context : null; }
	public function is_commerce_admitted(): bool { return self::STATE_ACTIVE === $this->state && null !== $this->context && $this->wpdb instanceof CommerceConnection && $this->wpdb->transaction_connection_usable(); }
	/** Maintenance authority is deliberately absent from context_on()/admitted_on(). */
	public static function reset_context_on( wpdb $db ): ?CommerceResetContext {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self ? $owner->reset_context() : null;
	}
	public function reset_context(): ?CommerceResetContext {
		return self::STATE_ACTIVE === $this->state && $this->wpdb instanceof CommerceConnection && $this->wpdb->transaction_connection_usable() ? $this->reset_context : null;
	}
	public function begin_reset_maintenance( CommerceResetRecord $expected ): bool {
		return $this->admit( 'FOR UPDATE', null, $expected );
	}
	/** Separate capability verifies every erased table's engine and excludes hidden effects. */
	public function begin_reset_deletion( CommerceResetRecord $expected ): bool {
		return $this->admit( 'FOR UPDATE', null, $expected, null, false, true );
	}
	public static function reset_deletion_on( wpdb $db ): ?CommerceResetContext {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self && $owner->reset_deletion ? $owner->reset_context() : null;
	}
	/** Exclusive foundation fence; grants no normal, maintenance, deletion or provider authority. */
	public function begin_reset_finalization( CommerceResetRecord $expected ): bool {
		return $this->admit( 'FOR UPDATE', null, $expected, null, true, false, true );
	}
	public static function reset_finalization_on( wpdb $db ): ?CommerceResetRecord {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self && null !== self::reset_control_on( $db ) ? $owner->reset_finalization : null;
	}
	/** A failed finalization primitive cannot be partially committed by a direct caller. */
	public static function fail_reset_finalization_on( wpdb $db ): void {
		if ( null !== self::reset_finalization_on( $db ) ) { self::$owners[$db]->rollback_only = true; }
	}
	public static function evidence_context_on( wpdb $db ): ?CommerceEvidenceContext {
		$owner = self::$owners[$db] ?? null;
		return $owner instanceof self ? $owner->evidence_context() : null;
	}
	public function evidence_context(): ?CommerceEvidenceContext {
		return ! $this->rollback_only && self::STATE_ACTIVE === $this->state && $this->wpdb instanceof CommerceConnection && $this->wpdb->transaction_connection_usable() ? $this->evidence_context : null;
	}
	public function begin_historical_evidence( CommerceEvidenceContext $expected ): bool {
		return $this->admit( 'LOCK IN SHARE MODE', null, null, $expected );
	}
	/** Entry snapshot remains usable only by this live owner; independent admission is always exact. */
	public static function owns_evidence_context( wpdb $db, CommerceEvidenceContext $expected ): bool {
		$owner = self::$owners[$db] ?? null;
		$live = self::evidence_context_on( $db );
		return null !== $live && ( $live->equals( $expected ) || $owner->evidence_entry_context->equals( $expected ) );
	}
	/** Internal binding unit: reserve one generation, apply one identity change, or require rollback. */
	public static function with_historical_identity_mutation( wpdb $db, CommerceEvidenceContext $expected, callable $mutation ): bool {
		if ( ! self::owns_evidence_context( $db, $expected ) ) { return false; }
		$owner = self::$owners[$db];
		if ( $owner->evidence_mutating ) { return false; }
		$owner->evidence_mutating = true;
		try {
			$next = ( new CommerceResetRepository( $db, $owner->tables ) )->increment_evidence_generation( $owner->evidence_context );
			if ( false === $next || true !== $mutation() ) { $owner->rollback_only = true; return false; }
			$owner->evidence_context = new CommerceEvidenceContext( $next, $owner->evidence_context->claim() );
			return true;
		} catch ( \Throwable $error ) { $owner->rollback_only = true; return false; }
		finally { $owner->evidence_mutating = false; }
	}
	/** Coverage reserves generations only for actual retained identity changes. */
	public static function with_reset_coverage_identity_mutation( wpdb $db, callable $mutation ): bool {
		$owned = self::reset_context_on( $db );
		if ( null === $owned || 'terminalizing_events' !== $owned->operation()->phase() || ! in_array( $owned->operation()->checkpoint(), array( 's1_s', 's1_e' ), true ) ) { return false; }
		$owner = self::$owners[$db];
		if ( $owner->rollback_only || $owner->coverage_mutating ) { return false; }
		$owner->coverage_mutating = true;
		try {
			$next = ( new CommerceResetRepository( $db, $owner->tables ) )->increment_coverage_generation( $owned );
			if ( false === $next || true !== $mutation() ) { $owner->rollback_only = true; return false; }
			$owner->reset_context = new CommerceResetContext( $next ); return true;
		} catch ( \Throwable $error ) { $owner->rollback_only = true; return false; }
		finally { $owner->coverage_mutating = false; }
	}
	public static function reset_coverage_identity_mutation_on( wpdb $db ): bool {
		return null !== self::reset_context_on( $db ) && self::$owners[$db]->coverage_mutating;
	}
	/** Generation persistence is unavailable outside a binding unit, even to an evidence owner. */
	public static function historical_identity_mutation_on( wpdb $db ): bool {
		return null !== self::evidence_context_on( $db ) && self::$owners[$db]->evidence_mutating;
	}
	/** Read-only locked preflight. A later write must recheck under its own admission. */
	public function provider_scope(mixed $installation, mixed $epoch, ?int $id = null): string {
		try { if (!$this->tables_use_innodb([])) return CommerceBaseline::ERROR; } catch(\Throwable $error) { return CommerceBaseline::ERROR; }
		if (!$this->begin()) return $this->is_outcome_uncertain() ? 'transaction_outcome_unknown' : CommerceBaseline::ERROR;
		try { $result = (new CommerceBaseline($this->wpdb,$this->tables))->scope($installation,$epoch,$id); }
		catch (\Throwable $error) { $result = CommerceBaseline::ERROR; }
		return $this->rollback() ? $result : 'transaction_outcome_unknown';
	}
	public function admission_error(): string { return $this->admission_error; }
	public function begin_commerce_shared( ?CommerceContext $expected = null ): bool { return $this->admit( 'LOCK IN SHARE MODE', $expected ); }
	public function begin_commerce_exclusive( ?CommerceContext $expected = null ): bool { return $this->admit( 'FOR UPDATE', $expected ); }
	private function admit( string $lock, ?CommerceContext $expected, ?CommerceResetRecord $reset = null, ?CommerceEvidenceContext $evidence = null, bool $reset_control = false, bool $deletion = false, bool $finalization = false, bool $inspection = false ): bool {
		$expected ??= $this->expected_workflow_context;
		$this->admission_error = ''; $this->admission_errno = null;
		if ( $this->is_active() || isset( self::$owners[$this->wpdb] ) ) { $this->admission_error = 'transaction_already_active'; return false; }
		$required = null !== $evidence ? array( $this->tables->commerce_resets(), $this->tables->provider_claims(), $this->tables->provider_identities() ) : ( null === $reset && ! $reset_control ? array() : array( $this->tables->commerce_resets() ) );
		if ( null !== $reset && in_array( $reset->phase(), array( 'retiring_claims', 'terminalizing_events' ), true ) ) { $required = array( $this->tables->commerce_resets(), $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->payment_events(), $this->tables->payment_sessions(), $this->tables->order_deletions() ); }
		if ( null !== $reset && in_array( $reset->phase(), array( 'deleting', 'finalizing' ), true ) ) { $required = array( $this->tables->commerce_resets(), $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->order_deletions() ); }
		if ( $deletion || $finalization ) { foreach ( CommerceResetDeletion::STAGES as $table ) { $required[] = $this->tables->$table(); } }
		try {
			if ( $finalization && ( ! CommerceFoundation::verify_structure( $this->wpdb, $this->tables ) || ! CommercePhaseFourCheckpointOneMigration::verify_structure( $this->wpdb, $this->tables, array( 'evidence_generation', 'validation_generation' ) ) ) ) { $this->admission_error = 'transactions_not_supported'; return false; }
			if ( ( $deletion || $finalization ) && ! $this->deletion_storage_safe( $finalization ) ) { $this->admission_error = 'transactions_not_supported'; return false; }
			if ( ! $this->wpdb instanceof CommerceConnection || ! $this->tables_use_innodb( $required ) ) { $this->admission_error = 'transactions_not_supported'; return false; }
		} catch ( \Throwable $error ) { $this->admission_error = 'database_error'; return false; }
		if ( ! $this->begin() ) { $this->admission_error = $this->is_outcome_uncertain() ? 'transaction_outcome_unknown' : 'transaction_already_active'; return false; }
		$database_error = '';
		try {
			$baseline = (new CommerceBaseline($this->wpdb,$this->tables))->read($lock);
			if (null === $baseline || 'completed' !== $baseline['status']) {
				$database_error = $this->wpdb->last_error;
				$this->admission_error = '' !== $database_error ? 'database_error' : (null === $baseline ? 'baseline_unavailable' : 'baseline_pending');
				if ('' !== $database_error) $this->admission_errno = $this->last_error_number();
				if (!$this->rollback()) $this->admission_error = 'transaction_outcome_unknown';
				if ('' !== $database_error) $this->wpdb->last_error = $database_error;
				return false;
			}
			$repository = new CommerceControlRepository( $this->wpdb, $this->tables );
			if ( $reset_control ) {
				$context = $repository->read_reset_control();
				if ( null !== $context ) {
					$this->reset_control_context = $context; $this->reset_control_state = $repository->state(); $this->reset_inspection = $inspection;
					if ( ! $finalization ) { return true; }
					$record = ( new CommerceResetRepository( $this->wpdb, $this->tables ) );
					$actual = $record->resolve( $reset->operation_identity() );
					if ( $actual instanceof CommerceResetRecord && ( $actual->equals( $reset ) || $actual->completion_of( $reset ) ) && ( CommerceResetLifecycle::final_entry( $actual ) || 'completed' === $actual->status() ) ) { $this->reset_finalization = $actual; return true; }
					$this->admission_error = false === $actual ? $record->error() : ( $actual instanceof CommerceResetRecord && $actual->equals( $reset ) && 'blocked' === $actual->status() ? 'reset_blocked' : 'reset_snapshot_stale' );
					$database_error = $this->wpdb->last_error;
					if ( ! $this->rollback() ) { $this->admission_error = 'transaction_outcome_unknown'; }
					if ( '' !== $database_error ) { $this->wpdb->last_error = $database_error; }
					return false;
				}
			} elseif ( null !== $evidence ) {
				$context = $repository->read_evidence( $evidence );
				if ( null !== $context ) { $this->evidence_context = $context; $this->evidence_entry_context = $context; return true; }
			} elseif ( null !== $reset ) {
				$context = $repository->read_reset( $reset );
				if ( null !== $context ) { $this->reset_context = $context; $this->reset_deletion = $deletion; return true; }
			} else {
				$context = $repository->read( $lock );
				if ( null !== $context && ( null === $expected || $context->equals( $expected ) ) ) { $this->context = $context; return true; }
			}
			$this->admission_error = null === $context ? $repository->error() : 'commerce_context_mismatch';
			$database_error = $this->wpdb->last_error;
			$this->admission_errno = $this->last_error_number();
		} catch ( \Throwable $error ) { $this->admission_error = 'database_error'; }
		if ( ! $this->rollback() ) { $this->admission_error = 'transaction_outcome_unknown'; }
		if ( '' !== $database_error ) { $this->wpdb->last_error = $database_error; }
		return false;
	}
	private function uncertain(): void {
		$this->state = self::STATE_UNCERTAIN;
		if ( $this->wpdb instanceof CommerceConnection ) { $this->wpdb->poison_transaction(); }
	}
	/** Connection lifecycle notification only: no SQL, release callback or outcome inference. */
	public static function connection_discarded_on( wpdb $db ): void {
		$owner = self::$owners[$db] ?? null;
		if ( ! $owner instanceof self ) { return; }
		$owner->clear_authority();
		$owner->state = self::STATE_UNCERTAIN;
		unset( self::$owners[$db] );
	}
	private function clear_authority(): void {
		$this->continuation_inspection=false; $this->retained_context=null; $this->retained_generation=null; $this->retained_admission=false; $this->retained_mutating=false;
		$this->reset_control_context = null; $this->reset_control_state = ''; $this->reset_inspection = false; $this->context = null; $this->reset_context = null; $this->evidence_context = null; $this->evidence_entry_context = null; $this->rollback_only = false; $this->evidence_mutating = false; $this->coverage_mutating = false; $this->reset_deletion = false; $this->reset_finalization = null;
	}
	private function released(): void {
		$this->clear_authority();
		$this->state = self::STATE_INACTIVE;
		unset( self::$owners[$this->wpdb] );
		if ( $this->wpdb instanceof CommerceConnection ) { $this->wpdb->release_transaction( $this ); }
	}
	private function transaction_query( string $sql ): bool {
		try { return false !== $this->wpdb->query( $sql ); } catch ( \Throwable $error ) { return false; }
	}

	public function __construct( wpdb $wpdb, TableNames $tables ) {
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/** Connection identity only; this does not confer transaction authority. */
	public function uses_connection( wpdb $db ): bool { return $this->wpdb === $db; }

	public function supports_reservation_acquisition(): bool {
		$required = array( $this->wpdb->posts, $this->wpdb->postmeta, $this->tables->orders(), $this->tables->order_items(), $this->tables->reservations() );
		return $this->tables_use_innodb( $required );
	}

	public function supports_order_persistence(): bool {
		return $this->tables_use_innodb( array( $this->tables->carts(), $this->tables->cart_items(), $this->tables->orders(), $this->tables->order_items(), $this->tables->order_deletions(), $this->tables->reservations(), $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->payment_sessions(), $this->tables->payment_events() ) );
	}

	public function supports_order_deletion(): bool {
		return $this->tables_use_innodb( array( $this->tables->carts(), $this->tables->orders(), $this->tables->order_items(), $this->tables->order_deletions(), $this->tables->reservations(), $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->payment_sessions(), $this->tables->payment_events() ) );
	}

	public function supports_payment_notifications(): bool {
		return $this->tables_use_innodb( array( $this->tables->orders(), $this->tables->order_items(), $this->tables->carts(), $this->tables->payment_events(), $this->tables->payment_sessions() ) );
	}

	public function supports_cart_reconciliation(): bool {
		return $this->tables_use_innodb( array( $this->tables->carts(), $this->tables->cart_items(), $this->tables->orders(), $this->tables->order_items() ) );
	}

	public function supports_cart_mutation(): bool {
		return $this->tables_use_innodb( array( $this->tables->carts(), $this->tables->cart_items() ) );
	}

	public function supports_payment_event_processing(): bool { return $this->tables_use_innodb( array( $this->tables->orders(), $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->payment_sessions(), $this->tables->payment_events() ) ); }

	public function supports_event_receipt(): bool { return $this->tables_use_innodb( array( $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->payment_events() ) ); }

	public function supports_admin_stock(): bool {
		return $this->tables_use_innodb( array( $this->wpdb->posts, $this->wpdb->postmeta, $this->tables->reservations() ) );
	}

	public function supports_payment_session_persistence(): bool {
		return $this->tables_use_innodb( array( $this->tables->orders(), $this->tables->order_items(), $this->tables->reservations(), $this->tables->payment_sessions() ) );
	}

	public function supports_sale_conversion(): bool {
		return $this->tables_use_innodb( array( $this->wpdb->posts, $this->wpdb->postmeta, $this->tables->orders(), $this->tables->order_items(), $this->tables->reservations(), $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->payment_sessions(), $this->tables->payment_events() ) );
	}

	/** Fixed metadata inventory; no business rows, hooks, cascades or runtime DDL. */
	private function deletion_storage_safe( bool $finalization = false ): bool {
		$names = array( $this->tables->commerce_resets(), $this->tables->provider_identities() );
		if ( $finalization ) { $names[] = $this->tables->commerce_control(); $names[] = $this->tables->commerce_epochs(); }
		foreach ( CommerceResetDeletion::STAGES as $method ) {
			$table = $this->tables->$method(); $names[] = $table;
			$this->wpdb->last_error = '';
			$keys = $this->wpdb->get_results( 'SHOW INDEX FROM ' . $table );
			if ( ! is_array( $keys ) || '' !== $this->wpdb->last_error ) { return false; }
			$primary = array_values( array_filter( $keys, static fn( $key ) => 'PRIMARY' === $key->Key_name ) );
			if ( 1 !== count( $primary ) || 'id' !== $primary[0]->Column_name || null !== $primary[0]->Sub_part ) { return false; }
		}
		$marks = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		foreach ( array(
			$this->wpdb->prepare( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN (' . $marks . ') LIMIT 1', ...$names ),
			$this->wpdb->prepare( 'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN (' . $marks . ') OR REFERENCED_TABLE_NAME IN (' . $marks . ')) LIMIT 1', ...$names, ...$names ),
		) as $query ) {
			$this->wpdb->last_error = ''; $rows = $this->wpdb->get_results( $query );
			if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error || $rows ) { return false; }
		}
		return true;
	}
	/** @param string[] $required Required transactional tables. */
	private function tables_use_innodb( array $required ): bool {
		$required = array_values( array_unique( array_merge( $required, array( $this->tables->commerce_control(), $this->tables->commerce_epochs() ) ) ) );
		$marks    = implode( ', ', array_fill( 0, count( $required ), '%s' ) );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$marks})", ...$required ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) || count( $rows ) !== count( $required ) ) {
			return false;
		}
		$engines = array();
		foreach ( $rows as $row ) {
			$engines[ (string) $row->TABLE_NAME ] = strtoupper( (string) $row->ENGINE );
		}
		foreach ( $required as $table ) {
			if ( 'INNODB' !== ( $engines[ $table ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/** Private-connection read snapshot; creates NO commerce/reset/evidence capability.
	 * No row locks: MVCC fixes one coherent point without a multi-epoch lock inversion.
	 * Pin before isolation/BEGIN; any unacknowledged bookkeeping poisons the owner.
	 */
	public function supports_retained_discovery(): bool {
		return $this->tables_use_innodb( array( $this->tables->provider_claims(), $this->tables->provider_identities(), $this->tables->commerce_resets(), $this->tables->order_deletions() ) );
	}
	/** D1 inspection only: drain prior cooperating transactions before any MVCC read.
	 * No normal, evidence, reset or control capability. Caller must confirm ROLLBACK.
	 * This is logically read-only, not SQL READ ONLY (which forbids FOR UPDATE).
	 */
	private bool $continuation_inspection = false;
	public static function continuation_inspection_on( wpdb $db ): bool {
		$o = self::$owners[$db] ?? null;
		return $o instanceof self && $o->continuation_inspection && self::STATE_ACTIVE === $o->state
			&& $db instanceof CommerceConnection && $db->transaction_connection_usable();
	}
	public function begin_continuation_inspection(): bool {
		if ( ! $this->wpdb instanceof CommerceConnection || ! $this->wpdb->transaction_connection_usable() || ! $this->begin() ) { return false; }
		try {
			$this->wpdb->last_error = '';
			$rows = $this->wpdb->get_results( 'SELECT * FROM ' . $this->tables->commerce_control() . ' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 2 FOR UPDATE' );
			if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) { $this->rollback(); return false; }
			$this->continuation_inspection = true;
			return true;
		} catch ( \Throwable $error ) { $this->rollback(); return false; }
	}
	public function begin_read_snapshot(): bool {
		self::$owners ??= new \WeakMap();
		if ( self::STATE_INACTIVE !== $this->state || isset( self::$owners[$this->wpdb] ) || ! $this->wpdb instanceof CommerceConnection || ! $this->wpdb->pin_transaction( $this ) ) { return false; }
		self::$owners[$this->wpdb] = $this;
		if ( ! $this->transaction_query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || ! $this->transaction_query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) { $this->uncertain(); return false; }
		$this->state = self::STATE_ACTIVE;
		return true;
	}
	public function begin(): bool {
		self::$owners ??= new \WeakMap();
		if ( self::STATE_INACTIVE !== $this->state || isset( self::$owners[$this->wpdb] ) ) { return false; }
		if ( $this->wpdb instanceof CommerceConnection && ! $this->wpdb->pin_transaction( $this ) ) { return false; }
		self::$owners[$this->wpdb] = $this;
		if ( ! $this->transaction_query( 'START TRANSACTION' ) ) { $this->uncertain(); return false; }
		$this->state = self::STATE_ACTIVE;
		return true;
	}
	public function commit(): bool {
		if ( self::STATE_ACTIVE !== $this->state ) { return false; }
		if ( $this->rollback_only ) { $this->rollback(); return false; }
		if ( ! $this->transaction_query( 'COMMIT' ) ) { $this->uncertain(); return false; }
		$this->released(); return true;
	}
	public function rollback(): bool {
		if ( self::STATE_INACTIVE === $this->state ) { return true; }
		if ( self::STATE_UNCERTAIN === $this->state ) { return false; }
		if ( ! $this->transaction_query( 'ROLLBACK' ) ) { $this->uncertain(); return false; }
		$this->released(); return true;
	}

	/** True when starting another transaction on this connection is unsafe. */
	public function is_active(): bool { return self::STATE_INACTIVE !== $this->state; }

	public function is_outcome_uncertain(): bool { return self::STATE_UNCERTAIN === $this->state; }

	/** Returns the native MySQL/MariaDB error number for the last query when available. */
	public function last_error_number(): int {
		if ( null !== $this->admission_errno ) { return $this->admission_errno; }
		$last_error = $this->wpdb->last_error;
		// Test/database adapters may expose the native number directly.
		if ( isset( $this->wpdb->last_errno ) && is_numeric( $this->wpdb->last_errno ) ) {
			return (int) $this->wpdb->last_errno;
		}

		// wpdb does not expose mysqli_errno; SHOW WARNINGS preserves the server code.
		$warnings = $this->wpdb->get_results( 'SHOW WARNINGS' );
		if ( is_array( $warnings ) ) {
			foreach ( $warnings as $warning ) {
				$number = isset( $warning->Code ) ? (int) $warning->Code : 0;
				if ( in_array( $number, array( 1205, 1213 ), true ) ) {
					return $number;
				}
			}
		}

		// Compatibility fallback for hosts that deny SHOW WARNINGS.
		if ( false !== stripos( $last_error, 'deadlock' ) ) {
			return 1213;
		}
		if ( false !== stripos( $last_error, 'lock wait timeout' ) ) {
			return 1205;
		}

		return 0;
	}
}
