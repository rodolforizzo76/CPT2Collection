<?php
/** Disposable historical attribution fixtures; never production reset start. */
declare( strict_types=1 );
require_once __DIR__ . '/reset-maintenance.php';
use RodolfoRizzo\Cpt2Collection\{CommerceEvidenceContext,ProviderClaimRecord,CommerceResetRecord,TableNames,PaymentProviderSessionRequest,PaymentProviderLineItem};
function evidence_context( wpdb $db ): CommerceEvidenceContext {
	$t = new TableNames( $db );
	return new CommerceEvidenceContext( CommerceResetRecord::from_row( $db->get_row( 'SELECT * FROM ' . $t->commerce_resets() . ' WHERE id=1' ) ), ProviderClaimRecord::from_row( $db->get_row( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id=1' ) ) );
}
function evidence_fixture( wpdb $db ): CommerceEvidenceContext {
	modern_maintenance_schema( $db ); maintenance_fixture( $db ); $t = new TableNames( $db );
	$row = $db->get_row( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id=1' );
	$request = new PaymentProviderSessionRequest( $row->reference, $row->idempotency_key, 'EUR', 100, array( new PaymentProviderLineItem( 'Fixture', 100, 1 ) ), '2030-01-01 00:00:00', array( 'environment'=>'test', 'installation_id'=>$row->installation_id, 'epoch_identity'=>$row->epoch_identity ) );
	check( false !== $db->update( $t->provider_claims(), array( 'request_version'=>2, 'request_snapshot'=>$request->snapshot(), 'evidence_state'=>'native' ), array( 'id'=>1 ), array('%d','%s','%s'), array('%d') ), 'native durable captured request fixture' );
	sql( $db, "UPDATE {$t->commerce_control()} SET state='resetting'" );
	return evidence_context( $db );
}
/** Only identities and their operation evidence_generation may change. */
function evidence_preserved_snapshot( wpdb $db ): string {
	$rows = unserialize( maintenance_snapshot( $db ) ); unset( $rows['provider_identities'] ); foreach ( $rows['commerce_resets'] as $row ) { unset( $row->evidence_generation ); } return serialize( $rows );
}
