<?php
/** Explicit current-schema fixtures. Production migration remains the only schema implementation. */
declare(strict_types=1);
require_once __DIR__.'/commerce-db-test.php';
spl_autoload_register(static function(string $class):void {
 $short=strtolower(substr($class,strrpos($class,'\\')+1));foreach(glob(dirname(__DIR__,2).'/includes/class-*.php') as $file){if(str_replace('-','',substr(basename($file),6,-4))===$short){require_once $file;return;}}
});
function phase_one_schema(wpdb $db):array {
 $t=new \RodolfoRizzo\Cpt2Collection\TableNames($db);$created=[];
 $source=file_get_contents(dirname(__DIR__,2).'/includes/class-database.php');preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
 foreach($matches[1] as $definition){$definition=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$t->{$m[1]}(),$definition);sql($db,'CREATE TABLE '.$definition.' ENGINE=InnoDB');preg_match('/\A([^\s]+)/',$definition,$m);$created[]=$m[1];}
 seed_commerce_foundation($db);
 return $created;
}
function phase_two_schema(wpdb $db):array {
 $created=phase_one_schema($db);$t=new \RodolfoRizzo\Cpt2Collection\TableNames($db);
 if(!\RodolfoRizzo\Cpt2Collection\CommercePhaseTwoMigration::run($db)){throw new RuntimeException('Phase 2 fixture migration failed: '.$db->last_error);}
 return array_merge($created,[$t->provider_claims(),$t->provider_identities()]);
}

/** Synchronous routing now requires the current retained and Order storage, including reset history. */
function provider_response_schema(wpdb $db):void {
 foreach(['CommercePhaseThreeCheckpointOneMigration','CommercePhaseFourCheckpointOneMigration','CommerceEvidenceGenerationMigration','CommerceBaselinePurgeMigration','CommerceOrderSourceIndexMigration','CommerceClaimSessionIndexMigration','CommerceDeletedOrderIndexesMigration','PaymentNotificationMigration','OrderCustomerMigration'] as $name){
  $class='RodolfoRizzo\\Cpt2Collection\\'.$name;
  $t=new \RodolfoRizzo\Cpt2Collection\TableNames($db);
  $present=match($name){
   'CommercePhaseThreeCheckpointOneMigration'=>[$t->order_deletions(),null],
   'CommercePhaseFourCheckpointOneMigration'=>[$t->commerce_resets(),null],
   'CommerceEvidenceGenerationMigration'=>[$t->commerce_resets(),'evidence_generation'],
   'CommerceBaselinePurgeMigration'=>[$t->commerce_control(),'baseline_purge'],default=>null
  };
  if($present!==null){$q=$present[1]===null?$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$present[0]):$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',...$present);if((int)$db->get_var($q)===1)continue;}
  if(!$class::run($db))throw new RuntimeException('Current provider fixture schema: '.$name.' '.$db->last_error);
 }
}
