<?php
/** v14 migration: actual MariaDB DDL interruptions, exact partial schema, preserved history. */
declare(strict_types=1);
require __DIR__.'/support/reset-maintenance.php';
use RodolfoRizzo\Cpt2Collection\{CommerceEvidenceGenerationMigration as Migration,CommercePhaseFourCheckpointOneMigration,CommerceResetRecord,CommerceResetRepository,TransactionManager,TableNames,Database};
class GenerationMigrationDb extends wpdb {
 public int $lose_commit=0; private int $commits=0;
 public string $fail=''; public bool $fired=false; public bool $lose=false;
 public function query(string $query):int|false {
  $matches=$this->fail!=='' && str_contains($query,$this->fail) && !$this->fired;
  if($matches && !$this->lose){$this->fired=true;$this->last_error='injected migration failure';return false;}
  $r=parent::query($query);
  if($query==='COMMIT' && $this->lose_commit>0 && ++$this->commits===$this->lose_commit){$this->fired=true;$this->last_error='lost commit acknowledgement';return false;}
  if($matches){$this->fired=true;$this->last_error='lost migration result';return false;}return $r;
 }
}
function migration_snapshot(wpdb $db):string {
 $rows=unserialize(maintenance_snapshot($db));unset($rows['commerce_control'][0]->state);
 foreach($rows['commerce_resets'] as $r){unset($r->evidence_generation,$r->validation_generation);}
 return serialize($rows);
}
foreach(['upgrade','one_column','other_column','fence_failure','fence_loss','first_ddl_loss','second_ddl_loss','ready_failure','ready_loss','fence_commit_loss','ready_commit_loss','malformed','active','blocked','contradictory'] as $case){
 $prefix='c2c_a0mig_'.bin2hex(random_bytes(5)).'_';$db=new GenerationMigrationDb($prefix);$t=new TableNames($db);
 try{
  maintenance_schema($db,false);
  if(in_array($case,['active','blocked','contradictory'],true)){
   sql($db,"INSERT INTO {$t->commerce_resets()} (operation_identity,installation_id,source_epoch_id,source_epoch_identity,target_epoch_identity,active_slot,status,phase,started_at,updated_at) SELECT REPEAT('c',32),c.installation_id,1,e.identity,REPEAT('d',32),1,'running','validating','2026-01-01','2026-01-01' FROM {$t->commerce_control()} c JOIN {$t->commerce_epochs()} e ON e.id=1");sql($db,"UPDATE {$t->commerce_control()} SET state='resetting'");
   if($case==='blocked')sql($db,"UPDATE {$t->commerce_resets()} SET status='blocked',error_code='fixture'");
   if($case==='contradictory')sql($db,"UPDATE {$t->commerce_control()} SET state='ready'");
   $before=maintenance_snapshot($db);check(!Migration::run($db) && $before===maintenance_snapshot($db),$case.': active reset cannot be migration recovery');continue;
  }
  // Coherent completed history: numeric source 1 retired and current target 2.
  sql($db,"UPDATE {$t->commerce_epochs()} SET retired_at='2026-01-02' WHERE id=1");
  sql($db,"INSERT INTO {$t->commerce_epochs()} VALUES(2,REPEAT('9',32),'2026-01-02',NULL)");
  sql($db,"INSERT INTO {$t->commerce_resets()} (operation_identity,installation_id,source_epoch_id,source_epoch_identity,target_epoch_identity,target_epoch_id,active_slot,status,phase,started_at,updated_at,completed_at) SELECT REPEAT('8',32),c.installation_id,1,e.identity,REPEAT('9',32),2,NULL,'completed','completed','2026-01-01','2026-01-02','2026-01-02' FROM {$t->commerce_control()} c JOIN {$t->commerce_epochs()} e ON e.id=1");
  sql($db,"UPDATE {$t->commerce_control()} SET current_epoch_id=2");
  $before=migration_snapshot($db);
  if($case==='one_column')sql($db,"ALTER TABLE {$t->commerce_resets()} ADD evidence_generation BIGINT UNSIGNED NOT NULL DEFAULT 0");
  if($case==='other_column')sql($db,"ALTER TABLE {$t->commerce_resets()} ADD validation_generation BIGINT UNSIGNED NULL DEFAULT NULL");
  if($case==='malformed'){
   sql($db,"ALTER TABLE {$t->commerce_resets()} ADD evidence_generation BIGINT NOT NULL DEFAULT 0");
   check(!Migration::run($db) && $before===migration_snapshot($db),'malformed partial column fails closed');continue;
  }
  $db->fail=match($case){'fence_failure','fence_loss'=>"SET state='migrating_v14'",'first_ddl_loss'=>'ADD evidence_generation','second_ddl_loss'=>'ADD validation_generation','ready_failure','ready_loss'=>"SET state='ready'",default=>''};
  $db->lose=str_ends_with($case,'loss');
  $db->lose_commit=match($case){'fence_commit_loss'=>1,'ready_commit_loss'=>2,default=>0};
  $success=Migration::run($db);check($success===($db->fail==='' && $db->lose_commit===0),$case.': expected migration outcome');
  if($db->lose_commit){check(!$db->transaction_connection_usable(),'lost migration commit revokes connection');}
  $db->close();$db=new GenerationMigrationDb($prefix);$t=new TableNames($db);
  check(Migration::run($db) && Migration::verify_structure($db,$t),$case.': fresh retry reaches exact v14');
  $record=CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets()));
  check($record->evidence_generation()===0 && $record->validation_generation()===null,$case.': existing history zero/NULL baseline');
  $tx=new TransactionManager($db,$t);check(!$tx->begin_reset_control() && CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets()))->status()==='completed',$case.': old completed history parses but unattested runtime remains denied');$tx->rollback();
  check($before===migration_snapshot($db),$case.': operational/provider/deletion/epoch/stock/history preserved');
  sql($db,"UPDATE {$t->commerce_resets()} SET evidence_generation=17,validation_generation=11");
  check(Migration::run($db) && '17'===(string)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets()) && '11'===(string)$db->get_var('SELECT validation_generation FROM '.$t->commerce_resets()),$case.': retry never resets generations');
  check(!CommercePhaseFourCheckpointOneMigration::verify_structure($db,$t),'historical v13 verifier remains exact');
 }finally{maintenance_cleanup($db);}
}
// Exercise the actual version acknowledgement route, including a lost option write.
$options=[];$lose_ack=false;
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['options'][$key]??$default;}
function update_option(string $key,mixed $value,bool $autoload=true):bool{if($GLOBALS['lose_ack'])return false;$GLOBALS['options'][$key]=$value;return true;}
foreach(['partial_failure','ack_loss','malformed'] as $case){
 $prefix='c2c_a0ack_'.bin2hex(random_bytes(5)).'_';$wpdb=new GenerationMigrationDb($prefix);$t=new TableNames($wpdb);
 try{
  maintenance_schema($wpdb,false);$options=[Database::OPTION_SCHEMA_VERSION=>'0.13.0'];$lose_ack=$case==='ack_loss';
  if($case==='malformed')sql($wpdb,"ALTER TABLE {$t->commerce_resets()} ADD validation_generation BIGINT UNSIGNED NOT NULL DEFAULT 0");
  if($case==='partial_failure')$wpdb->fail='ADD validation_generation';
  Database::maybe_upgrade();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.13.0',$case.': incomplete/unknown upgrade never acknowledged');
  if($case==='malformed'){check(!Migration::verify_structure($wpdb,$t),'wrong nullability/default rejected');continue;}
  $wpdb->close();$wpdb=new GenerationMigrationDb($prefix);$lose_ack=false;Database::maybe_upgrade();
  check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.21.0' && Migration::verify_structure($wpdb,$t),$case.': fresh retry acknowledges only exact complete schema');
 }finally{maintenance_cleanup($wpdb);}
}
echo "All evidence-generation migration checks passed.\n";
