<?php
/** Storage guards, true process death, residual corruption and retained contradiction. */
declare(strict_types=1);
require __DIR__.'/support/reset-deletion.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetDeletion as D,CommerceResetRepository,TransactionManager,TableNames};
foreach(['trigger','reset_trigger','identity_trigger','foreign_key','engine','read_failure'] as $case){$db=new DeletionDb('c2c_erase_storage_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r]=deletion_setup($db,1);$snapshot=baseline_snapshot($db);
 if($case==='trigger')sql($db,'CREATE TRIGGER '.$db->prefix.'restore_stock BEFORE DELETE ON '.$t->reservations().' FOR EACH ROW UPDATE '.$db->postmeta." SET meta_value='999'");
 if($case==='reset_trigger')sql($db,'CREATE TRIGGER '.$db->prefix.'reset_stock BEFORE UPDATE ON '.$t->commerce_resets()." FOR EACH ROW UPDATE ".$db->postmeta." SET meta_value='999'");
 if($case==='identity_trigger')sql($db,'CREATE TRIGGER '.$db->prefix.'identity_stock BEFORE UPDATE ON '.$t->provider_identities()." FOR EACH ROW UPDATE ".$db->postmeta." SET meta_value='999'");
 if($case==='foreign_key')sql($db,'ALTER TABLE '.$t->cart_items().' ADD CONSTRAINT '.$db->prefix.'cart_fk FOREIGN KEY(cart_id) REFERENCES '.$t->carts().'(id) ON DELETE CASCADE');
 if($case==='engine')sql($db,'ALTER TABLE '.$t->carts().' ENGINE=MyISAM');
 if($case==='read_failure')sql($db,'RENAME TABLE '.$t->payment_events().' TO '.$db->prefix.'missing_events');
 $out=validation_service($db)->delete_operational($r);check($out->operation()===null&&in_array($out->code(),['database_error','inconsistent_state'],true),'unexpected '.$case.' denies destructive capability');
 if($case==='read_failure')sql($db,'RENAME TABLE '.$db->prefix.'missing_events TO '.$t->payment_events());
 check($snapshot===baseline_snapshot($db),'storage rejection preserves stock and entire reset/domain');
 if($case==='foreign_key')sql($db,'ALTER TABLE '.$t->cart_items().' DROP FOREIGN KEY '.$db->prefix.'cart_fk');
 }finally{baseline_cleanup($db);}}
// Structural retained contradiction after committed partial deletion preserves the frontier/seal.
$db=new DeletionDb('c2c_erase_retained_block_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r,$f]=deletion_setup($db);$r=deletion_pass($db,$r,1,'o');$front=D::position($r);$stock=deletion_stock($db);$r=validation_bind($db,$r,$f[0]['claim'],'event','evt_corrupt_retained',hash('sha256','contradiction'),'checkout.session.completed');
 sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id='.$f[0]['session'].' WHERE id='.$f[1]['claim']);$r=deletion_retained($db,$r,1);check($r->status()==='blocked'&&D::position($r)[0]===$front[0]&&D::position($r)[1]===$front[1]&&str_starts_with($r->error_code(),'retained_'),'retained contradiction blocks with committed erasure position preserved');
 foreach(['payment_events','payment_sessions','reservations','order_items'] as $method)check(!$db->get_results('SELECT id FROM '.$t->$method().' LIMIT 1'),'blocked retained pass never reconstructs '.$method);check(deletion_stock($db)===$stock,'blocked retained contradiction leaves physical stock unchanged');check(validation_service($db)->delete_operational($r)->code()==='conflict','blocked destructive resume denied');
 $cp=$r->checkpoint();$r=validation_bind($db,$r,$f[0]['claim'],'session','cs_retained_block');check($r->status()==='blocked'&&$r->checkpoint()===$cp,'blocked historical evidence does not erase frontier');
 }finally{baseline_cleanup($db);}
// Corruption in an already-completed table must be caught by final seven point probes.
foreach(D::STAGES as $stage=>$method){$db=new DeletionDb('c2c_erase_residual_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r]=deletion_setup($db,1);$saved=get_object_vars($db->get_row('SELECT * FROM '.$t->$method().' LIMIT 1'));$r=deletion_pass($db,$r,100,'x');check($db->insert($t->$method(),$saved,array_fill(0,count($saved),'%s'))===1,'test-only illicit completed-stage insertion');$r=validation_service($db)->delete_operational($r)->operation();check($r->status()==='blocked'&&$r->checkpoint()==='z1_x_0_done'&&$r->error_code()==='deletion_residual_'.$method,'bounded final probes reject residual '.$method);
 }finally{baseline_cleanup($db);}}
