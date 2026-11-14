<?php
/** Production strengthened seal, retained-only certification, and fresh-connection recovery. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceResetRepository,ProviderRegistry};
class SourceSealDb extends wpdb {
 public string $fault=''; public bool $observe=false; public array $reads=[],$writes=[];
 public function query(string $q):int|false {
  if($this->observe && preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|TRUNCATE)\b/i',$q))$this->writes[]=$q;
  if($q==='COMMIT'&&$this->fault==='before'){$this->last_error='lost before commit';return false;}
  if($q==='COMMIT'&&$this->fault==='after'){parent::query($q);$this->last_error='lost acknowledgment';return false;}
  if($this->fault==='write'&&str_starts_with($q,'UPDATE `'.$this->prefix.'c2c_commerce_resets`')){$this->last_error='progress failure';return false;}
  return parent::query($q);
 }
 public function get_results(string $q):?array {if($this->observe)$this->reads[]=$q;return parent::get_results($q);}
 public function get_row(string $q):?object {if($this->observe)$this->reads[]=$q;return parent::get_row($q);}
}
function source_retained(wpdb $db,object $r,int $size=1,?string $stop=null):object {
 for($i=0;$i<20000;++$i){if($r->status()==='blocked'||($r->checkpoint()==='z1_e_0_done'&&$r->validation_generation()===$r->evidence_generation())||$r->checkpoint()===$stop)return $r;
 $out=validation_service($db)->certify_retained($r,$size);if(!in_array($out->code(),['progressed','blocked'],true))throw new RuntimeException('retained '.$out->code().' at '.$r->checkpoint());$r=$out->operation();}throw new RuntimeException('retained convergence');
}
/** Test-only absence model; production has no erasure API. Never forge seal progress. */
function source_absence(wpdb $db,object $r):void {
 $t=new TableNames($db);$tx=new TransactionManager($db,$t);check($tx->begin_reset_maintenance($r),'test-only absence owns sealed maintenance');
 foreach(['payment_events','payment_sessions','reservations','order_items','orders','cart_items','carts'] as $table)sql($db,'DELETE FROM '.$t->$table());
 check($tx->commit(),'test-only operational absence committed without seal change');
}
foreach(['source','older','legacy','enrichment','ownership','hash','environment','ambiguous','invalid','behind','near_final','repeated','blocked'] as $case){foreach([1,100] as $size){
 $db=new SourceSealDb('c2c_source_seal_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$old=null;
 if(in_array($case,['older','legacy'],true)){$old=validation_order($db);$receipt=validation_receipt($db,$old,'evt_older');baseline_epoch_switch($db);
  if($case==='legacy'){$contract=json_decode($db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()),true);$epoch=$db->get_row('SELECT * FROM '.$t->commerce_epochs().' ORDER BY id DESC LIMIT 1');$contract['first_epoch_id']=(int)$epoch->id;$contract['first_epoch_identity']=$epoch->identity;foreach(['reservations','order_items','payment_sessions','orders','cart_items','carts'] as $domain)sql($db,'DELETE FROM '.$t->$domain());sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET baseline_contract=%s',json_encode($contract)));}
 }
 $f=validation_order($db);$g=validation_order($db);$event=validation_receipt($db,$f,'evt_source');
 $tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'fixture sets immutable Session provider associations under commerce');sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='cs_session',provider_payment_id='pi_session',provider_event_id='evt_session' WHERE id={$f['session']}");foreach(['session'=>'cs_session','payment'=>'pi_session','event'=>'evt_session'] as $kind=>$value)check((new ProviderRegistry($db,$t))->bind('stripe_checkout',$kind,$value,$f['claim'],gmdate('Y-m-d H:i:s'),null,null,'test')==='bound','guarded fixture binds Session identity');check($tx->commit(),'provider associations fixture committed');
 $r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'s1_s');$preserved=retirement_preserved($db);$beforeGen=$r->evidence_generation();
 if($case==='enrichment')sql($db,"UPDATE {$t->provider_identities()} SET environment=NULL WHERE identity_value='cs_session'");

 if(in_array($case,['ownership','hash','environment'],true)){
  $owner=$case==='ownership'?$g['claim']:$f['claim'];$env=$case==='environment'?'live':'test';
  sql($db,"UPDATE {$t->provider_identities()} SET claim_id=$owner,environment='$env' WHERE identity_value='cs_session'");
  if($case==='hash')sql($db,"UPDATE {$t->provider_identities()} SET payload_sha256=REPEAT('a',64) WHERE identity_value='evt_source'");
 }
 if($case==='ambiguous')sql($db,"UPDATE {$t->provider_claims()} SET payment_session_id={$f['session']} WHERE id={$g['claim']}");
 if($case==='invalid')sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='bad value' WHERE id={$f['session']}");
 $r=retirement_pass($db,$r,$size);check($preserved===retirement_preserved($db) || in_array($case,['ambiguous','invalid'],true),'coverage does not mutate operational history or stock');
 if(in_array($case,['ambiguous','invalid','environment','ownership'],true)){
  // Quarantine is retained; independently contradictory immutable ownership facts still block.
  check($r->status()==='blocked','contradiction blocks without seal');check($r->phase()==='terminalizing_events','blocked pre-seal remains unsealed');continue;
 }
 check($r->phase()==='deleting'&&$r->checkpoint()==='z1_e_0_c'&&$r->validation_generation()===$r->evidence_generation(),'production seal certifies coverage, whole A1 and terminal catch-up');
 check(in_array($case,['enrichment','older','hash'],true)?$r->evidence_generation()>$beforeGen:$r->evidence_generation()===$beforeGen,'only materialization/enrichment/quarantine changes generation and converges');
 $i=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='cs_session'");check($i!==null&&$i->environment==='test'&&($case!=='ownership'||$i->binding_state==='quarantined'&&(int)$i->claim_id===$g['claim']),'coherent enrichment or conflict preserves original immutable ownership');
 if($old){$i=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_older'");check($case==='legacy'?$i===null:$i!==null&&(int)$i->claim_id===$old['claim'],'older modern covered, pre-baseline excluded');}
 check(validation_service($db)->checkpoint($r,'fake',0)->code()==='invalid_request'&&validation_service($db)->advance($r,'finalizing')->code()==='invalid_request','metadata cannot erase seal or authorize later checkpoint');
 source_absence($db,$r);$preserved=retirement_preserved($db);$claims=serialize($db->get_results('SELECT * FROM '.$t->provider_claims().' ORDER BY id'));
 $db->observe=true;$r=source_retained($db,$r,$size);$db->observe=false;check($r->checkpoint()==='z1_e_0_done','retained certification succeeds with operational parents absent');
 foreach(['orders','order_items','reservations','payment_sessions','payment_events','carts','cart_items'] as $table)check(!array_filter($db->reads,fn($q)=>str_contains($q,$t->$table())),'every retained read avoids erased '.$table);
 $cert=$r;$r=validation_bind($db,$r,$f['claim'],'event','evt_late',hash('sha256','late'),'checkout.session.completed');
 check($r->validation_generation()===$cert->validation_generation()&&$r->evidence_generation()===$cert->evidence_generation()+1,'late evidence invalidates retained generation only');
 check(validation_service($db)->certify_retained($cert,1)->code()==='stale','old retained worker cannot certify');
 $r=validation_service($db)->certify_retained($r,1)->operation();check($r->checkpoint()==='z1_e_0_c'&&$r->cursor()===0&&$r->phase()==='deleting','generation restart preserves seal and never whole A1');
 if($case==='behind'){$r=source_retained($db,$r,1,'z1_e_0_i');$r=validation_service($db)->certify_retained($r,1)->operation();check((int)$db->get_var("SELECT id FROM {$t->provider_identities()} WHERE identity_value='cs_session'")<=$r->cursor(),'mutation is behind the retained cursor');$r=validation_bind($db,$r,$g['claim'],'session','cs_session');}
 if($case==='near_final'){$r=source_retained($db,$r,1,'z1_e_0_i');$last=(int)$db->get_var('SELECT MAX(id) FROM '.$t->provider_identities());while($r->cursor()<$last)$r=validation_service($db)->certify_retained($r,1)->operation();check($r->checkpoint()==='z1_e_0_i'&&$r->cursor()===$last,'mutation occurs immediately before final retained certification');$r=validation_bind($db,$r,$f['claim'],'event','evt_near_final',hash('sha256','near'),'checkout.session.completed');}
 if($case==='repeated')for($turn=0;$turn<3;++$turn){$r=source_retained($db,$r,1,'z1_e_0_i');$r=validation_bind($db,$r,$f['claim'],'event','evt_repeat_'.$turn,hash('sha256','repeat'.$turn),'checkout.session.completed');$r=validation_service($db)->certify_retained($r,1)->operation();check($r->checkpoint()==='z1_e_0_c'&&$r->phase()==='deleting','repeated generation restarts retained path only');}
 if($case==='blocked'){$r=validation_service($db)->block($r,'diagnostic_block')->operation();$cp=$r->checkpoint();$r=validation_bind($db,$r,$f['claim'],'session','cs_blocked');check($r->status()==='blocked'&&$r->checkpoint()===$cp&&validation_service($db)->certify_retained($r)->code()==='conflict','blocked evidence remains admissible without unblocking');continue;}
 $r=source_retained($db,$r,$size);check($r->checkpoint()==='z1_e_0_done'&&$r->validation_generation()===$r->evidence_generation(),'behind/near-final changes converge to exact retained certificate');
 check($db->get_var("SELECT disposition FROM {$t->provider_identities()} WHERE identity_value='evt_late'")==='neutralized_retired','new pending Event terminalized');
 $dup=validation_bind($db,$r,$f['claim'],'event','evt_late',hash('sha256','late'),'checkout.session.completed');check($dup->equals($r),'coherent duplicate is generation-neutral');
 check($claims===serialize($db->get_results('SELECT * FROM '.$t->provider_claims().' ORDER BY id'))&&$preserved===retirement_preserved($db),'post-seal evidence and terminal catch-up preserve claims, commerce and stock');
 check($db->get_var('SELECT retired_at FROM '.$t->commerce_epochs().' WHERE id='.$r->source_epoch_id())===null&&$r->target_epoch_id()===null,'seal does not retire source or allocate target');
 }finally{baseline_cleanup($db);}
}}
// Only the semantic pipeline can cross the source boundary; no metadata shortcut.
$db=new SourceSealDb('c2c_source_gates_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);validation_order($db);$r=validation_start($db);check(validation_service($db)->advance($r,'retiring_claims')->code()==='invalid_request','incomplete initial A1 cannot authorize seal');$r=validation_pass($db,$r);
 foreach(['s1_s','s1_e','s1_v_v_carts','s1_c','s1_i'] as $cp){$r=retirement_pass($db,$r,1,$cp);$snapshot=baseline_snapshot($db);check($r->checkpoint()===$cp&&validation_service($db)->advance($r,'deleting')->code()==='invalid_request'&&validation_service($db)->checkpoint($r,'s1_done',0)->code()==='invalid_request','incomplete '.$cp.' cannot bypass semantic gate');check($snapshot===baseline_snapshot($db),'rejected seal shortcut preserves exact durable position');}
 $r=retirement_pass($db,$r);sql($db,'UPDATE '.$t->commerce_resets().' SET checkpoint=NULL WHERE id='.$r->id());$legacy=validation_service($db)->resume($r->operation_identity())->operation();check(validation_service($db)->certify_retained($legacy)->code()==='invalid_request','legacy deleting/null is not promoted to a strengthened seal');
}finally{baseline_cleanup($db);}
// Genuine Phase 3 source and older-modern tombstones remain permanent replay barriers.
$db=new SourceSealDb('c2c_source_deleted_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$old=validation_order($db);$delete=new \RodolfoRizzo\Cpt2Collection\OrderDeletionService(new \RodolfoRizzo\Cpt2Collection\OrderDeletionRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new \RodolfoRizzo\Cpt2Collection\UtcClock());check($delete->delete($old['order'])->code()==='deleted','actual older modern Phase 3 deletion');baseline_epoch_switch($db);
 $f=validation_order($db);check($delete->delete($f['order'])->code()==='deleted','actual source Phase 3 deletion');validation_receipt($db,$f,'evt_deleted_receipt');$r=retirement_pass($db,validation_pass($db,validation_start($db)));check($r->phase()==='deleting','genuine deletion evidence participates in source seal');
 $deletions=serialize($db->get_results('SELECT * FROM '.$t->order_deletions().' ORDER BY id'));source_absence($db,$r);$r=validation_bind($db,$r,$f['claim'],'event','evt_deleted_late',hash('sha256','deleted'),'checkout.session.completed');$r=source_retained($db,$r);
 check($r->checkpoint()==='z1_e_0_done','retained certification understands source and older genuine deletion');check($db->get_var('SELECT disposition FROM '.$t->provider_claims().' WHERE id='.$f['claim'])==='deleted'&&$db->get_var('SELECT disposition FROM '.$t->provider_claims().' WHERE id='.$old['claim'])==='deleted','deleted claims remain deleted');check($deletions===serialize($db->get_results('SELECT * FROM '.$t->order_deletions().' ORDER BY id')),'reset does not fabricate or change permanent deletion tombstones');
}finally{baseline_cleanup($db);}
// A contradiction after an earlier materialization keeps the unresolved cursor and stale certificate.
$db=new SourceSealDb('c2c_source_partial_block_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$g=validation_order($db);$r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'s1_s');sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='cs_before_block' WHERE id={$f['session']}");sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='invalid value' WHERE id={$g['session']}");$before=$r;$r=validation_service($db)->retire($r,2)->operation();
 check($r->status()==='blocked'&&$r->checkpoint()===$before->checkpoint()&&$r->cursor()===$before->cursor(),'partial coverage contradiction never advances unresolved cursor');check($r->evidence_generation()===$before->evidence_generation()+1&&$r->validation_generation()===$before->validation_generation(),'committed partial evidence invalidates certification while preserving block');$r=validation_bind($db,$r,$f['claim'],'session','cs_blocked_retry');check($r->status()==='blocked'&&$r->cursor()===$before->cursor(),'blocked partial coverage admits retained historical evidence without resuming');
}finally{baseline_cleanup($db);}
// Known rollback and retryable storage failure grant no seal authority.
foreach(['coverage','seal','retained','terminal'] as $position){$db=new SourceSealDb('c2c_source_rollback_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'s1_s');sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='cs_rollback' WHERE id={$f['session']}");
 $post=in_array($position,['retained','terminal'],true);if($position==='seal')$r=retirement_pass($db,$r,1,'s1_done');if($post){$r=retirement_pass($db,$r);$r=validation_bind($db,$r,$f['claim'],'event','evt_rollback',hash('sha256','rollback'),'checkout.session.completed');$r=validation_service($db)->certify_retained($r,1)->operation();if($position==='terminal'){$r=source_retained($db,$r,1,'z1_e_0_i');$last=(int)$db->get_var('SELECT MAX(id) FROM '.$t->provider_identities().' WHERE identity_value<>\'evt_rollback\'');while($r->cursor()<$last)$r=validation_service($db)->certify_retained($r,1)->operation();}}
 $snapshot=baseline_snapshot($db);$tx=new TransactionManager($db,$t);check($tx->begin_reset_maintenance($r),$position.' rollback owns exact snapshot');$repo=new CommerceResetRepository($db,$t);$next=$post?$repo->certify_retained($r,1,gmdate('Y-m-d H:i:s')):$repo->retire($r,1,gmdate('Y-m-d H:i:s'));check($next!==false&&$tx->rollback(),$position.' explicit rollback');check($snapshot===baseline_snapshot($db),$position.' rollback preserves durable pre-batch truth');
 $db->fault='write';$out=$post?validation_service($db)->certify_retained($r,1):validation_service($db)->retire($r,1);$db->fault='';check($out->code()==='database_error'&&$snapshot===baseline_snapshot($db),$position.' retryable failure rolls back all evidence and progress');
}finally{baseline_cleanup($db);}}
foreach(['coverage','coverage_done','seal','retained','retained_done','terminal'] as $position)foreach(['before','after'] as $fault){
 $prefix='c2c_source_loss_'.bin2hex(random_bytes(5)).'_';$db=new SourceSealDb($prefix);$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'s1_s');
 sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='cs_coverage_loss' WHERE id={$f['session']}");
 if($position==='coverage_done')$r=retirement_pass($db,$r,1,'s1_e');
 if($position==='seal')$r=retirement_pass($db,$r,1,'s1_done');
 $post=in_array($position,['retained','retained_done','terminal'],true);
 if($post){$r=retirement_pass($db,$r);$r=source_retained($db,$r);$r=validation_bind($db,$r,$f['claim'],'event','evt_loss_late',hash('sha256','loss'),'checkout.session.completed');$r=validation_service($db)->certify_retained($r,1)->operation();if(in_array($position,['terminal','retained_done'],true)){$r=source_retained($db,$r,1,'z1_e_0_i');$last=(int)$db->get_var('SELECT MAX(id) FROM '.$t->provider_identities());$before=$position==='terminal'?(int)$db->get_var('SELECT COALESCE(MAX(id),0) FROM '.$t->provider_identities().' WHERE id<'.$last):$last;while($r->cursor()<$before)$r=validation_service($db)->certify_retained($r,1)->operation();}}
 $snapshot=baseline_snapshot($db);$db->fault=$fault;$out=$post?validation_service($db)->certify_retained($r,1):validation_service($db)->retire($r,1);
 check($out->code()==='transaction_outcome_unknown'&&$out->operation()===null&&!$db->transaction_connection_usable(),$position.'/'.$fault.' uncertain connection grants no authority');$db->close();$db=new SourceSealDb($prefix);$fresh=validation_service($db)->resume($r->operation_identity())->operation();
 check($fault==='before'?$fresh->equals($r):$fresh->revision()===$r->revision()+1,$position.' fresh connection determines durable outcome');if($fault==='before')check($snapshot===baseline_snapshot($db),'close rolls back mutation and cursor together');
 $fresh=$post?source_retained($db,$fresh):retirement_pass($db,$fresh);check($fresh->status()==='running'&&$fresh->phase()==='deleting','fresh recovery resumes idempotently');
 }finally{try{$db->close();}catch(Throwable $e){}baseline_cleanup(new wpdb($prefix));}
}
echo "All strengthened source-seal and retained-evidence checks passed.\n";
