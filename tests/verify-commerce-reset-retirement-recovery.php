<?php
/** Lost COMMIT outcomes recovered solely through a new connection and exact durable state. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommerceResetRetirement};
class RetirementLossDb extends wpdb {
 public string $fault='';
 public function query(string $q):int|false {
  if($this->fault==='commit_before'&&$q==='COMMIT'){$this->last_error='lost before COMMIT';return false;}
  if($this->fault==='commit_after'&&$q==='COMMIT'){parent::query($q);$this->last_error='lost after COMMIT';return false;}
  if($this->fault==='write'&&str_starts_with($q,'UPDATE `'.$this->prefix.'c2c_commerce_resets`')){$this->last_error='checkpoint failed';return false;}
  return parent::query($q);
 }
}
foreach(['claim','mismatch','revalidation_cursor','revalidation_done','claims_transition','receipt','identity','final'] as $position){foreach(['commit_before','commit_after'] as $fault){$prefix='c2c_b_loss_'.bin2hex(random_bytes(5)).'_';$db=new RetirementLossDb($prefix);$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);validation_receipt($db,$f,'evt_loss');$r=validation_pass($db,validation_start($db));$svc=validation_service($db);
 if(in_array($position,['mismatch','revalidation_cursor','revalidation_done'],true)){$r=$svc->retire($r,1)->operation();$r=validation_bind($db,$r,$f['claim'],'session','cs_loss_bind');if($position==='revalidation_cursor')$r=$svc->retire($r,1)->operation();if($position==='revalidation_done'){do{$r=$svc->retire($r,1)->operation();}while(CommerceResetRetirement::position($r)[2]!=='v_complete');}}
 elseif($position==='claims_transition')$r=$svc->retire($r,1)->operation();
 elseif(in_array($position,['receipt','identity','final'],true)){while($r->phase()==='retiring_claims')$r=$svc->retire($r,1)->operation();if($position!=='receipt'){$r=retirement_pass($db,$r,1,'b_i');if($position==='final'){while($r->cursor()<(int)$db->get_var('SELECT MAX(id) FROM '.$t->provider_identities()))$r=$svc->retire($r,1)->operation();}}}
 $before=retirement_preserved($db);$durable=baseline_snapshot($db);$db->fault=$fault;$out=$svc->retire($r,1);check($out->code()==='transaction_outcome_unknown'&&$out->operation()===null,$position.'/'.$fault.': no guessed result');check(!$db->transaction_connection_usable()&&$svc->resume($r->operation_identity())->code()==='transaction_outcome_unknown','uncertain connection poisoned');$db->close();$db=new RetirementLossDb($prefix);$t=new TableNames($db);$fresh=validation_service($db)->resume($r->operation_identity())->operation();
 check($fault==='commit_before'?$fresh->equals($r):$fresh->revision()===$r->revision()+1,'fresh locked recovery sees both effects and checkpoint or neither');if($fault==='commit_before')check($durable===baseline_snapshot($db),'rollback-on-close retains all evidence plus exact progress');check(retirement_pass($db,$fresh)->phase()==='deleting','recovered operation resumes idempotently');check($before===retirement_preserved($db),'uncertainty does not mutate stock, commerce or epoch');
}finally{try{$db->close();}catch(Throwable $e){}baseline_cleanup(new wpdb($prefix));}}}
// Known checkpoint failure must roll back the claim mutation in the same transaction.
$db=new RetirementLossDb('c2c_b_rollback_'.bin2hex(random_bytes(5)).'_');try{validation_schema($db);validation_order($db);$r=validation_pass($db,validation_start($db));$before=baseline_snapshot($db);$db->fault='write';check(validation_service($db)->retire($r)->code()==='database_error','known reset write failure remains retryable');$db->fault='';check($before===baseline_snapshot($db),'claim retirement and progress roll back together');}finally{baseline_cleanup($db);}
echo "All B transaction recovery checks passed.\n";
