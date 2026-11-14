<?php
/** Real SQL exact-operation admission, separation, preservation and failure injection. */
declare( strict_types=1 );
require __DIR__ . '/support/reset-maintenance.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRecord,CommerceResetContext,CommerceResetRepository,CommerceContext,TransactionManager,TableNames,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,PaymentEventRepository,ProviderRegistry,AdminStockRepository,LockedCatalogReader,OrderDeletionRegistry};
final class MaintenanceFailureDb extends wpdb {
	public array $trace = array();
	public string $failure = '';
	public string $boundary = '';
	public string $failure_kind = 'error';
	public function get_results( string $query ): ?array {
		$this->trace[] = $query;
		if ( '' !== $this->failure && str_contains( $query, $this->failure ) ) {
			if ( 'throw' === $this->failure_kind ) { throw new RuntimeException( 'injected read exception' ); }
			if ( 'null' === $this->failure_kind ) { return null; }
			$this->last_error = 'injected read ambiguity'; return array();
		}
		return parent::get_results( $query );
	}
	public function query( string $query ): int|false {
		if ( $query === $this->boundary ) { $this->last_error = 'injected unknown outcome'; return false; }
		return parent::query( $query );
	}
}
$prefix = 'c2c_p42a_' . bin2hex( random_bytes( 5 ) ) . '_';
$db = new MaintenanceFailureDb( $prefix ); $t = new TableNames( $db ); $tx = new TransactionManager( $db, $t );
try {
	modern_maintenance_schema( $db ); $expected = maintenance_fixture( $db );
	$repo = new CommerceResetRepository( $db, $t ); $unowned = new CommerceResetContext( $expected );
	check( false === $repo->find_for_maintenance( $unowned ), 'constructed context alone grants no maintenance authority' );
	check( $tx->begin() && false === $repo->find_for_maintenance( $unowned ), 'bare transaction cannot authorize maintenance' ); $tx->rollback();
	check( $tx->begin_commerce_shared() && false === $repo->find_for_maintenance( $unowned ) && null === $tx->reset_context(), 'normal ready authority cannot authorize maintenance operation' );
	$normal = $tx->commerce_context(); $order = ( new OrderRepository( $db, $t ) )->find( 1 ); $tx->commit();
	check( ! $tx->begin_reset_maintenance( $expected ), 'ready rejects maintenance' );
	sql( $db, "UPDATE {$t->commerce_control()} SET state='resetting'" );
	$before = maintenance_snapshot( $db ); $db->trace = array();
	check( $tx->begin_reset_maintenance( $expected ), 'exact durable active reset obtains maintenance' );
	$locks = array_values( array_filter( $db->trace, fn( $q ) => str_contains( $q, 'FOR UPDATE' ) ) );
	check(str_contains($locks[0],'c2c_commerce_control'),'baseline control lock precedes runtime locks');array_shift($locks);
	check( 3 === count( $locks ) && str_contains( $locks[0], 'c2c_commerce_control' ) && str_contains( $locks[1], 'c2c_commerce_epochs' ) && str_contains( $locks[2], 'c2c_commerce_resets' ), 'lock order is control, source epoch, operation' );
	$context = $tx->reset_context();
	check( $context instanceof CommerceResetContext && ! $context instanceof CommerceContext && $context->operation()->equals( $expected ), 'distinct immutable maintenance type retains exact snapshot' );
	$type_rejected = false;
	try { $normal->equals( $context ); } catch ( TypeError $error ) { $type_rejected = true; }
	check( $type_rejected, 'normal context equality rejects maintenance type' );
	check( ! TransactionManager::admitted_on( $db ) && null === TransactionManager::context_on( $db ) && ! $tx->is_commerce_admitted(), 'maintenance never satisfies normal central admission guards' );
	check( $repo->find_for_maintenance( $context )->equals( $expected ), 'maintenance-only read uses exact owned operation' );
	check( false === $repo->find_active( true ), 'maintenance does not borrow normal history admission' );
	$nested = new TransactionManager( $db, $t );
	check( ! $nested->begin_reset_maintenance( $expected ) && ! $nested->begin_commerce_shared() && ! $nested->begin(), 'connection owner prevents nested and cross-mode transactions' );
	$other = new wpdb( $prefix );
	check( false === ( new CommerceResetRepository( $other, new TableNames( $other ) ) )->find_for_maintenance( $context ), 'context cannot authorize an independent unowned connection' ); $other->close();
	$at = '2026-01-01 00:00:00';
	check( false === ( new CartRepository( $db, $t ) )->touch( 1, $at, $at, $at ), 'cart mutation rejected' );
	check( false === ( new OrderRepository( $db, $t ) )->update_status( 1, 'paid', 'cancelled', $at ), 'order mutation rejected' );
	check( false === ( new ReservationRepository( $db, $t ) )->update_status( 1, 'converted', 'released', $at ), 'reservation mutation rejected' );
	check( false === ( new PaymentSessionRepository( $db, $t ) )->mark_failed( 1, $at ), 'payment-session mutation rejected' );
	check( false === ( new PaymentEventRepository( $db, $t ) )->quarantine_ownership( 1 ), 'payment-event mutation rejected' );
	check( 'database_error' === ( new ProviderRegistry( $db, $t ) )->bind( 'stripe_checkout', 'session', 'cs_forbidden', 1, $at ), 'provider identity append rejected' );
	check( false === ( new AdminStockRepository( $db, $tx ) )->set_exact( 10, 1, 7, 6 ) && false === ( new AdminStockRepository( $db, $tx ) )->initialize( 10 ), 'admin physical stock writes rejected' );
	check( false === ( new LockedCatalogReader( $db, $tx ) )->decrement_stock( 10, 7, 1 ), 'sale physical stock decrement rejected' );
	check( false === ( new OrderDeletionRegistry( $db, $t ) )->persist( $order, $at ), 'Phase 3 deletion evidence mutation rejected' );
	$raw = $db->get_row( 'SELECT * FROM ' . $t->commerce_resets() . ' WHERE id=1' );
	$wrong = clone $raw; $wrong->id = 2;
	check( false === $repo->find_for_maintenance( new CommerceResetContext( CommerceResetRecord::from_row( $wrong ) ) ), 'another operation cannot borrow maintenance authority' );
	check( $tx->commit() && null === $tx->reset_context() && false === $repo->find_for_maintenance( $context ), 'confirmed commit releases maintenance authority' );
	check( $before === maintenance_snapshot( $db ), 'admission and denied writes preserve all populated operational, evidence, stock, epoch and reset rows' );
	check( ! $tx->begin_commerce_shared( $normal ) && ! $tx->begin_commerce_exclusive( $normal ), 'resetting rejects both normal modes despite matching unretired epoch' );
	foreach ( array( 'id'=>2, 'operation_identity'=>str_repeat('e',32), 'installation_id'=>str_repeat('e',32), 'source_epoch_id'=>2, 'source_epoch_identity'=>str_repeat('e',32), 'phase'=>'stale', 'status'=>'completed', 'revision'=>1, 'target_epoch_identity'=>str_repeat('e',32) ) as $field=>$value ) {
		$wrong = clone $raw; $wrong->$field = $value;
		check( ! $tx->begin_reset_maintenance( CommerceResetRecord::from_row( $wrong ) ) && ! $tx->is_active(), "wrong/stale expected $field fails closed and releases ownership" );
	}
	// Match the caller's snapshot to bad durable data: validation must be semantic too.
	foreach ( array( 'installation_id'=>str_repeat('e',32), 'source_epoch_id'=>2, 'source_epoch_identity'=>str_repeat('e',32), 'active_slot'=>null, 'status'=>'completed', 'phase'=>'unknown', 'completed_at'=>$at, 'target_epoch_id'=>2 ) as $field=>$value ) {
		check( false !== $db->update( $t->commerce_resets(), array($field=>$value), array('id'=>1), array('%s'), array('%d') ), 'set durable ' . $field );
		$bad = CommerceResetRecord::from_row( $db->get_row( 'SELECT * FROM ' . $t->commerce_resets() . ' WHERE id=1' ) );
		check( ! $tx->begin_reset_maintenance( $bad ) && ! $tx->is_active(), "contradictory durable $field rejected even with matching expectation" );
		$db->update( $t->commerce_resets(), array($field=>$raw->$field), array('id'=>1), array('%s'), array('%d') );
	}
	foreach ( array( 'inactive', 'unknown', 'failed' ) as $status ) {
		sql($db,"UPDATE {$t->commerce_resets()} SET status='$status'");
		$bad=CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id=1'));
		check(!$tx->begin_reset_maintenance($bad),$status.' status cannot become authority by matching caller expectation');
	}
	sql($db,"UPDATE {$t->commerce_resets()} SET status='running'");
	foreach ( array( 'ready', 'migrating_v11', 'migrating_v12', 'migrating_v13', 'unknown' ) as $state ) {
		sql( $db, "UPDATE {$t->commerce_control()} SET state='$state'" );
		check( ! $tx->begin_reset_maintenance( $expected ), "$state rejects maintenance" );
	}
	sql( $db, "UPDATE {$t->commerce_control()} SET state='resetting'" );
	foreach ( array(
		'noncanonical slot'=>array("UPDATE {$t->commerce_resets()} SET active_slot=2", "UPDATE {$t->commerce_resets()} SET active_slot=1"),
		'malformed state'=>array("UPDATE {$t->commerce_resets()} SET status='bad state'", "UPDATE {$t->commerce_resets()} SET status='running'"),
		'retired source'=>array("UPDATE {$t->commerce_epochs()} SET retired_at=NOW()", "UPDATE {$t->commerce_epochs()} SET retired_at=NULL"),
		'missing source'=>array("DELETE FROM {$t->commerce_epochs()} WHERE id=1", "INSERT INTO {$t->commerce_epochs()} VALUES(1,REPEAT('b',32),'$at',NULL)"),
		'wrong control source'=>array("UPDATE {$t->commerce_control()} SET current_epoch_id=2", "UPDATE {$t->commerce_control()} SET current_epoch_id=1"),
		'target identity already allocated'=>array("INSERT INTO {$t->commerce_epochs()} VALUES(2,REPEAT('d',32),'$at',NULL)", "DELETE FROM {$t->commerce_epochs()} WHERE id=2"),
		'second occupied slot'=>array("INSERT INTO {$t->commerce_resets()} SELECT 2,REPEAT('e',32),installation_id,2,REPEAT('e',32),REPEAT('f',32),NULL,2,status,phase,checkpoint,`cursor`,revision,requested_by,error_code,started_at,updated_at,completed_at,evidence_generation,validation_generation FROM {$t->commerce_resets()} WHERE id=1", "DELETE FROM {$t->commerce_resets()} WHERE id=2"),
	) as $label=>[$change,$restore] ) { sql($db,$change); check(!$tx->begin_reset_maintenance($expected)&&!$tx->is_active(),$label.' rejects admission'); sql($db,$restore); }
	foreach ( array( 'c2c_commerce_control', 'WHERE id =', 'WHERE identity=', 'WHERE active_slot' ) as $failure ) {
		$db->failure=$failure; check(!$tx->begin_reset_maintenance($expected)&&!$tx->is_active(),'SQL ambiguity fails closed: '.$failure); $db->failure='';
	}
	foreach ( array('null','throw') as $kind ) {
		$db->failure='WHERE active_slot'; $db->failure_kind=$kind;
		check(!$tx->begin_reset_maintenance($expected)&&!$tx->is_active(),$kind.' reset read fails closed');
	}
	$db->failure=''; $db->failure_kind='error';
	check($tx->begin_reset_maintenance($expected),'read-failure test admitted'); $db->failure='WHERE id=1';
	check(false===$repo->find_for_maintenance($context),'maintenance repository SQL ambiguity fails closed'); $db->failure=''; $tx->rollback();
	sql($db,"DELETE FROM {$t->commerce_resets()}"); check(!$tx->begin_reset_maintenance($expected),'missing operation rejected'); $expected=maintenance_fixture($db);
	sql($db,"ALTER TABLE {$t->commerce_resets()} ENGINE=MyISAM"); check(!$tx->begin_reset_maintenance($expected)&&'transactions_not_supported'===$tx->admission_error(),'nontransactional reset table rejected'); sql($db,"ALTER TABLE {$t->commerce_resets()} ENGINE=InnoDB");
	check($tx->begin_reset_maintenance($expected),'poison test admitted'); $db->poison_transaction();
	check(null===$tx->reset_context()&&false===$repo->find_for_maintenance($context),'connection poison revokes maintenance authority'); $tx->rollback(); $db->close();
	foreach ( array('START TRANSACTION','COMMIT','ROLLBACK') as $boundary ) {
		$db=new MaintenanceFailureDb($prefix); $tx=new TransactionManager($db,$t);
		if ('START TRANSACTION' !== $boundary) { check($tx->begin_reset_maintenance($expected),'outcome fixture admitted'); }
		$db->boundary=$boundary;
		$ok=match($boundary){'START TRANSACTION'=>$tx->begin_reset_maintenance($expected),'COMMIT'=>$tx->commit(),'ROLLBACK'=>$tx->rollback()};
		check(!$ok&&$tx->is_outcome_uncertain()&&null===$tx->reset_context()&&!$tx->begin_reset_maintenance($expected)&&!$tx->begin_commerce_shared(),$boundary.' uncertainty revokes authority and prevents reuse'); $db->close();
	}
} finally { try { $db->close(); } catch ( Throwable $ignored ) {} maintenance_cleanup( new wpdb( $prefix ) ); }
echo "All Phase 4.2A reset-maintenance admission checks passed.\n";
