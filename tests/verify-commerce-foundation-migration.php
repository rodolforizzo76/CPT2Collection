<?php
/** Real production DDL/bootstrap, operational preservation, and corruption recovery. */
declare( strict_types=1 );
$wp_fixture = sys_get_temp_dir() . '/c2c_m8_wp_' . bin2hex(random_bytes(5));
mkdir($wp_fixture.'/wp-admin/includes',0700,true); file_put_contents($wp_fixture.'/wp-admin/includes/upgrade.php','<?php');
define('ABSPATH',$wp_fixture.'/');
require __DIR__.'/support/commerce-db-test.php';
require dirname(__DIR__).'/includes/class-database.php';
require dirname(__DIR__).'/includes/class-post-types.php';
use RodolfoRizzo\Cpt2Collection\{Database,TableNames,CommerceFoundation,TransactionManager};
$options=[]; $ddl=[];
function get_option(string $key,mixed $default=false):mixed { return $GLOBALS['options'][$key]??$default; }
function update_option(string $key,mixed $value,bool $autoload=true):bool { $GLOBALS['options'][$key]=$value; return true; }
function dbDelta(string $sql):array { global $wpdb,$ddl; $ddl[]=$sql; sql($wpdb,str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql)); return []; }
class BootstrapDb extends wpdb {
	public bool $lose_commit=false;
	public function query(string $sql):int|false { $r=parent::query($sql); if($this->lose_commit && $sql==='COMMIT'){ $this->last_error='response lost'; return false; } return $r; }
}
$prefix='c2c_boot_'.bin2hex(random_bytes(5)).'_'; $wpdb=new BootstrapDb($prefix); $t=new TableNames($wpdb);
$operational=['carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events'];
function snapshot(wpdb $db,TableNames $t,array $names):string { $data=[]; foreach($names as $name)$data[$name]=$db->get_results('SELECT * FROM '.$t->$name().' ORDER BY id'); $data['stock']=$db->get_results('SELECT * FROM '.$db->postmeta.' ORDER BY meta_id'); return serialize($data); }
try {
	Database::maybe_upgrade(); check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.13.0','fresh installation completes Phase 4.1 without configured CPT');
	check(\RodolfoRizzo\Cpt2Collection\CommercePhaseThreeCheckpointOneMigration::verify_structure($wpdb,$t),'fresh installation contains verified order-deletion table');
	$first=serialize([$wpdb->get_results('SELECT * FROM '.$t->commerce_control()),$wpdb->get_results('SELECT * FROM '.$t->commerce_epochs())]);
	check(CommerceFoundation::initialize($wpdb),'repeated initialization verifies existing rows');
	check($first===serialize([$wpdb->get_results('SELECT * FROM '.$t->commerce_control()),$wpdb->get_results('SELECT * FROM '.$t->commerce_epochs())]),'repeated initialization preserves exact identities and timestamps');
	sql($wpdb,"CREATE TABLE {$wpdb->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB");
	sql($wpdb,"INSERT INTO {$wpdb->postmeta} VALUES(1,1,'_cpt2collection_stock_quantity','0'),(2,2,'_cpt2collection_stock_quantity','malformed')");
	$at="'2026-01-01 00:00:00'";
	sql($wpdb,"INSERT INTO {$t->carts()}(epoch_id,id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES(1,1,REPEAT('c',64),$at,$at,$at,'2030-01-01')");
	sql($wpdb,"INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,created_at,updated_at) VALUES(1,1,2,$at,$at)");
	sql($wpdb,"INSERT INTO {$t->orders()}(epoch_id,id,cart_id,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at,cart_reconciliation_status) VALUES(1,1,1,'paid','EUR',200,200,$at,$at,$at,'completed')");
	sql($wpdb,"INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,1,'Snapshot',100,2,200,200,'EUR',$at)");
	sql($wpdb,"INSERT INTO {$t->reservations()}(epoch_id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at,converted_at) VALUES(1,1,1,1,2,'converted',$at,$at,$at,$at)");
	sql($wpdb,"INSERT INTO {$t->payment_sessions()}(epoch_id,id,reference,order_id,provider,provider_session_id,creation_key_hash,status,currency,amount_minor,created_at,updated_at,completed_at) VALUES(1,1,REPEAT('d',32),1,'stripe_checkout','cs_old',REPEAT('e',64),'completed','EUR',200,$at,$at,$at)");
	sql($wpdb,"INSERT INTO {$t->payment_events()}(epoch_id,ownership_state,provider,provider_event_id,event_type,livemode,payment_session_id,processing_status,received_at,payload_sha256) VALUES(1,'bound','stripe_checkout','evt_old','checkout.session.completed',0,1,'processed',$at,REPEAT('f',64))");
	$before=snapshot($wpdb,$t,$operational); sql($wpdb,'DROP TABLE '.$t->commerce_control().','.$t->commerce_epochs());
	$options[Database::OPTION_SCHEMA_VERSION]='0.9.0'; $ddl=[]; Database::maybe_upgrade();
	check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.13.0' && count($ddl)===2,'0.9 upgrade applies foundation DDL and completes sequential migrations through 0.13.0');
	check($before===snapshot($wpdb,$t,$operational),'all operational rows, paid evidence, reconciliation and stock remain byte-for-byte unchanged');
	foreach(['control','epoch','retired','extra'] as $case) {
		sql($wpdb,'DELETE FROM '.$t->commerce_control()); sql($wpdb,'DELETE FROM '.$t->commerce_epochs()); seed_commerce_foundation($wpdb);
		if($case==='control')sql($wpdb,'DELETE FROM '.$t->commerce_control());
		if($case==='epoch')sql($wpdb,'DELETE FROM '.$t->commerce_epochs());
		if($case==='retired')sql($wpdb,'UPDATE '.$t->commerce_epochs().' SET retired_at=NOW()');
		if($case==='extra')sql($wpdb,'INSERT INTO '.$t->commerce_control().' SELECT 2,installation_id,current_epoch_id,state,updated_at FROM '.$t->commerce_control());
		check(!CommerceFoundation::initialize($wpdb),"partial/corrupt $case is not regenerated");
		Database::maybe_upgrade(); $tx=new TransactionManager($wpdb,$t); check(!$tx->begin_commerce_shared(),"completed schema with $case corruption fails admission");
	}
	sql($wpdb,'DELETE FROM '.$t->commerce_control()); sql($wpdb,'DELETE FROM '.$t->commerce_epochs());
	Database::maybe_upgrade();
	check(0===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t->commerce_control()) && 0===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t->commerce_epochs()),'completed schema never repairs empty foundation during normal bootstrap');
	sql($wpdb,'ALTER TABLE '.$t->commerce_control().' MODIFY installation_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
	check(!CommerceFoundation::verify_structure($wpdb,$t),'incorrect identity storage type refuses verification');
	sql($wpdb,'ALTER TABLE '.$t->commerce_control().' MODIFY installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
	$wpdb->lose_commit=true; check(!CommerceFoundation::initialize($wpdb) && !$wpdb->transaction_connection_usable(),'uncertain initialization commit is not success'); $wpdb->close();
	$wpdb=new BootstrapDb($prefix); check(CommerceFoundation::initialize($wpdb),'fresh connection verifies accepted uncertain initialization without replacement');
	check($before===snapshot($wpdb,$t,$operational),'failure/recovery has no operational side effects');
} finally {
	$wpdb->close(); $cleanup=new wpdb($prefix); foreach(array_merge($operational,['commerce_control','commerce_epochs','provider_claims','provider_identities','order_deletions','commerce_resets']) as $method)$cleanup->query('DROP TABLE IF EXISTS '.$t->$method()); $cleanup->query('DROP TABLE IF EXISTS '.$cleanup->postmeta); $cleanup->close();
	unlink($wp_fixture.'/wp-admin/includes/upgrade.php'); rmdir($wp_fixture.'/wp-admin/includes'); rmdir($wp_fixture.'/wp-admin'); rmdir($wp_fixture);
}
