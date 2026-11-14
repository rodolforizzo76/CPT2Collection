<?php
/** Production controller + services + MariaDB, with standalone WP request/permission stubs. */
declare(strict_types=1);
require_once __DIR__.'/reset-final-switch.php';
require_once __DIR__.'/reset-rest.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRestController,CommerceResetResult,TransactionManager,TableNames};
class ResetRestDb extends DeletionDb {
 public ?Closure $before_commit=null;
 public int $commits=0,$fault_commit=0;public bool $commit_after=false,$rollback_fault=false;public string $read_fault='';public ?Closure $inspect_probe=null;
 public function get_results(string $q):?array {
  if($this->inspect_probe!==null&&str_contains($q,'WHERE operation_identity='))($this->inspect_probe)($this);
  if($this->read_fault!==''&&str_contains($q,$this->read_fault)){$this->last_error='private injected DB failure';return null;}
  return parent::get_results($q);
 }
 public function query(string $q):int|false {
  if($q==='COMMIT'&&$this->before_commit!==null)($this->before_commit)($this);
  if($q==='COMMIT'&&++$this->commits===$this->fault_commit){if($this->commit_after)parent::query($q);$this->last_error='private commit acknowledgement lost';return false;}
  if($q==='ROLLBACK'&&$this->rollback_fault){$this->last_error='private rollback acknowledgement lost';return false;}
  return parent::query($q);
 }
}
function reset_rest_controller(wpdb $db):CommerceResetRestController {
 return new CommerceResetRestController(static function(string $action,array $p)use($db):CommerceResetResult {
  $svc=validation_service($db);
  return match($action){'start'=>$svc->start($p['operation_identity'],$p['expected_epoch_identity'],$p['requested_by']),'status'=>$svc->inspect($p['operation_identity']),'resume'=>$svc->continue_operation($p['operation_identity'],$p['expected_epoch_identity'],(int)$p['revision'])};
 });
}
function rest_fixture():array {
 $prefix='c2c_r48_'.bin2hex(random_bytes(5)).'_';$db=new ResetRestDb($prefix);validation_schema($db);$f=validation_order($db,2);validation_receipt($db,$f,'evt_private_rest');return [$db,$prefix,$f['epoch']];
}
function rest_cleanup(string $prefix):void{baseline_cleanup(new wpdb($prefix));}
