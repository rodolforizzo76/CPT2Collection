<?php
/** Representative A1 boundaries: never infer a transaction outcome from an acknowledgement. */
declare(strict_types=1);
require __DIR__.'/support/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
class ValidationFailureDb extends wpdb {
 public string $fault='';
 public function get_results(string $query):?array {if(in_array($this->fault,['read','rollback'],true)&&str_contains($query,'FORCE INDEX(PRIMARY)')){$this->last_error='injected read failure';return null;}return parent::get_results($query);}
 public function query(string $query):int|false {
  if(($this->fault==='begin'&&$query==='START TRANSACTION')||($this->fault==='rollback'&&$query==='ROLLBACK')||($this->fault==='commit_before'&&$query==='COMMIT')||($this->fault==='write'&&str_starts_with($query,'UPDATE `'.$this->prefix.'c2c_commerce_resets`'))){$this->last_error='injected boundary failure';return false;}
  if($this->fault==='commit_after'&&$query==='COMMIT'){parent::query($query);$this->last_error='lost COMMIT acknowledgement';return false;}return parent::query($query);
 }
}
foreach(['capture','cursor','stage','restart','final','block'] as $position){foreach(['commit_before','commit_after'] as $fault){
 $prefix='c2c_a1_recovery_'.bin2hex(random_bytes(5)).'_';$db=new ValidationFailureDb($prefix);$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);if($position==='block')sql($db,"UPDATE {$t->orders()} SET checkout_attempt_hash=NULL WHERE id={$f['order']}");$r=validation_start($db);
  if(in_array($position,['cursor','stage','restart'],true)){$r=validation_service($db)->validate($r,1)->operation();if($position==='stage')$r=validation_service($db)->validate($r,1)->operation();if($position==='restart')$r=validation_bind($db,$r,$f['claim'],'session','cs_restart_loss');}
  elseif($position==='final')$r=validation_pass($db,$r,1,'v_complete');elseif($position==='block')$r=validation_pass($db,$r,1,'v_orders');
  $before=validation_preserved($db);$db->fault=$fault;$svc=validation_service($db);$result=$svc->validate($r,1);check($result->code()==='transaction_outcome_unknown'&&$result->operation()===null,$position.'/'.$fault.': no guessed progress or block');check(!$db->transaction_connection_usable()&&$svc->resume($r->operation_identity())->code()==='transaction_outcome_unknown','uncertain connection is poisoned and cannot recover itself');$db->close();
  $db=new ValidationFailureDb($prefix);$t=new TableNames($db);$fresh=validation_service($db)->resume($r->operation_identity())->operation();
  if($fault==='commit_before')check($fresh->equals($r),'fresh locked inspection proves no progress after close/rollback');
  else{check($fresh->revision()===$r->revision()+1,$position.': fresh locked inspection recovers exactly one committed revision');$expect=match($position){'capture','restart'=>'v_carts','cursor'=>'v_carts','stage'=>'v_cart_items','final'=>null,'block'=>'v_orders'};check($fresh->checkpoint()===$expect,$position.': exact committed stage recovered');if($position==='restart')check($fresh->cursor()===0&&$fresh->validation_generation()===$fresh->evidence_generation(),'lost restart acknowledgement retains captured live generation');if($position==='cursor')check($fresh->cursor()>0,'lost cursor acknowledgement retains physical progress');if($position==='final')check($fresh->phase()==='retiring_claims','lost final acknowledgement recovers certified transition without rerunning effects');if($position==='block')check($fresh->status()==='blocked'&&$fresh->error_code()==='validation_order_snapshot','lost block acknowledgement recovers durable contradiction');}
  $final=$fresh->phase()==='validating'&&$fresh->status()==='running'?validation_pass($db,$fresh):$fresh;check($position==='block'?$final->status()==='blocked':$final->phase()==='retiring_claims','fresh recovery terminates with the correct semantic decision');check($before===validation_preserved($db),'uncertainty/recovery preserves stock, provider, operational and epoch rows');
 }finally{try{$db->close();}catch(Throwable $ignored){}baseline_cleanup(new wpdb($prefix));}
}}
foreach(['begin','read','write','rollback'] as $fault){$prefix='c2c_a1_error_'.bin2hex(random_bytes(5)).'_';$db=new ValidationFailureDb($prefix);$t=new TableNames($db);try{
 validation_schema($db);validation_order($db);$r=validation_start($db);$r=validation_service($db)->validate($r,1)->operation();$before=validation_preserved($db);$db->fault=$fault;$result=validation_service($db)->validate($r,1);check($result->code()===(in_array($fault,['begin','rollback'],true)?'transaction_outcome_unknown':'database_error'),$fault.': database failure is not an integrity block');$db->close();$db=new ValidationFailureDb($prefix);$fresh=validation_service($db)->resume($r->operation_identity())->operation();check($fresh->equals($r),'fresh recovery retains exact prior snapshot');check(validation_pass($db,$fresh)->phase()==='retiring_claims'&&$before===validation_preserved($db),'confirmed retry completes with full preservation');
 }finally{try{$db->close();}catch(Throwable $ignored){}baseline_cleanup(new wpdb($prefix));}}
echo "All A1 transaction recovery checks passed.\n";
