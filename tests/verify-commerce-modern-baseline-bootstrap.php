<?php
/** Actual fresh Database bootstrap and acknowledgement routing, with disposable WordPress DDL shim. */
declare(strict_types=1);
$wp_fixture=sys_get_temp_dir().'/c2c_baseline_wp_'.bin2hex(random_bytes(5));
mkdir($wp_fixture.'/wp-admin/includes',0700,true);file_put_contents($wp_fixture.'/wp-admin/includes/upgrade.php','<?php');define('ABSPATH',$wp_fixture.'/');
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{Database,TableNames,TransactionManager,CommerceOrderSourceIndexMigration,CommerceClaimSessionIndexMigration,CommerceDeletedOrderIndexesMigration};
function dbDelta(string $sql):array{global $wpdb;sql($wpdb,str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql));return [];}
$wpdb=new wpdb('c2c_baseboot_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($wpdb);
try{
 Database::activate();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.21.0','actual fresh bootstrap acknowledges v21');
 check(CommerceOrderSourceIndexMigration::verify_structure($wpdb,$t)&&CommerceClaimSessionIndexMigration::verify_structure($wpdb,$t)&&CommerceDeletedOrderIndexesMigration::verify_structure($wpdb,$t),'fresh install verifies exact nonunique v17/v18/v19 access paths');
 $c=$wpdb->get_row('SELECT * FROM '.$t->commerce_control());$b=json_decode($c->baseline_contract,true);
 check($b['status']==='completed'&&$b['first_epoch_id']===1&&$c->state==='ready','actual fresh bootstrap completes baseline without purge');
 $tx=new TransactionManager($wpdb,$t);check($tx->begin_commerce_shared()&&$tx->commit(),'fresh bootstrap supports normal commerce');
 $snapshot=serialize($wpdb->get_results('SELECT * FROM '.$t->commerce_control()));Database::maybe_upgrade();Database::activate();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.21.0'&&serialize($wpdb->get_results('SELECT * FROM '.$t->commerce_control()))===$snapshot,'normal bootstrap does not reallocate baseline');
 // Completed schema never authorizes runtime repair of a missing attestation.
 sql($wpdb,'UPDATE '.$t->commerce_control().' SET baseline_contract=NULL');Database::maybe_upgrade();check(!$tx->begin_commerce_shared()&&!\RodolfoRizzo\Cpt2Collection\CommerceBaselineMigration::run($wpdb),'missing durable contract cannot authorize admission or allocate a second baseline');
}finally{baseline_cleanup($wpdb);unlink($wp_fixture.'/wp-admin/includes/upgrade.php');rmdir($wp_fixture.'/wp-admin/includes');rmdir($wp_fixture.'/wp-admin');rmdir($wp_fixture);}
