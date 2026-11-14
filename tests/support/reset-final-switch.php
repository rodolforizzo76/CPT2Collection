<?php
/** Production finalization fixture and statement-boundary fault adapter. */
declare(strict_types=1);
require_once __DIR__.'/reset-deletion.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommerceResetRecord};
class FinalSwitchDb extends DeletionDb {
 public string $failure=''; public string $read_failure=''; public bool $after_statement=false;
 public function get_results(string $q):?array {
  if($this->read_failure!==''&&str_contains($q,$this->read_failure)){$this->last_error='injected final read failure';return null;}
  return parent::get_results($q);
 }
 public function query(string $q):int|false {
  if($this->failure!==''&&str_contains($q,$this->failure)){
   if($this->after_statement)parent::query($q);
   $this->last_error='injected final statement failure';return false;
  }
  return parent::query($q);
 }
}
function final_setup(FinalSwitchDb $db,int $orders=1):array {
 [$r,$f]=deletion_setup($db,$orders);
 $t=new TableNames($db);$seeds=[];
 foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)$seeds[$m]=$db->get_row('SELECT * FROM '.$t->$m().' LIMIT 1');
 return [deletion_pass($db,$r,100),$f,$seeds];
}
function final_preserved(wpdb $db):string {
 $a=unserialize(baseline_snapshot($db));unset($a['commerce_resets'],$a['commerce_control'],$a['commerce_epochs']);
 $t=new TableNames($db);$c=$db->get_row('SELECT * FROM '.$t->commerce_control());
 $a['baseline_contract']=$c->baseline_contract;$a['baseline_purge']=$c->baseline_purge;
 $a['configuration']=$GLOBALS['baseline_options']??[];
 $a['products']=$db->get_results('SELECT * FROM '.$db->posts.' ORDER BY ID');
 return serialize($a);
}
function final_row(wpdb $db,array $changes):CommerceResetRecord {
 $t=new TableNames($db);$row=$db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE active_slot=1');
 return CommerceResetRecord::from_row((object)array_merge(get_object_vars($row),$changes));
}
