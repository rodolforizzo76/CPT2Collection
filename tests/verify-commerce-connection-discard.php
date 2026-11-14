<?php
/** D1.1 native WordPress wpdb + disposable MariaDB discard/reconnect verification.
 * C2C_TEST_WORDPRESS_PATH points to a WordPress source tree; it is never booted.
 */
declare(strict_types=1);
$wordpress = getenv('C2C_TEST_WORDPRESS_PATH');
if (!$wordpress || !getenv('C2C_TEST_DB_NAME')) { fwrite(STDERR,"C2C_TEST_WORDPRESS_PATH and C2C_TEST_DB_NAME required.\n"); exit(1); }
define('ABSPATH',rtrim($wordpress,'/').'/'); define('WP_DEBUG',false); define('WP_DEBUG_DISPLAY',false);
define('DB_USER',getenv('C2C_TEST_DB_USER')?:'root'); define('DB_PASSWORD',getenv('C2C_TEST_DB_PASSWORD')?:''); define('DB_NAME',getenv('C2C_TEST_DB_NAME')); define('DB_HOST',(getenv('C2C_TEST_DB_HOST')?:'127.0.0.1').':'.(getenv('C2C_TEST_DB_PORT')?:3306)); define('DB_CHARSET','utf8mb4'); define('DB_COLLATE','');
function absint($v){return abs((int)$v);} function apply_filters($tag,$value,...$args){if($tag==='query'&&isset($GLOBALS['discard_query_filter']))return ($GLOBALS['discard_query_filter'])($value);return $value;} function add_filter(...$args){} function has_filter(...$args){return false;} function did_action(...$args){return false;} function is_multisite(){return false;} function wp_debug_backtrace_summary(...$args){return '';} function wp_load_translations_early(){} function __($s){return $s;} function is_wp_error($v){return false;}
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
spl_autoload_register(static function(string $class):void {
 $short=strtolower(substr($class,strrpos($class,'\\')+1));foreach(glob(dirname(__DIR__).'/includes/class-*.php') as $file){if(str_replace('-','',substr(basename($file),6,-4))===$short){require_once $file;return;}}
});
use RodolfoRizzo\Cpt2Collection\{CommerceWpdb,TableNames,TransactionManager,CommercePhaseFourCheckpointOneMigration,CommerceResetRecord,CommercePhaseTwoMigration,CommerceEvidenceContext,ProviderClaimRecord};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$global=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$global->suppress_errors(true);
$prefix='c2c_discard_'.bin2hex(random_bytes(5)).'_';$global->set_prefix($prefix);$tables=new TableNames($global);
$created=create_commerce_foundation($global);attest_modern_runtime_fixture($global);
$table=$prefix.'probe';$global->query("CREATE TABLE $table(id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB");$created[]=$table;$global->query("INSERT INTO $table VALUES(1,0)");
function revoked(CommerceWpdb $db,TransactionManager $tx,string $mode):void {
 check(!TransactionManager::admitted_on($db)&&TransactionManager::context_on($db)===null&&TransactionManager::reset_context_on($db)===null&&TransactionManager::reset_control_on($db)===null&&TransactionManager::reset_control_state_on($db)===null&&TransactionManager::reset_deletion_on($db)===null&&TransactionManager::reset_finalization_on($db)===null&&TransactionManager::evidence_context_on($db)===null&&TransactionManager::retained_context_on($db)===null&&!TransactionManager::retained_admission_on($db)&&!TransactionManager::continuation_inspection_on($db)&&!TransactionManager::historical_identity_mutation_on($db)&&!TransactionManager::reset_coverage_identity_mutation_on($db),'all authority revoked '.$mode);
 check(!$db->transaction_connection_usable()&&!$tx->begin()&&!$db->pin_transaction(new stdClass()),'connection permanently unusable '.$mode);
 if($tx->is_active())check($tx->is_outcome_uncertain()&&!$tx->commit()&&!$tx->rollback(),'discard preserves unconfirmed outcome '.$mode);
 $owners=(new ReflectionProperty(TransactionManager::class,'owners'))->getValue();check(!isset($owners[$db]),'registry detached '.$mode);
}
function private_db(wpdb $global):CommerceWpdb {$db=CommerceWpdb::connect($global);$db->suppress_errors(true);return $db;}
try {
 $db=private_db($global);$tx=new TransactionManager($db,new TableNames($db));
 check($tx->begin_commerce_shared(),'healthy native normal admission');
 check(1===$db->query("UPDATE $table SET value=1 WHERE id=1"),'real uncommitted SQL before close');
 $id=(int)$db->get_var('SELECT CONNECTION_ID()');$db->close();
 check('0'===(string)$global->get_var("SELECT value FROM $table"),'physical close rolls back uncommitted probe');
 if(in_array('--reproduce',$argv,true)) {
  echo json_encode(['after_close'=>['active'=>$tx->is_active(),'usable'=>$db->transaction_connection_usable(),'admitted'=>TransactionManager::admitted_on($db)] ])."\n";
  $connected=$db->db_connect(false);
  echo json_encode(['explicit_reconnect'=>$connected,'different_physical_connection'=>$connected&&(int)$db->get_var('SELECT CONNECTION_ID()')!==$id,'stale_admitted'=>TransactionManager::admitted_on($db),'stale_context'=>$tx->commerce_context()!==null])."\n";
  $db->close();return;
 }
 check(!TransactionManager::admitted_on($db)&&$tx->commerce_context()===null&&!$db->transaction_connection_usable(),'close revokes normal authority');
 check(!$db->db_connect(false)&&!$db->check_connection(false),'explicit and implicit reconnection denied after discard');
 check(!$tx->commit()&&!$tx->rollback()&&$tx->is_outcome_uncertain(),'discard is never confirmed transaction termination');
 check(false===$db->query("UPDATE $table SET value=99 WHERE id=1"),'discarded connection executes no SQL');
 check(false===$db->close(),'repeated native close is safe');
 // Defense in depth: bypass ONLY the reconnect override to model a physical reopen.
 check((new ReflectionMethod(wpdb::class,'db_connect'))->invoke($db,false),'test-only parent reconnect creates a real replacement socket');
 check((int)$global->get_var('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID='.$id)===0,'original physical connection is gone');
 check(!TransactionManager::admitted_on($db)&&false===$db->query("UPDATE $table SET value=99 WHERE id=1"),'physical replacement cannot revive authority or authorize SQL');
 check('0'===(string)$global->get_var("SELECT value FROM $table"),'observer sees zero stale writes after physical replacement');$db->close();

 // Minimal current retained schema uses production DDL and existing v18/v19 indexes.
 foreach(CommercePhaseTwoMigration::ddl($tables) as $ddl)check(false!==$global->query($ddl),'native provider fixture DDL');
 $created[]=$tables->provider_claims();$created[]=$tables->provider_identities();
 foreach(['CommercePhaseThreeCheckpointOneMigration','CommercePhaseFourCheckpointOneMigration'] as $name){$class='RodolfoRizzo\\Cpt2Collection\\'.$name;check(false!==$global->query($class::ddl($tables)),'native retained fixture DDL');}
 $created[]=$tables->order_deletions();$created[]=$tables->commerce_resets();
 check(false!==$global->query('ALTER TABLE '.$tables->commerce_resets().' ADD evidence_generation BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD validation_generation BIGINT UNSIGNED NULL'),'native generation fixture');
 check(false!==$global->query('ALTER TABLE '.$tables->provider_claims().' ADD KEY payment_session_id(payment_session_id), ADD KEY order_id(order_id)'),'native claim lookup indexes');
 check(false!==$global->query('ALTER TABLE '.$tables->order_deletions().' ADD KEY original_order_id(original_order_id)'),'native deletion lookup index');
 check(false!==$global->query("INSERT INTO {$tables->provider_claims()} (installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,environment,evidence_state,disposition,created_at) VALUES(REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout',REPEAT('c',32),1,1,REPEAT('d',64),CONCAT('c2c_ps_',REPEAT('c',32)),2,'test','native','open','2026-01-01')"),'native claim fixture');
 native_provider_claim_fixture($global,1);
 $claim=ProviderClaimRecord::from_row($global->get_row('SELECT * FROM '.$tables->provider_claims()));
 $operation=\RodolfoRizzo\Cpt2Collection\CommittedOperation::native_request($claim,'2026-10-21 00:00:00');
 foreach(['retained','control','inspection','snapshot','poison','commit_before','commit_after','exception','replacement','idle'] as $mode){
  $global->query("UPDATE $table SET value=0 WHERE id=1");
  $db=private_db($global);$tx=new TransactionManager($db,new TableNames($db));
  if($mode==='retained')check($tx->begin_retained_evidence($operation->candidate)?->status==='attributed'&&TransactionManager::retained_context_on($db)!==null,'native retained evidence admission');
  elseif($mode==='control')check($tx->begin_reset_control()&&TransactionManager::reset_control_on($db)!==null,'native reset/control admission');
  elseif($mode==='inspection')check($tx->begin_continuation_inspection()&&TransactionManager::continuation_inspection_on($db),'native continuation inspection');
  elseif($mode==='snapshot')check($tx->begin_read_snapshot(),'native read snapshot');
  elseif($mode!=='idle')check($tx->begin_commerce_shared(),'native normal admission '.$mode);
  if(in_array($mode,['poison','commit_before','commit_after','exception','replacement'],true))check(1===$db->query("UPDATE $table SET value=7 WHERE id=1"),'native uncommitted effect '.$mode);
  if($mode==='poison')$db->poison_transaction();
  if(str_starts_with($mode,'commit_')){
   $GLOBALS['discard_query_filter']=function($q)use($mode,$db){if($q==='COMMIT'){if($mode==='commit_after')$db->dbh->commit();throw new RuntimeException('Injected missing acknowledgement');}return $q;};
   check(!$tx->commit()&&$tx->is_outcome_uncertain(),'native unknown COMMIT '.$mode);unset($GLOBALS['discard_query_filter']);
  }
  if($mode==='replacement'){check(!$db->db_connect(false),'active physical replacement denied and authority revoked');revoked($db,$tx,'before close replacement');}
  if($mode==='exception'){$db->dbh->close();try{$db->close();throw new RuntimeException('Expected driver exception');}catch(Error $e){check(true,'actual driver close exception');}}
  else $db->close();
  revoked($db,$tx,$mode);
  check(!$db->db_connect(false)&&!$db->check_connection(false),'no reconnect '.$mode);
  check((new ReflectionMethod(wpdb::class,'db_connect'))->invoke($db,false),'test-only physical replacement '.$mode);
  revoked($db,$tx,'replacement '.$mode);
  check(false===$db->query("UPDATE $table SET value=99 WHERE id=1"),'no stale SQL on replacement '.$mode);
  check((new \RodolfoRizzo\Cpt2Collection\ProviderRegistry($db,new TableNames($db)))->bind('stripe_checkout','session','cs_stale',1,'2026-10-21 00:00:00',null,null,'test')!=='bound','guarded writer denies stale normal authority '.$mode);
  check((string)$global->get_var("SELECT value FROM $table")===($mode==='commit_after'?'7':'0'),'observer verifies physical outcome '.$mode);
  $db->close();
 }
 // Legacy historical evidence and maintenance also lose their distinct capabilities.
 check(false!==$global->query("INSERT INTO {$tables->commerce_resets()} (operation_identity,installation_id,source_epoch_id,source_epoch_identity,target_epoch_identity,active_slot,status,phase,started_at,updated_at) VALUES(REPEAT('c',32),REPEAT('a',32),1,REPEAT('b',32),REPEAT('d',32),1,'running','validating','2026-01-01','2026-01-01')"),'native active reset fixture');
 $expected=CommerceResetRecord::from_row($global->get_row('SELECT * FROM '.$tables->commerce_resets()));
 $global->query("UPDATE {$tables->commerce_control()} SET state='resetting'");
 foreach(['maintenance','historical'] as $mode){
  $db=private_db($global);$tx=new TransactionManager($db,new TableNames($db));
  check($mode==='maintenance'?$tx->begin_reset_maintenance($expected):$tx->begin_historical_evidence(new CommerceEvidenceContext($expected,$claim)),'native '.$mode.' admission');
  $db->close();revoked($db,$tx,$mode);check(!$db->db_connect(false),'no reconnect '.$mode);
 }
 $global->query('DELETE FROM '.$tables->commerce_resets());$global->query("UPDATE {$tables->commerce_control()} SET state='ready'");
 $db=private_db($global);$tx=new TransactionManager($db,new TableNames($db));
 check($tx->begin_commerce_shared()&&$tx->commit()&&$tx->begin_commerce_shared()&&$tx->rollback(),'genuinely new connection supports fresh normal admission and confirmed reuse');
 check($tx->begin_commerce_shared(),'healthy prior owner for actual recovery discard');
 $stage=new class implements \RodolfoRizzo\Cpt2Collection\OperationalStageInspection {
  public function inspect(\RodolfoRizzo\Cpt2Collection\CommittedOperation $op,wpdb $db,TableNames $tables):\RodolfoRizzo\Cpt2Collection\OperationalInspectionResult {
   check(TransactionManager::continuation_inspection_on($db)&&!TransactionManager::admitted_on($db),'fresh native inspection has only inspection authority');
   return new \RodolfoRizzo\Cpt2Collection\OperationalInspectionResult('verification_required','synthetic_no_business_predicate');
  }
 };
 $out=(new \RodolfoRizzo\Cpt2Collection\OperationalRecoveryInspector(fn()=>private_db($global)))->inspect($operation,$stage,$db);
 check($out->reason==='synthetic_no_business_predicate','fresh actual recovery inspection after healthy prior discard');revoked($db,$tx,'inspector prior');

 // Independent native process: physical discard must release the held control fence.
 if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required for native discard serialization');
 $global->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
 if($pid===-1)throw new RuntimeException('fork failed');
 if($pid===0){
  fclose($pair[0]);stream_set_timeout($pair[1],15);fgets($pair[1]);
  try{
   $child=new CommerceWpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$child->set_prefix($prefix);$child->suppress_errors(true);
   $child->query('SET SESSION innodb_lock_wait_timeout=10');fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);
   $child_tx=new TransactionManager($child,new TableNames($child));
   $ok=$child_tx->begin_reset_control()&&$child->get_var("SELECT value FROM $table")==='0'&&$child_tx->rollback();
   fwrite($pair[1],$ok?"drained\n":"FAIL\n");fflush($pair[1]);$child->close();exit($ok?0:2);
  }catch(Throwable $error){fwrite($pair[1],'FAIL:'.$error->getMessage()."\n");exit(2);}
 }
 fclose($pair[1]);stream_set_timeout($pair[0],15);
 $global=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$global->set_prefix($prefix);$global->suppress_errors(true);
 $db=private_db($global);$tx=new TransactionManager($db,new TableNames($db));$global->query("UPDATE $table SET value=0");
 try{
  check($tx->begin_commerce_shared()&&1===$db->query("UPDATE $table SET value=8 WHERE id=1"),'native holder owns control and uncommitted SQL');
  fwrite($pair[0],"go\n");fflush($pair[0]);$child_id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+10;$waiting=false;
  do{$waiting=(int)$global->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$child_id AND INFO LIKE 'SELECT %FROM {$tables->commerce_control()}%'")>0;if(!$waiting)usleep(20000);}while(!$waiting&&microtime(true)<$deadline);
  $read=[$pair[0]];$w=[];$e=[];check($waiting&&stream_select($read,$w,$e,0,200000)===0,'independent native control waiter blocked at least 200ms');
  $db->close();revoked($db,$tx,'independent process discard');
  check(trim(fgets($pair[0]))==='drained','fresh independent control admission drains discarded holder and sees rollback');
 }finally{$db->close();pcntl_waitpid($pid,$status);fclose($pair[0]);}
 check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent native process exits cleanly');
} finally {if(isset($db))try{$db->close();}catch(Throwable $e){}foreach(array_reverse($created) as $name)$global->query('DROP TABLE IF EXISTS '.$name);$global->close();}