// Real paid conversion occurs before reset; converted reservation erasure cannot restore its sale.
$db=new DeletionDb('c2c_erase_paid_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$order=payment_fixture($db);[$payments]=payment_service($db,new ModernBoundaryProvider());check($payments->create($order,bin2hex(random_bytes(16)))->is_success(),'guarded native payment with local provider stub');$session=$db->get_row("SELECT * FROM {$t->payment_sessions()} WHERE order_id=$order");$claim=$db->get_row("SELECT * FROM {$t->provider_claims()} WHERE payment_session_id={$session->id}");$payload=modern_payload($session->reference,'evt_paid_purge',$claim->installation_id,$claim->epoch_identity);check(webhook_for($db)->handle($payload,hash('sha256',json_encode($payload)))->status()===200,'production sale conversion before reset');check($db->get_var('SELECT meta_value FROM '.$db->postmeta)==='19'&&$db->get_var('SELECT status FROM '.$t->reservations())==='converted','historical sale already deducted stock once');
 $r=deletion_retained($db,retirement_pass($db,validation_pass($db,validation_start($db))));$stock=deletion_stock($db);while(!D::certified_empty($r)){$r=validation_service($db)->delete_operational($r,1)->operation();check($r->status()==='running'&&deletion_stock($db)===$stock,'each actual paid-history erasure batch leaves sold physical stock unchanged');}
 check($db->get_var('SELECT meta_value FROM '.$db->postmeta)==='19','converted reservation and paid order erasure never restore sale stock');
 }finally{baseline_cleanup($db);}
// Operational PK larger than supported integer range blocks before any DELETE.
$db=new DeletionDb('c2c_erase_overflow_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{[$r]=deletion_setup($db,1);sql($db,'UPDATE '.$t->payment_events().' SET id=18446744073709551614');$db->writes=[];$db->observe=true;$r=validation_service($db)->delete_operational($r)->operation();$db->observe=false;check($r->status()==='blocked'&&$r->checkpoint()==='z1_e_0_done'&&!array_filter($db->writes,fn($q)=>str_starts_with($q,'DELETE ')),'unsupported unsigned PK blocks without lossy conversion or deletion');}finally{baseline_cleanup($db);}
// Actual installation-wide erasure includes coherent older modern and unresolved NULL receipts.
$db=new DeletionDb('c2c_erase_global_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$old=validation_order($db);validation_receipt($db,$old,'evt_older_domain');baseline_epoch_switch($db);$new=validation_order($db);validation_receipt($db,$new,'evt_source_domain');$r=deletion_retained($db,retirement_pass($db,validation_pass($db,validation_start($db))));$kept=deletion_kept($db);check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events().' WHERE epoch_id IS NULL')===2,'unresolved receipts belong to full erasure domain');$r=deletion_pass($db,$r,1);foreach(D::STAGES as $method)check(!$db->get_results('SELECT id FROM '.$t->$method().' LIMIT 1'),'actual global purge clears older and source '.$method);
 check(deletion_kept($db)===$kept,'older epochs and retained ownership survive full-domain erasure');$r=validation_bind($db,$r,$new['claim'],'event','evt_older_domain',hash('sha256','contradictory'),'checkout.session.completed');$identity=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_older_domain'");check((int)$identity->claim_id===$old['claim']&&$identity->binding_state==='quarantined','late conflicting historical key retains older immutable owner after purge');check(D::certified_empty(deletion_retained($db,$r)),'retained validator accepts preserved older ownership/quarantine after purge');
 }finally{baseline_cleanup($db);}
// Real process death before effects or after DELETE/progress, with no attempted COMMIT.
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['before_delete','after_delete'] as $position){$prefix='c2c_erase_death_'.bin2hex(random_bytes(5)).'_';$db=new DeletionDb($prefix);try{[$r]=deletion_setup($db,1);$snapshot=baseline_snapshot($db);$db->close();$pid=pcntl_fork();if($pid===0){$child=new DeletionDb($prefix);$tx=new TransactionManager($child,new TableNames($child));if(!$tx->begin_reset_deletion($r))exit(2);if($position==='after_delete'&&(new CommerceResetRepository($child,new TableNames($child)))->delete_operational($r,100,gmdate('Y-m-d H:i:s'))===false)exit(3);exit(0);}
 pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'independent process exits '.$position.' before attempted COMMIT');$db=new DeletionDb($prefix);$fresh=validation_service($db)->resume($r->operation_identity())->operation();check($fresh->equals($r)&&baseline_snapshot($db)===$snapshot,'process death rolls back DELETE and frontier with stock unchanged');check(D::certified_empty(deletion_pass($db,$fresh)),'fresh connection recovers from process death');
 }finally{baseline_cleanup($db);}}
echo "All operational erasure storage and death checks passed.\n";
