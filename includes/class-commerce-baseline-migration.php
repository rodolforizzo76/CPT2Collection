<?php
/**
 * Restartable v15 migration; no commerce deletion or runtime completion API.
 * The migration fence commits before DDL. The canonical JSON attestation and
 * ready/pending state commit together afterwards. Recovery never rewrites a
 * valid attestation, and a missing one in ready v15 storage is not repaired.
 */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceBaselineMigration {
 public static function verify_structure(\wpdb $db,TableNames $t):bool {
  $db->last_error='';$cols=$db->get_results('SHOW FULL COLUMNS FROM '.$t->commerce_control());
  if($db->last_error!==''||!is_array($cols))return false;
  foreach($cols as $c)if($c->Field==='baseline_contract')return strtolower($c->Type)==='longtext'&&$c->Null==='YES'&&$c->Default===null&&$c->Collation==='ascii_bin'&&$c->Extra==='';
  return false;
 }
 public static function run(\wpdb $db):bool {
  if(!$db instanceof CommerceConnection)return false;
  $t=new TableNames($db);$tx=new TransactionManager($db,$t);
  $name=substr('c2c_phase4_cp1_'.hash('sha256',(string)$db->get_var('SELECT DATABASE()').':'.$db->prefix),0,64);
  if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$name))!=='1')return false;
  try {
   if(!CommerceFoundation::verify_structure($db,$t)||!CommerceEvidenceGenerationMigration::verify_structure($db,$t)||!CommercePhaseTwoMigration::verify_structure($db,$t)||!CommercePhaseThreeCheckpointOneMigration::verify_structure($db,$t)||!$tx->begin())return false;
   $db->last_error='';$rows=$db->get_results('SELECT * FROM '.$t->commerce_control().' ORDER BY id LIMIT 2 FOR UPDATE');
   if($db->last_error!==''||!is_array($rows)||count($rows)!==1||(string)$rows[0]->id!=='1')return false;
   $c=$rows[0];
   if(isset($c->baseline_contract)){
	if(!self::verify_structure($db,$t)||(new CommerceBaseline($db,$t))->read('FOR UPDATE')===null)return false;
	return $tx->commit();
   }
   // A NULL attestation in a ready v15-shaped table is corruption, not a second baseline.
   if(property_exists($c,'baseline_contract')&&$c->baseline_contract===null&&$c->state!=='migrating_v15')return false;
   if(!in_array($c->state,['ready','migrating_v15'],true)||!CommerceBaseline::identity($c->installation_id))return false;
   $db->last_error='';$epoch=$db->get_row($db->prepare('SELECT * FROM '.$t->commerce_epochs().' WHERE id=%d FOR UPDATE',$c->current_epoch_id));
   if($db->last_error!==''||!is_object($epoch)||$epoch->retired_at!==null||!CommerceBaseline::identity($epoch->identity))return false;
   $db->last_error='';$active=$db->get_results('SELECT id FROM '.$t->commerce_resets()." WHERE active_slot IS NOT NULL OR status<>'completed' OR phase<>'completed' OR completed_at IS NULL OR target_epoch_id IS NULL OR error_code IS NOT NULL LIMIT 1 FOR UPDATE");
   if($db->last_error!==''||!is_array($active)||$active)return false;
   if($db->query("UPDATE {$t->commerce_control()} SET state='migrating_v15' WHERE id=1")===false||!$tx->commit())return false;
   $db->last_error='';$columns=$db->get_col('SHOW COLUMNS FROM '.$t->commerce_control());
   if($db->last_error!==''||!is_array($columns))return false;
   if(!in_array('baseline_contract',$columns,true)&&$db->query('ALTER TABLE '.$t->commerce_control().' ADD baseline_contract LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL')===false)return false;
   if(!self::verify_structure($db,$t)||!$tx->begin())return false;
   $db->last_error='';$again=$db->get_row('SELECT * FROM '.$t->commerce_control().' WHERE id=1 FOR UPDATE');
   if($db->last_error!==''||!is_object($again)||$again->state!=='migrating_v15'||$again->installation_id!==$c->installation_id||(string)$again->current_epoch_id!==(string)$c->current_epoch_id||$again->baseline_contract!==null)return false;
   // Prove pristine state under the fence. No row is removed or repaired.
   $empty=true;
   foreach(['carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events','provider_claims','provider_identities','order_deletions','commerce_resets'] as $method){
	$db->last_error='';$one=$db->get_results('SELECT * FROM '.$t->$method().' LIMIT 1');
	if($db->last_error!==''||!is_array($one))return false;if($one)$empty=false;
   }
   $db->last_error='';$epochs=$db->get_results('SELECT * FROM '.$t->commerce_epochs().' ORDER BY id FOR UPDATE');
   if($db->last_error!==''||!is_array($epochs)||!$epochs)return false;
   $max=0;foreach($epochs as $e){$id=filter_var($e->id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false||!CommerceBaseline::identity($e->identity))return false;$max=max($max,$id);}
   $fresh=$empty&&count($epochs)===1&&(int)$epoch->id===1&&$epochs[0]->identity===$epoch->identity;
   if(!$fresh&&$max===PHP_INT_MAX)return false;
   $b=['version'=>1,'installation_id'=>$c->installation_id,'identity'=>bin2hex(random_bytes(16)),'first_epoch_id'=>$fresh?1:$max+1,'first_epoch_identity'=>$fresh?$epoch->identity:bin2hex(random_bytes(16)),'status'=>$fresh?'completed':'prepared'];
   foreach($epochs as $e)if(!$fresh&&$e->identity===$b['first_epoch_identity'])return false;
   $db->last_error='';$reserved=$db->get_results($db->prepare('SELECT id FROM '.$t->commerce_resets().' WHERE target_epoch_identity=%s',$b['first_epoch_identity']));
   if($db->last_error!==''||!is_array($reserved)||$reserved)return false;
   if($db->update($t->commerce_control(),['state'=>$fresh?'ready':'baseline_pending','baseline_contract'=>json_encode($b,JSON_UNESCAPED_SLASHES)],['id'=>1],['%s','%s'],['%d'])===false)return false;
   if((new CommerceBaseline($db,$t))->read('FOR UPDATE')===null)return false;
   return $tx->commit();
  }catch(\Throwable $e){return false;}
  finally{if($tx->is_active()&&!$tx->is_outcome_uncertain())$tx->rollback();if(!$tx->is_outcome_uncertain())$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$name));}
 }
}
