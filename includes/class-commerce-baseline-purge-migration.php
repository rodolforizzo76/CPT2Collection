<?php
/** Additive, restartable v16 progress storage; never performs the purge. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceBaselinePurgeMigration {
 public static function verify_structure(\wpdb $db,TableNames $t):bool {
  $db->last_error='';$columns=$db->get_results('SHOW FULL COLUMNS FROM '.$t->commerce_control());
  if($db->last_error!==''||!is_array($columns))return false;
  foreach($columns as $c)if($c->Field==='baseline_purge')return strtolower($c->Type)==='longtext'&&$c->Null==='YES'&&$c->Default===null&&$c->Collation==='ascii_bin'&&$c->Extra==='';
  return false;
 }
 public static function run(\wpdb $db):bool {
  if(!$db instanceof CommerceConnection)return false;
  $t=new TableNames($db);$tx=new TransactionManager($db,$t);
  $name=substr('c2c_phase4_cp1_'.hash('sha256',(string)$db->get_var('SELECT DATABASE()').':'.$db->prefix),0,64);
  if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$name))!=='1')return false;
  try {
   if(!CommerceFoundation::verify_structure($db,$t)||!CommerceBaselineMigration::verify_structure($db,$t)||!CommercePhaseTwoMigration::verify_structure($db,$t)||!CommercePhaseThreeCheckpointOneMigration::verify_structure($db,$t)||!CommerceEvidenceGenerationMigration::verify_structure($db,$t)||!$tx->begin())return false;
   if((new CommerceBaseline($db,$t))->read('FOR UPDATE')===null||!$tx->commit())return false;
   // No new admission fence is needed: nullable additive storage grants no authority.
   // Pending stays pending throughout DDL; completed commerce need not be interrupted.
   $db->last_error='';$columns=$db->get_col('SHOW COLUMNS FROM '.$t->commerce_control());
   if($db->last_error!==''||!is_array($columns))return false;
   if(!in_array('baseline_purge',$columns,true)&&false===$db->query('ALTER TABLE '.$t->commerce_control().' ADD baseline_purge LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL'))return false;
   if(!self::verify_structure($db,$t)||!$tx->begin())return false;
   if((new CommerceBaseline($db,$t))->read('FOR UPDATE')===null)return false;
   $row=$db->get_row('SELECT baseline_purge FROM '.$t->commerce_control().' WHERE id=1 FOR UPDATE');
   if($db->last_error!==''||!is_object($row))return false;
   if($row->baseline_purge!==null)CommerceBaselinePurgeRecord::from_json($row->baseline_purge);
   return $tx->commit();
  }catch(\Throwable $e){return false;}
  finally{if($tx->is_active()&&!$tx->is_outcome_uncertain())$tx->rollback();if(!$tx->is_outcome_uncertain())$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$name));}
 }
}
