<?php
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration as Migration,TableNames,TransactionManager,Database};
class BaselineFaultDb extends wpdb {
 public string $fault=''; public int $commit_number=0;
 public function query(string $sql):int|false {
  if($sql==='START TRANSACTION'&&$this->fault==='begin_loss'){parent::query($sql);$this->last_error='Injected BEGIN acknowledgement loss';return false;}
  if($sql==='ROLLBACK'&&$this->fault==='rollback_loss'){parent::query($sql);$this->last_error='Injected ROLLBACK acknowledgement loss';return false;}
  if($sql==='COMMIT'){
   ++$this->commit_number;
   if($this->fault==='commit_'.$this->commit_number){parent::query($sql);$this->last_error='Injected lost acknowledgement';return false;}
   if($this->fault==='rollback_commit_'.$this->commit_number){$this->last_error='Injected uncertain commit before server execution';return false;}
  }
  if(str_starts_with($sql,'ALTER TABLE')&&str_contains($sql,'baseline_contract')&&in_array($this->fault,['ddl_before','ddl_after'],true)){
   if($this->fault==='ddl_after')parent::query($sql);$this->last_error='Injected DDL interruption';return false;
  }
  if(str_starts_with($sql,'UPDATE')&&str_contains($sql,'baseline_contract')&&in_array($this->fault,['write','rollback_loss'],true)){$this->last_error='Injected baseline write failure';return false;}
  return parent::query($sql);
 }
}
foreach(['none','begin_loss','rollback_loss','ddl_before','ddl_after','write','commit_1','commit_2','rollback_commit_1','rollback_commit_2','malformed','active_reset','ack_loss'] as $case){
 $prefix='c2c_basemig_'.bin2hex(random_bytes(5)).'_';$db=new BaselineFaultDb($prefix);$t=new TableNames($db);
 try {
  maintenance_schema($db);
  $snap=unserialize(baseline_snapshot($db,true));unset($snap['commerce_control']);
  if($case==='active_reset'){maintenance_fixture($db);sql($db,"UPDATE {$t->commerce_control()} SET state='resetting'");$before=baseline_snapshot($db,true);check(!Migration::run($db)&&baseline_snapshot($db,true)===$before,'active reset prevents baseline preparation without overwriting lifecycle');continue;}
  if($case==='malformed'){sql($db,'ALTER TABLE '.$t->commerce_control().' ADD baseline_contract VARCHAR(64) NULL');check(!Migration::run($db),'wrong durable structure rejected');continue;}
  $db->commit_number=0;$db->fault=$case;
  if($case==='ack_loss'){$GLOBALS['baseline_options']=[Database::OPTION_SCHEMA_VERSION=>'0.14.0'];$GLOBALS['baseline_lose_ack']=true;$GLOBALS['wpdb']=$db;Database::maybe_upgrade();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.14.0','lost option acknowledgement does not invent completion');}
  else check(Migration::run($db)===($case==='none'),$case.': expected first outcome');
  if(str_contains($case,'commit_')||in_array($case,['begin_loss','rollback_loss'],true))check(!$db->transaction_connection_usable(),$case.': uncertain connection poisoned');
  $durable=$db->transaction_connection_usable()?$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()):null;
  $db->close();$db=new BaselineFaultDb($prefix);$t=new TableNames($db);
  $db->last_error='';$cols=$db->get_col('SHOW COLUMNS FROM '.$t->commerce_control());
  $committed=in_array('baseline_contract',$cols,true)?$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()):null;
  check(Migration::run($db),$case.': fresh recovery/retry succeeds');
  if($committed!==null)check($committed===$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()),'fresh inspection preserves committed identity after uncertain acknowledgement');
  if($case==='ack_loss'){$GLOBALS['baseline_lose_ack']=false;$GLOBALS['wpdb']=$db;Database::maybe_upgrade();check(get_option(Database::OPTION_SCHEMA_VERSION)==='0.21.0','fresh retry acknowledges current schema');}
  $after=unserialize(baseline_snapshot($db,true));unset($after['commerce_control']);check(serialize($after)===serialize($snap),$case.': recovery preserves entire old domain and stock');
  if($durable!==null)check($durable===$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()),'retry preserves exact committed baseline identity');
  $before=baseline_snapshot($db,true);check(Migration::run($db)&&baseline_snapshot($db,true)===$before,'second baseline not allocated');
 }finally{baseline_cleanup($db);}
}
