<?php
/** Explicit test-only connection and initial epoch fixtures. Never repairs on reads. */
declare( strict_types=1 );
require_once dirname( __DIR__, 2 ) . '/includes/class-commerce-connection.php';
trait TestCommerceConnection {
	private ?object $test_owner = null;
	private bool $test_poisoned = false;
	public function pin_transaction( object $owner ): bool { if ( $this->test_owner || $this->test_poisoned ) { return false; } $this->test_owner = $owner; return true; }
	public function release_transaction( object $owner ): void { if ( $owner === $this->test_owner && ! $this->test_poisoned ) { $this->test_owner = null; } }
	public function poison_transaction(): void { $this->test_poisoned = true; }
	public function transaction_connection_usable(): bool { return ! $this->test_poisoned; }
	/** Unit adapters model only the already-migrated, fixed epoch unless a test overrides it. */
	public function foundation_results( string $sql ): ?array {
		if ( str_contains( $sql, 'information_schema.' ) ) { return null; }
		if ( str_contains( $sql, 'c2c_commerce_control' ) ) { return array( (object) array( 'id' => 1, 'installation_id' => str_repeat( 'a', 32 ), 'current_epoch_id' => 1, 'state' => 'ready', 'baseline_contract'=>modern_fixture_attestation(), 'updated_at' => '2026-01-01 00:00:00' ) ); }
		if ( str_contains( $sql, 'c2c_commerce_epochs' ) ) { return array( (object) array( 'id' => 1, 'identity' => str_repeat( 'b', 32 ), 'created_at' => '2026-01-01 00:00:00', 'retired_at' => null ) ); }
		return null;
	}
}

/** Fixed canonical attestation for unit/runtime fixtures, never an upgrade shortcut. */
function modern_fixture_attestation(): string {
 return json_encode(['version'=>1,'installation_id'=>str_repeat('a',32),'identity'=>str_repeat('c',32),'first_epoch_id'=>1,'first_epoch_identity'=>str_repeat('b',32),'status'=>'completed'],JSON_THROW_ON_ERROR);
}
function attest_modern_runtime_fixture(wpdb $db): void {
 $t=new \RodolfoRizzo\Cpt2Collection\TableNames($db);
 if(false===$db->query('ALTER TABLE '.$t->commerce_control().' ADD baseline_contract LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL'))throw new RuntimeException($db->last_error);
 if(false===$db->query($db->prepare('UPDATE '.$t->commerce_control().' SET baseline_contract=%s',modern_fixture_attestation())))throw new RuntimeException($db->last_error);
}

function seed_commerce_foundation( wpdb $db ): void {
	$t = new \RodolfoRizzo\Cpt2Collection\TableNames( $db );
	foreach ( array(
		"INSERT INTO {$t->commerce_epochs()} VALUES(1,REPEAT('b',32),'2026-01-01 00:00:00',NULL)",
		"INSERT INTO {$t->commerce_control()} (id,installation_id,current_epoch_id,state,updated_at) VALUES(1,REPEAT('a',32),1,'ready','2026-01-01 00:00:00')",
	) as $sql ) { if ( false === $db->query( $sql ) ) { throw new RuntimeException( $db->last_error ); } }
}
function create_commerce_foundation( wpdb $db ): array {
	$t = new \RodolfoRizzo\Cpt2Collection\TableNames( $db );
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-database.php' );
	preg_match_all( '/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s', $source, $matches );
	$created = array();
	foreach ( $matches[1] as $definition ) {
		if ( ! str_contains( $definition, 'commerce_' ) ) { continue; }
		$definition = preg_replace_callback( '/\{\$tables->([a-z_]+)\(\)\}/', fn( $m ) => $t->{$m[1]}(), $definition );
		if ( false === $db->query( 'CREATE TABLE ' . $definition . ' ENGINE=InnoDB' ) ) { throw new RuntimeException( $db->last_error ); }
		preg_match( '/\A([^\s]+)/', $definition, $name ); $created[] = $name[1];
	}
	seed_commerce_foundation( $db );
	return $created;
}

