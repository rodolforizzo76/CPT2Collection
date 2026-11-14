<?php
declare(strict_types=1);
require __DIR__.'/support/baseline-purge.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration as Migration,TableNames,Database};
class PurgeMigrationFaultDb extends wpdb{
 public string $fault='';public int $commits=0;
 public function query(string $q):int|false{
  if(str_starts_with($q,'ALTER TABLE')&&str_contains($q,'baseline_purge')&&in_array($this->fault,['ddl_before','ddl_after'],true)){if($this->fault==='ddl_after')parent::query($q);$this->last_error='DDL interrupted';return false;}
  if($q==='START TRANSACTION'&&$this->fault==='begin'){parent::query($q);$this->last_error='BEGIN ack lost';return false;}
  if($q==='COMMIT'){++$this->commits;if($this->fault==='commit_'.$this->commits){parent::query($q);$this->last_error='COMMIT ack lost';return false;}}
  return parent::query($q);
 }
}
foreach(['none','ddl_before','ddl_after','begin','commit_1','commit_2','malformed','ack_loss'] as $case){
 $prefix='c2c_purgemig_'.bin2hex(random_bytes(5)).'_';$db=new PurgeMigrationFaultDb($prefix);$t=new TableNames($db);
 try{
  maintenance_schema($db);check(CommerceBaselineMigration::run($db),'populated fixture prepares v15 boundary');$b=purge_baseline($db);$snapshot=unserialize(baseline_snapshot($db,true));$db->commits=0;
  if($case==='malformed'){sql($db,'ALTER TABLE '.$t->commerce_control().' ADD baseline_purge VARCHAR(64) NULL');check(!Migration::run($db),'malformed progress structure rejected');continue;}
  $db->fault=$case;
  if($case==='ack_loss'){$GLOBALS['wpdb']=$db;$GLOBALS['baseline_options']=[Database::OPTION_SCHEMA_VERSION=>'0.15.0'];$GLOBALS['baseline_lose_ack']=true;Database::maybe_upgrade();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.15.0','failed acknowledgement does not claim v16');$GLOBALS['baseline_lose_ack']=false;}
  else check(Migration::run($db)===($case==='none'),$case.': interrupted migration returns failure');
  if(in_array($case,['begin','commit_1','commit_2'],true))check(!$db->transaction_connection_usable(),'uncertain migration connection poisoned');
  $db->close();$db=new PurgeMigrationFaultDb($prefix);$t=new TableNames($db);
  if($case==='ack_loss'){$GLOBALS['wpdb']=$db;Database::maybe_upgrade();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.21.0','fresh Database upgrade acknowledges verified structure');}
  else check(Migration::run($db),'fresh connection migration recovers additive DDL');
  $after=unserialize(baseline_snapshot($db,true));unset($after['commerce_control'][0]->baseline_purge);check(serialize($after)===serialize($snapshot)&&purge_baseline($db)===$b,'upgrade/recovery preserves attestation source history commerce evidence and stock');
  $before=baseline_snapshot($db,true);check(Migration::run($db)&&baseline_snapshot($db,true)===$before,'migration rerun cannot delete or complete pending baseline');
 }finally{baseline_cleanup($db);}
}
