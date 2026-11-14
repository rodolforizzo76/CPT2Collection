<?php
/** Test-only durable reset fence and populated operational fixtures. */
declare( strict_types=1 );
require_once __DIR__ . '/phase-two-schema.php';
function __( string $text, string $domain = '' ): string { return $text; }
use RodolfoRizzo\Cpt2Collection\{CommercePhaseTwoMigration,CommercePhaseThreeCheckpointOneMigration,CommercePhaseFourCheckpointOneMigration,CommerceResetRecord,TableNames};
function maintenance_schema( wpdb $db, bool $current = true ): void {
	$t = new TableNames( $db );
	phase_one_schema( $db ); $at = '2026-01-01 00:00:00';
	sql( $db, "CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB" );
	sql( $db, "INSERT INTO {$db->postmeta} VALUES(1,10,'_cpt2collection_stock_quantity','7')" );
	sql( $db, "INSERT INTO {$t->carts()}(id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES(1,REPEAT('c',64),'$at','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,created_at,updated_at) VALUES(1,10,1,'$at','$at')" );
	sql( $db, "INSERT INTO {$t->orders()}(id,cart_id,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at) VALUES(1,1,'paid','EUR',100,100,'$at','$at','$at')" );
	sql( $db, "INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,10,'Legacy',100,1,100,100,'EUR','$at')" );
	sql( $db, "INSERT INTO {$t->reservations()}(order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at,converted_at) VALUES(1,1,10,1,'converted','$at','$at','$at','$at')" );
	sql( $db, "INSERT INTO {$t->payment_sessions()}(id,reference,order_id,provider,provider_session_id,provider_payment_id,provider_event_id,creation_key_hash,status,currency,amount_minor,created_at,updated_at,completed_at) VALUES(1,REPEAT('c',32),1,'stripe_checkout','cs_old','pi_old','evt_old',REPEAT('d',64),'completed','EUR',100,'$at','$at','$at')" );
	sql( $db, "INSERT INTO {$t->payment_events()}(provider,provider_event_id,event_type,livemode,payment_session_id,provider_session_id,provider_payment_id,local_reference,processing_status,received_at,payload_sha256) VALUES('stripe_checkout','evt_old','checkout.session.completed',0,1,'cs_old','pi_old',REPEAT('c',32),'processed','$at',REPEAT('e',64))" );
	check( CommercePhaseTwoMigration::run( $db ) && CommercePhaseThreeCheckpointOneMigration::run( $db ), 'fixture reaches genuine schema 0.12 through historical migrations' );
	sql( $db, "INSERT INTO {$t->order_deletions()}(installation_id,epoch_id,epoch_identity,original_order_id,deleted_at,checkout_disposition) SELECT c.installation_id,e.id,e.identity,99,'$at','identity_only' FROM {$t->commerce_control()} c JOIN {$t->commerce_epochs()} e ON e.id=c.current_epoch_id" );
	check( CommercePhaseFourCheckpointOneMigration::run( $db ), 'fixture reaches schema 0.13' );
	if ( $current ) { check( \RodolfoRizzo\Cpt2Collection\CommerceEvidenceGenerationMigration::run( $db ), 'fixture reaches schema 0.14' ); }
}
function maintenance_fixture( wpdb $db ): CommerceResetRecord {
	$t = new TableNames( $db );
	sql( $db, "INSERT INTO {$t->commerce_resets()} (id,operation_identity,installation_id,source_epoch_id,source_epoch_identity,target_epoch_identity,active_slot,status,phase,started_at,updated_at) SELECT 1,REPEAT('c',32),c.installation_id,e.id,e.identity,REPEAT('d',32),1,'running','validating','2026-01-01','2026-01-01' FROM {$t->commerce_control()} c JOIN {$t->commerce_epochs()} e ON e.id=c.current_epoch_id" );
	return CommerceResetRecord::from_row( $db->get_row( 'SELECT * FROM ' . $t->commerce_resets() . ' WHERE id=1' ) );
}
function maintenance_snapshot( wpdb $db ): string {
	$t = new TableNames( $db ); $rows = array();
	foreach ( array( 'commerce_control', 'commerce_epochs', 'commerce_resets', 'carts', 'cart_items', 'orders', 'order_items', 'reservations', 'payment_sessions', 'payment_events', 'provider_claims', 'provider_identities', 'order_deletions' ) as $method ) {
		$db->last_error = ''; $rows[$method] = $db->get_results( 'SELECT * FROM ' . $t->$method() . ' ORDER BY id' );
		check( is_array( $rows[$method] ) && '' === $db->last_error, "$method preservation snapshot readable" );
	}
	$rows['stock'] = $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' );
	check( is_array( $rows['stock'] ) && '' === $db->last_error, 'stock preservation snapshot readable' );
	return serialize( $rows );
}
function maintenance_cleanup( wpdb $db ): void {
	$t = new TableNames( $db );
	foreach ( array( 'commerce_resets', 'order_deletions', 'provider_identities', 'provider_claims', 'payment_events', 'payment_sessions', 'reservations', 'order_items', 'orders', 'cart_items', 'carts', 'commerce_control', 'commerce_epochs' ) as $method ) { sql( $db, 'DROP TABLE IF EXISTS ' . $t->$method() ); }
	sql( $db, 'DROP TABLE IF EXISTS ' . $db->postmeta ); $db->close();
}

/** Synthetic modern populated runtime fixture; upgrade tests keep maintenance_schema(). */
function modern_maintenance_schema(wpdb $db): void {
 maintenance_schema($db);$t=new TableNames($db);attest_modern_runtime_fixture($db);
 sql($db,"UPDATE {$t->orders()} SET cart_revision=0,checkout_generation=0,checkout_attempt_hash=REPEAT('d',64)");
 sql($db,"UPDATE {$t->order_items()} SET source_cart_item_id=1,source_cart_item_version=0");
 native_provider_claim_fixture($db,1);
 sql($db,"UPDATE {$t->provider_identities()} SET evidence_state='native'");
 sql($db,"UPDATE {$t->order_deletions()} SET checkout_disposition='revision',cart_id=99,cart_revision=0,checkout_generation=0,checkout_attempt_hash=REPEAT('e',64)");
}