/** Completes the production Phase 2 migration for a full legacy-schema fixture. */
function migrate_commerce_phase_two_fixture( wpdb $db ): array {
	require_once dirname( __DIR__, 2 ) . '/includes/class-commerce-phase-two-migration.php';
	$tables = new \RodolfoRizzo\Cpt2Collection\TableNames( $db );
	if ( ! \RodolfoRizzo\Cpt2Collection\CommercePhaseTwoMigration::run( $db ) ) {
		throw new RuntimeException( 'Phase 2 fixture migration failed: ' . $db->last_error );
	}
	// Legacy regression fixtures insert current-epoch rows directly. A test-only
	// default keeps those seeds concise while production retains explicit ownership.
	foreach ( array( 'carts', 'orders', 'reservations', 'payment_sessions' ) as $method ) {
		if ( false === $db->query( 'ALTER TABLE ' . $tables->{$method}() . ' ALTER epoch_id SET DEFAULT 1' ) ) {
			throw new RuntimeException( $db->last_error );
		}
	}
	return array( $tables->provider_claims(), $tables->provider_identities() );
}

/** Native captured evidence for direct-SQL modern fixtures, not a production backfill. */
function native_provider_claim_fixture(wpdb $db, int $id): void {
 $t=new \RodolfoRizzo\Cpt2Collection\TableNames($db);$r=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$id);
 $snapshot=json_encode(['version'=>2,'reference'=>$r->reference,'idempotency_key'=>'c2c_ps_'.$r->reference,'currency'=>'EUR','amount_minor'=>100,'deadline'=>'2030-01-01 00:00:00','items'=>[['name'=>'Fixture','unit_amount'=>100,'quantity'=>1]],'configuration'=>['environment'=>'test','installation_id'=>$r->installation_id,'epoch_identity'=>$r->epoch_identity]],JSON_THROW_ON_ERROR);
 if(false===$db->update($t->provider_claims(),['request_version'=>2,'request_snapshot'=>$snapshot,'environment'=>'test','evidence_state'=>'native'],['id'=>$id],['%d','%s','%s','%s'],['%d']))throw new RuntimeException($db->last_error);
}

/** Explicit current retained storage, never repairs runtime reads. */
function current_provider_runtime_fixture(wpdb $db):void {
 $files=['commerce-reset-retained-evidence','commerce-reset-record','commerce-baseline-migration','commerce-phase-three-checkpoint-one-migration','commerce-phase-four-checkpoint-one-migration','commerce-evidence-generation-migration','commerce-baseline-purge-migration','commerce-order-source-index-migration','commerce-claim-session-index-migration','commerce-deleted-order-indexes-migration','payment-notification-migration','order-customer-migration'];
 foreach($files as $file)require_once dirname(__DIR__,2).'/includes/class-'.$file.'.php';
 $t=new \RodolfoRizzo\Cpt2Collection\TableNames($db);
 foreach(['carts','orders','reservations','payment_sessions'] as $m)if(false===$db->query('ALTER TABLE '.$t->$m().' ALTER epoch_id DROP DEFAULT'))throw new RuntimeException($db->last_error);
 foreach(['CommercePhaseThreeCheckpointOneMigration','CommercePhaseFourCheckpointOneMigration','CommerceEvidenceGenerationMigration','CommerceBaselinePurgeMigration','CommerceOrderSourceIndexMigration','CommerceClaimSessionIndexMigration','CommerceDeletedOrderIndexesMigration','PaymentNotificationMigration','OrderCustomerMigration'] as $name){
  $class='RodolfoRizzo\\Cpt2Collection\\'.$name;
  $present=match($name){'CommercePhaseThreeCheckpointOneMigration'=>[$t->order_deletions(),null],'CommercePhaseFourCheckpointOneMigration'=>[$t->commerce_resets(),null],'CommerceEvidenceGenerationMigration'=>[$t->commerce_resets(),'evidence_generation'],'CommerceBaselinePurgeMigration'=>[$t->commerce_control(),'baseline_purge'],default=>null};
  if($present!==null){$q=$present[1]===null?$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$present[0]):$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',...$present);if((int)$db->get_var($q)===1)continue;}
  if(!$class::run($db))throw new RuntimeException('Current provider fixture schema: '.$name.' '.$db->last_error);
 }
 foreach(['carts','orders','reservations','payment_sessions'] as $m)if(false===$db->query('ALTER TABLE '.$t->$m().' ALTER epoch_id SET DEFAULT 1'))throw new RuntimeException($db->last_error);
}
