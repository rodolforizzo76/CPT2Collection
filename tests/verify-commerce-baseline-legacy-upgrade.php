<?php
/** Genuine no-Event legacy reproduction and its controlled one-time baseline purge. */
declare( strict_types=1 );
require __DIR__ . '/support/baseline-purge.php';

use RodolfoRizzo\Cpt2Collection\{CommercePhaseTwoMigration,CommercePhaseThreeCheckpointOneMigration,CommercePhaseFourCheckpointOneMigration,CommerceEvidenceGenerationMigration,ProviderClaimRecord,TableNames,TransactionManager,ProviderRegistry,PaymentSessionRecord};

$db = new wpdb( 'c2c_a1legacy_' . bin2hex( random_bytes( 5 ) ) . '_' );
$t = new TableNames( $db );
$created = phase_one_schema( $db );
try {
	$at = '2026-01-01 00:00:00';
 sql($db,"CREATE TABLE {$db->posts}(ID BIGINT PRIMARY KEY,post_type VARCHAR(32)) ENGINE=InnoDB");
 sql($db,"CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB");
 sql($db,"INSERT INTO {$db->posts} VALUES(10,'collection_item')");sql($db,"INSERT INTO {$db->postmeta} VALUES(1,10,'_cpt2collection_stock_quantity','7')");

	// Genuine pre-Phase-2 shape: no Event has been received, so no captured livemode.
	sql( $db, "INSERT INTO {$t->orders()}(id,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,'awaiting_payment','EUR',100,100,'$at','$at')" );
	sql( $db, "INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,10,'Legacy',100,1,100,100,'EUR','$at')" );
	sql( $db, "INSERT INTO {$t->reservations()}(order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES(1,1,10,1,'active','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$t->payment_sessions()}(id,reference,order_id,provider,provider_session_id,creation_key_hash,status,active_slot,currency,amount_minor,redirect_url,created_at,updated_at,expires_at) VALUES(1,REPEAT('c',32),1,'stripe_checkout','cs_legacy_without_mode',REPEAT('d',64),'active',1,'EUR',100,'https://example.test/pay','$at','$at','2030-01-01')" );
	check( CommercePhaseTwoMigration::run( $db ), 'production Phase 2 migration accepts legacy session without Events' );
	$claim = $db->get_row( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id=1' );
	check( is_object( $claim ) && 'legacy_incomplete' === $claim->evidence_state && '1' === (string) $claim->request_version && null === $claim->request_snapshot && null === $claim->environment, 'migration produces version-1 legacy claim with unknown environment' );
	$identity = $db->get_row( 'SELECT * FROM ' . $t->provider_identities() . ' WHERE id=1' );
	check( is_object( $identity ) && 'bound' === $identity->binding_state && '1' === (string) $identity->claim_id && null === $identity->environment && 'legacy_incomplete' === $identity->evidence_state, 'durable Session identity also has no environment evidence' );
	check( '0' === (string) $db->get_var( 'SELECT COUNT(*) FROM ' . $t->payment_events() ), 'no Event can independently establish test/live' );
	$before = serialize( array( $claim, $identity ) );
	check( CommercePhaseThreeCheckpointOneMigration::run( $db ), 'production migration reaches 0.12' );
	check( CommercePhaseFourCheckpointOneMigration::run( $db ), 'production migration reaches 0.13' );
	check( CommerceEvidenceGenerationMigration::run( $db ), 'production migration reaches 0.14' );
	check( $before === serialize( array( $db->get_row( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id=1' ), $db->get_row( 'SELECT * FROM ' . $t->provider_identities() . ' WHERE id=1' ) ) ), 'all later migrations preserve unknown historical environment' );
	$tx = new TransactionManager( $db, $t );
	check( !$tx->begin_commerce_shared(), 'current runtime refuses unattested pre-baseline authority' );
	$rejected = false;
	try { ProviderClaimRecord::from_row( $claim ); }
	catch ( InvalidArgumentException $error ) { $rejected = 'Unproven claim provider/environment.' === $error->getMessage(); }
	check( $rejected, 'modern claim parser rejects unproven pre-baseline environment' );
 $preserved=purge_preserved($db);
 check(\RodolfoRizzo\Cpt2Collection\CommerceBaselineMigration::run($db),'Checkpoint 1 prepares genuine incomplete legacy fixture');
 $b=purge_baseline($db);check($before===serialize([$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id=1'),$db->get_row('SELECT * FROM '.$t->provider_identities().' WHERE id=1')]),'preparation preserves real legacy incompleteness');
 check(\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db),'legacy fixture reaches purge schema');purge_to_end($db,$b);
 check(purge_preserved($db)===$preserved,'legacy purge preserves stock products configuration reset foundation');
 check(purge_baseline($db)['first_epoch_identity']===$b['first_epoch_identity']&&purge_baseline($db)['status']==='completed','legacy purge completes on originally reserved modern boundary');
 foreach(\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeRecord::STAGES as $m)check($db->get_var('SELECT id FROM '.$t->$m().' LIMIT 1')===null,'genuine legacy domain gone: '.$m);
 $snap=baseline_snapshot($db);$p=modern_payload(str_repeat('c',32),'evt_new_for_discarded_legacy');$p['data']['object']['id']='cs_legacy_without_mode';
 check(webhook_for($db)->handle($p,hash('sha256',json_encode($p)))->code()==='pre_baseline'&&baseline_snapshot($db)===$snap,'new Event cannot recreate physically discarded genuine legacy provider state');
} finally {
	if ( isset( $tx ) && $tx->is_active() ) { $tx->rollback(); }
 baseline_cleanup($db);
}
