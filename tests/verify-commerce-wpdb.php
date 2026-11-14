<?php
/** Real WordPress wpdb + disposable MariaDB connection-loss verification.
 * C2C_TEST_WORDPRESS_PATH points to a WordPress source tree; it is never booted.
 */
declare(strict_types=1);
$wordpress = getenv('C2C_TEST_WORDPRESS_PATH');
if (!$wordpress || !getenv('C2C_TEST_DB_NAME')) { fwrite(STDERR,"C2C_TEST_WORDPRESS_PATH and C2C_TEST_DB_NAME required.\n"); exit(1); }
define('ABSPATH',rtrim($wordpress,'/').'/'); define('WP_DEBUG',false); define('WP_DEBUG_DISPLAY',false);
define('DB_USER',getenv('C2C_TEST_DB_USER')?:'root'); define('DB_PASSWORD',getenv('C2C_TEST_DB_PASSWORD')?:''); define('DB_NAME',getenv('C2C_TEST_DB_NAME')); define('DB_HOST',(getenv('C2C_TEST_DB_HOST')?:'127.0.0.1').':'.(getenv('C2C_TEST_DB_PORT')?:3306)); define('DB_CHARSET','utf8mb4'); define('DB_COLLATE','');
function absint($v){return abs((int)$v);} function apply_filters($tag,$value,...$args){return $value;} function add_filter(...$args){} function has_filter(...$args){return false;} function did_action(...$args){return false;} function is_multisite(){return false;} function wp_debug_backtrace_summary(...$args){return '';} function wp_load_translations_early(){} function __($s){return $s;} function is_wp_error($v){return false;}
// PHP 8 has no mbstring.func_overload; WordPress's byte-mode wrappers are no-ops here.
function mbstring_binary_safe_encoding($reset=false){} function reset_mbstring_encoding(){}
require ABSPATH.'wp-includes/class-wpdb.php';
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/includes/class-commerce-wpdb.php';
require dirname(__DIR__).'/includes/class-table-names.php';
require dirname(__DIR__).'/includes/class-provider-registry.php';
require dirname(__DIR__).'/includes/class-commerce-reset-repository.php';
require_once dirname(__DIR__).'/includes/class-transaction-manager.php';
require __DIR__.'/support/commerce-fixtures.php';
require dirname(__DIR__).'/includes/class-commerce-phase-two-migration.php';
require dirname(__DIR__).'/includes/class-commerce-phase-four-checkpoint-one-migration.php';
use RodolfoRizzo\Cpt2Collection\{CommerceWpdb,TableNames,TransactionManager,CommercePhaseFourCheckpointOneMigration,CommerceResetRecord,CommercePhaseTwoMigration,CommerceEvidenceContext,ProviderClaimRecord};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$global=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $global->suppress_errors(true);
$prefix='c2c_native_'.bin2hex(random_bytes(5)).'_'; $global->set_prefix($prefix);
$created=create_commerce_foundation($global);attest_modern_runtime_fixture($global); $table=$prefix.'probe'; $global->query("CREATE TABLE $table(id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB"); $global->query("INSERT INTO $table VALUES(1,0)");
$tables=new TableNames($global);
check(false!==$global->query(CommercePhaseFourCheckpointOneMigration::ddl($tables)), 'real wpdb reset fixture table created');
$created[]=$tables->commerce_resets();
check(false!==$global->query('ALTER TABLE '.$tables->commerce_resets().' ADD evidence_generation BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD validation_generation BIGINT UNSIGNED NULL DEFAULT NULL'),'real wpdb v14 fixture columns');
check(false!==$global->query("INSERT INTO {$tables->commerce_resets()} (operation_identity,installation_id,source_epoch_id,source_epoch_identity,target_epoch_identity,active_slot,status,phase,started_at,updated_at) VALUES(REPEAT('c',32),REPEAT('a',32),1,REPEAT('b',32),REPEAT('d',32),1,'running','validating','2026-01-01','2026-01-01')"),'real wpdb durable reset fixture created');
$expected=CommerceResetRecord::from_row($global->get_row('SELECT * FROM '.$tables->commerce_resets()));
foreach(CommercePhaseTwoMigration::ddl($tables) as $ddl){check(false!==$global->query($ddl),'real wpdb provider schema fixture');}
$created[]=$tables->provider_claims();$created[]=$tables->provider_identities();
check(false!==$global->query("INSERT INTO {$tables->provider_claims()} (installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,environment,evidence_state,disposition,created_at) VALUES(REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout',REPEAT('c',32),1,1,REPEAT('d',64),CONCAT('c2c_ps_',REPEAT('c',32)),2,'test','native','open','2026-01-01')"),'real wpdb durable claim fixture');native_provider_claim_fixture($global,1);
$evidence=new CommerceEvidenceContext($expected,ProviderClaimRecord::from_row($global->get_row('SELECT * FROM '.$tables->provider_claims())));
try {
 foreach(['normal','maintenance','evidence'] as $mode){
 $global->query("UPDATE {$tables->commerce_control()} SET state='".('normal'===$mode?'ready':'resetting')."'");
 foreach(['before_write','after_write','commit','rollback'] as $stage){
  $db=CommerceWpdb::connect($global);$db->suppress_errors(true);$tx=new TransactionManager($db,new TableNames($db));
  check(match($mode){'normal'=>$tx->begin_commerce_shared(),'maintenance'=>$tx->begin_reset_maintenance($expected),default=>$tx->begin_historical_evidence($evidence)},'real wpdb '.$mode.' admission '.$stage);
  $nested=new TransactionManager($db,new TableNames($db));check(!$nested->begin(),'real wpdb rejects nested owner');
  if($stage!=='before_write')check(1===$db->query("UPDATE $table SET value=value+1 WHERE id=1"),'transactional write before loss');
  $id=(int)$db->get_var('SELECT CONNECTION_ID()');check(false!==$global->query('KILL CONNECTION '.$id),'kill original transaction connection');
  if($stage==='commit')check(!$tx->commit(),'lost COMMIT is not success');
  elseif($stage==='rollback')check(!$tx->rollback(),'lost ROLLBACK is not confirmed');
  else {check(false===$db->query("UPDATE $table SET value=value+1 WHERE id=1"),'lost write never replays');check(!$tx->rollback(),'lost write leaves uncertain rollback');}
  check($tx->is_outcome_uncertain()&&!$db->transaction_connection_usable()&&!$tx->begin_commerce_shared()&&!$tx->begin_reset_maintenance($expected)&&null===$tx->reset_context()&&null===$tx->evidence_context()&&!$tx->begin_historical_evidence($evidence),'lost connection cannot regain admission');
  check(false===$db->query("UPDATE $table SET value=99 WHERE id=1"),'poisoned wpdb executes no follow-up');
  check('0'===(string)$global->get_var("SELECT value FROM $table WHERE id=1"),'no write escaped into autocommit');
 }
 }
 // Actual historical binding, followed by server connection loss: neither half survives.
 $db=CommerceWpdb::connect($global);$db->suppress_errors(true);$tx=new TransactionManager($db,new TableNames($db));
 $registry=new \RodolfoRizzo\Cpt2Collection\ProviderRegistry($db,new TableNames($db));
 check($tx->begin_historical_evidence($evidence) && 'bound'===$registry->bind_historical($evidence,'stripe_checkout','session','cs_generation_loss','2026-01-02 00:00:00','test'),'real wpdb writes identity and generation in owned transaction');
 check($tx->evidence_context()->operation()->evidence_generation()===1,'real wpdb refreshes generation context');
 $id=(int)$db->get_var('SELECT CONNECTION_ID()');check(false!==$global->query('KILL CONNECTION '.$id),'kill generation transaction before commit');
 check(!$tx->commit() && $tx->is_outcome_uncertain() && !$tx->begin_historical_evidence($evidence),'lost generation commit poisons real wpdb owner');
 $fresh=CommerceWpdb::connect($global);$inspection=new TransactionManager($fresh,new TableNames($fresh));
 check($inspection->begin_historical_evidence($evidence),'fresh locked admission drains killed generation transaction');
 check('0'===(string)$fresh->get_var('SELECT evidence_generation FROM '.$tables->commerce_resets()) && '0'===(string)$fresh->get_var('SELECT COUNT(*) FROM '.$tables->provider_identities()),'fresh connection sees neither uncommitted identity nor generation');
 check($inspection->rollback(),'fresh recovery releases authority');$fresh->close();
 $global->query("UPDATE {$tables->commerce_control()} SET state='ready'");
 $db=CommerceWpdb::connect($global);$tx=new TransactionManager($db,new TableNames($db));
 check($tx->begin_commerce_shared()&&$tx->commit()&&$tx->begin_commerce_shared()&&$tx->rollback(),'real wpdb confirmed termination permits reuse');
 check($global->get_var('SELECT 1')==1,'global wpdb connection remains untouched');$db->close();
} finally {if(isset($db)){$db->close();}foreach(array_merge([$table],array_reverse($created)) as $name)$global->query('DROP TABLE IF EXISTS '.$name);$global->close();}
