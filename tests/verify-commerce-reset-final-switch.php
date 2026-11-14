<?php
/** Atomic production switch: authority, recovery, preservation and new epoch. */
declare(strict_types=1);
require __DIR__.'/support/reset-final-switch.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRecord,CommerceResetRepository,CommerceResetLifecycle,CommerceResetDeletion as D,TransactionManager,TableNames,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry,CommerceContext,CartRepository,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock};
function current_time(string $type,bool $gmt=false):int {return $GLOBALS['final_clock']??time();}
$db=new FinalSwitchDb('c2c_final_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try {
 [$r,$f]=final_setup($db);$svc=validation_service($db);$before=baseline_snapshot($db);$kept=final_preserved($db);$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
 foreach(['bare','control','maintenance','deletion','historical'] as $mode){
  $e=new CommerceEvidenceContext($r,ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f[0]['claim'])));
  $ok=match($mode){'bare'=>$tx->begin(),'control'=>$tx->begin_reset_control(),'maintenance'=>$tx->begin_reset_maintenance($r),'deletion'=>$tx->begin_reset_deletion($r),'historical'=>$tx->begin_historical_evidence($e)};
  check($ok,'authority matrix admits '.$mode);check($repo->finalize($r,$r->updated_at())===false&&$tx->rollback(),'no epoch mutation through '.$mode.' authority');check(baseline_snapshot($db)===$before,'denied capability changes nothing');
 }
 foreach(['operation_identity'=>str_repeat('8',32),'source_epoch_identity'=>str_repeat('9',32),'installation_id'=>str_repeat('7',32),'source_epoch_id'=>100,'target_epoch_identity'=>str_repeat('5',32),'target_epoch_id'=>2,'requested_by'=>44,'cursor'=>1,'revision'=>$r->revision()+1,'phase'=>'finalizing','checkpoint'=>'z1_x_0_done','validation_generation'=>null] as $k=>$v){
  $bad=final_row($db,[$k=>$v]);check(!$svc->finalize($bad)->is_success(),'wrong expected '.$k.' fails closed');check(baseline_snapshot($db)===$before,'wrong expected snapshot never allocates');
 }
 check($tx->begin_reset_finalization($r),'dedicated exact finalization capability');
 check(TransactionManager::context_on($db)===null&&TransactionManager::reset_context_on($db)===null&&TransactionManager::reset_deletion_on($db)===null&&TransactionManager::evidence_context_on($db)===null,'finalizer has no commerce/maintenance/deletion/provider capability');
 $db->observe=true;$db->reads=[];$db->writes=[];$done=$repo->finalize($r,$r->updated_at());$db->observe=false;
 foreach($db->writes as $q)check(str_starts_with($q,'INSERT INTO '.$t->commerce_epochs())||str_starts_with($q,'UPDATE '.$t->commerce_epochs())||str_starts_with($q,'UPDATE '.$t->commerce_control())||str_starts_with($q,'UPDATE `'.$t->commerce_resets().'`'),'finalizer writes only epoch/control/reset lifecycle state');
 check($done instanceof CommerceResetRecord&&$done->completion_of($r),'one canonical completion built from exact entry');
 foreach(D::STAGES as $m)check(count(array_filter($db->reads,fn($q)=>str_contains($q,$t->$m())&&str_starts_with($q,'SELECT id ')))===1,'one fresh PRIMARY LIMIT 1 probe for '.$m);
 check($repo->finalize($r,$r->updated_at())===false,'same live owner cannot switch twice');check($tx->rollback()&&baseline_snapshot($db)===$before,'explicit pre-COMMIT rollback restores complete exact state');
 $GLOBALS['final_clock']=strtotime($r->updated_at().' UTC')+5;
 $out=$svc->finalize($r);$done=$out->operation();check($out->code()==='completed'&&$done->target_epoch_id()===2,'one fresh target allocated and completed');
 check($done->completed_at()===gmdate('Y-m-d H:i:s',$GLOBALS['final_clock']),'deterministic UTC clock controls stable completion timestamp');
 check($done->status()==='completed'&&$done->phase()==='completed'&&$done->checkpoint()==='z1_n_0_done'&&$done->cursor()===0&&$done->evidence_generation()===$done->validation_generation(),'canonical terminal phase/certificate/generation');
 check($done->active_slot()===null&&$done->completed_at()===$done->updated_at()&&$done->revision()===$r->revision()+1,'completion fields atomically published exactly once');
 check($db->get_var('SELECT retired_at FROM '.$t->commerce_epochs().' WHERE id=1')===$done->completed_at()&&$db->get_var('SELECT created_at FROM '.$t->commerce_epochs().' WHERE id=2')===$done->completed_at(),'source retirement and target creation use same UTC completion clock');
 $c=$db->get_row('SELECT * FROM '.$t->commerce_control());check($c->state==='ready'&&(int)$c->current_epoch_id===2&&$c->installation_id===$r->installation_id(),'control points to unretired target and preserves installation');
 check(final_preserved($db)===$kept,'stock/products/baseline/config/evidence/operational domain unchanged');
 $terminal=baseline_snapshot($db);foreach([$r,$done] as $retry)check($svc->finalize($retry)->operation()->equals($done)&&baseline_snapshot($db)===$terminal,'exact prior or completed snapshot retry is read-only idempotent');
 $old=final_row_completed($db,$done,['revision'=>$r->revision()-1]);check(!$svc->finalize($old)->is_success(),'unrelated stale revision cannot claim committed result');
 check($svc->resume($r->operation_identity(),$r->source_epoch_identity())->operation()->equals($done),'fresh locked recovery recognizes complete terminal truth');
 check(!$tx->begin_historical_evidence(new CommerceEvidenceContext($done,$e->claim())),'fresh completed-source historical primitive denied; automatic route remains deferred');
 check(!$svc->certify_retained($r)->is_success()&&!$svc->delete_operational($r)->is_success(),'stale retained/deletion workers cannot mutate completed reset');
 check($tx->begin_commerce_shared(new CommerceContext($r->installation_id(),2,$r->target_epoch_identity())),'normal admission succeeds only for target');
 check($repo->finalize($done,$done->completed_at())===false,'normal target context cannot invoke epoch switch');
 // Existing generic registry API can retain old eligible evidence under current
 // normal admission; it does not grant source operational authority or reset generation.
 $registry=new ProviderRegistry($db,$t);
 check($registry->bind('stripe_checkout','event','evt_fresh_late_source',$f[0]['claim'],$done->completed_at(),hash('sha256','late source'),'checkout.session.completed','test')==='bound','existing normal registry primitive retains late retired-source identity');
 $payload=modern_payload($f[0]['reference'],'evt_fresh_late_source',$r->installation_id(),$r->source_epoch_identity());
 $receipt=(new \RodolfoRizzo\Cpt2Collection\StripeEventNormalizer())->normalize($payload,hash('sha256','late source'))->receipt();
 check($registry->route($receipt,$tx->commerce_context())['status']==='retired','late source registry routing remains non-operational without source rows');
 check($svc->resume($r->operation_identity())->code()==='database_error','separate owner cannot inspect while normal transaction owns connection');
 check($tx->rollback()&&baseline_snapshot($db)===$terminal,'late low-level evidence demonstration rolls back with completion stable');
 check(!$tx->begin_commerce_shared(new CommerceContext($r->installation_id(),1,$r->source_epoch_identity())),'normal source authority rejected');
 foreach(D::STAGES as $m)check(!$db->get_results('SELECT id FROM '.$t->$m().' LIMIT 1'),'old '.$m.' operational history absent');
 $new=validation_order($db,1,false,false);check((int)$db->get_var('SELECT epoch_id FROM '.$t->carts().' WHERE id='.$new['cart'])===2&&(int)$db->get_var('SELECT epoch_id FROM '.$t->orders().' WHERE id='.$new['order'])===2,'real new cart/order scoped to target');
 check($new['cart']!==$f[0]['cart']&&$new['order']!==$f[0]['order'],'old identifiers not resurrected');
 $next=$svc->start(str_repeat('6',32),$r->target_epoch_identity(),9);check($next->code()==='started'&&$next->operation()->source_epoch_id()===2&&$next->operation()->target_epoch_identity()!==$r->target_epoch_identity()&&$next->operation()->target_epoch_identity()!==$r->source_epoch_identity(),'future reset reserves independent target and active slot');
 check($svc->resume($r->operation_identity())->operation()->equals($done),'previous completed history remains inspectable during next legitimate reset');
} finally {unset($GLOBALS['final_clock']);baseline_cleanup($db);}
function final_row_completed(wpdb $db,CommerceResetRecord $record,array $changes):CommerceResetRecord {$t=new TableNames($db);return CommerceResetRecord::from_row((object)array_merge(get_object_vars($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$record->id())),$changes));}
// Residual detection is a durable block, never remediation/deletion or epoch allocation.
foreach(D::STAGES as $method){$db=new FinalSwitchDb('c2c_final_residual_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r,$f,$seeds]=final_setup($db);check($db->insert($t->$method(),get_object_vars($seeds[$method]),array_fill(0,count(get_object_vars($seeds[$method])),'%s'))===1,'test-only residual '.$method.' restored');
 $keep=deletion_kept($db);$out=validation_service($db)->finalize($r);check($out->code()==='blocked'&&$out->operation()->error_code()==='deletion_residual_'.$method&&$out->operation()->checkpoint()==='z1_n_0_done','final probes block '.$method.' without deleting it');
 check(deletion_kept($db)===$keep&&$out->operation()->target_epoch_id()===null&&$out->operation()->completed_at()===null,'residual failure preserves source/control/target/evidence/stock');
 check(validation_service($db)->finalize($out->operation())->code()==='conflict','blocked reset cannot finalize');
}finally{baseline_cleanup($db);}}
// Fixed metadata rejects hidden effects, nontransactional foundation and missing keys.
foreach(['source_trigger','control_trigger','source_engine','control_engine','epoch_identity_index','reset_active_index'] as $case){$db=new FinalSwitchDb('c2c_final_storage_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r]=final_setup($db,0);$before=baseline_snapshot($db);
 if($case==='source_trigger')sql($db,'CREATE TRIGGER '.$db->prefix.'source_stock BEFORE UPDATE ON '.$t->commerce_epochs()." FOR EACH ROW UPDATE ".$db->postmeta." SET meta_value='999'");
 if($case==='control_trigger')sql($db,'CREATE TRIGGER '.$db->prefix.'control_stock BEFORE UPDATE ON '.$t->commerce_control()." FOR EACH ROW UPDATE ".$db->postmeta." SET meta_value='999'");
 if($case==='source_engine')sql($db,'ALTER TABLE '.$t->commerce_epochs().' ENGINE=MyISAM');
 if($case==='control_engine')sql($db,'ALTER TABLE '.$t->commerce_control().' ENGINE=MyISAM');
 if($case==='epoch_identity_index')sql($db,'ALTER TABLE '.$t->commerce_epochs().' DROP INDEX identity');
 if($case==='reset_active_index')sql($db,'ALTER TABLE '.$t->commerce_resets().' DROP INDEX active_slot');
 check(!validation_service($db)->finalize($r)->is_success()&&baseline_snapshot($db)===$before,'storage '.$case.' rejects capability without any data mutation');
}finally{baseline_cleanup($db);}}
// SQL failures at each meaningful boundary: no partial terminal effect survives.
$db=new FinalSwitchDb('c2c_final_rollback_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r]=final_setup($db);$before=baseline_snapshot($db);
 foreach(['FROM '.$t->payment_events().' FORCE','ORDER BY id DESC LIMIT 1'] as $read){$db->read_failure=$read;check(validation_service($db)->finalize($r)->code()==='database_error','failure after admission/probes rolls back');$db->read_failure='';check(baseline_snapshot($db)===$before,'read fault restores exact snapshot');}
 foreach(['INSERT INTO '.$t->commerce_epochs(),'UPDATE '.$t->commerce_epochs(),'UPDATE '.$t->commerce_control(),'UPDATE `'.$t->commerce_resets().'`'] as $mutation)foreach([false,true] as $after){
  $db->failure=$mutation;$db->after_statement=$after;check(validation_service($db)->finalize($r)->code()==='database_error','SQL failure before/after meaningful mutation');$db->failure='';check(baseline_snapshot($db)===$before,'insert/retirement/control/completion rollback is exact');
 }
 $tx=new TransactionManager($db,$t);
 check($tx->begin_reset_finalization($r),'direct finalizer admitted for rollback-only failure proof');
 $db->failure='UPDATE '.$t->commerce_control();$db->after_statement=true;
 check((new CommerceResetRepository($db,$t))->finalize($r,$r->updated_at())===false,'direct SQL failure after mutation returns failure');
 $db->failure='';check(!$tx->commit()&&baseline_snapshot($db)===$before,'failed primitive forces rollback even if direct caller attempts COMMIT');
 check(validation_service($db)->finalize($r)->code()==='completed','retry after known rollback uses reserved identity once');
}finally{baseline_cleanup($db);}
// Unknown COMMIT outcomes require a discarded connection and fresh locked recovery.
foreach(['before','after'] as $loss){$prefix='c2c_final_loss_'.bin2hex(random_bytes(5)).'_';$db=new FinalSwitchDb($prefix);try{
 [$r]=final_setup($db);$before=baseline_snapshot($db);$keep=final_preserved($db);$db->fault=$loss;$svc=validation_service($db);$out=$svc->finalize($r);check($out->code()==='transaction_outcome_unknown'&&$out->operation()===null&&!$db->transaction_connection_usable(),'unknown '.$loss.' COMMIT poisons authority and returns no guessed snapshot');
 check($svc->finalize($r)->code()==='transaction_outcome_unknown','poisoned connection cannot retry');$db->close();$db=new FinalSwitchDb($prefix);$svc=validation_service($db);$fresh=$svc->resume($r->operation_identity())->operation();
 check($loss==='before'?$fresh->equals($r)&&baseline_snapshot($db)===$before:$fresh->completion_of($r),'fresh recovery observes exact old or whole canonical completed state');
 $done=$svc->finalize($fresh)->operation();check($done->completion_of($r)&&final_preserved($db)===$keep,'fresh retry recovers exactly one completion without evidence/stock change');
 check($svc->finalize($r)->operation()->equals($done),'stale exact entry recognizes success without second target');
}finally{baseline_cleanup($db);}}
// Allocation is one indexed reverse probe over retained epochs, not AUTO_INCREMENT.
foreach(['sparse','php_max_minus_one','php_max','unsigned_max','orphan_active','identity_conflict','missing_source'] as $case){$db=new FinalSwitchDb('c2c_final_alloc_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r]=final_setup($db,0);$at=$r->updated_at();$id=match($case){'php_max_minus_one'=>(string)(PHP_INT_MAX-1),'php_max'=>(string)PHP_INT_MAX,'unsigned_max'=>'18446744073709551615',default=>'10001'};
 if($case==='sparse'){sql($db,$db->prepare('INSERT INTO '.$t->commerce_epochs().' VALUES(5000,%s,%s,%s)',md5('missing historical epoch'),$at,$at));sql($db,'DELETE FROM '.$t->commerce_epochs().' WHERE id=5000');}
 if($case==='identity_conflict')sql($db,$db->prepare('INSERT INTO '.$t->commerce_epochs().' VALUES(10001,%s,%s,%s)',$r->target_epoch_identity(),$at,$at));
 elseif($case==='missing_source')sql($db,'DELETE FROM '.$t->commerce_epochs().' WHERE id=1');
 else sql($db,$db->prepare('INSERT INTO '.$t->commerce_epochs().' VALUES('.$id.',%s,%s,'.($case==='orphan_active'?'NULL':'%s').')',str_repeat('f',32),$at,...($case==='orphan_active'?[]:[$at])));
 $before=baseline_snapshot($db);$out=validation_service($db)->finalize($r);
 if(in_array($case,['sparse','php_max_minus_one'],true)){check($out->code()==='completed'&&$out->operation()->target_epoch_id()===(int)$id+1,'sparse/high safe maximum allocates monotone exact integer');}
 else check(!$out->is_success()&&baseline_snapshot($db)===$before,'overflow/conflicting identity/orphan/missing source fails before mutation');
}finally{baseline_cleanup($db);}}
// Genuine permanent deletion and reset retirement remain separate retained evidence.
$db=new FinalSwitchDb('c2c_final_phase3_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$old=validation_order($db);$del=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock());check($del->delete($old['order'])->code()==='deleted','real permanent order deletion fixture');$live=validation_order($db);
 $r=deletion_pass($db,deletion_retained($db,retirement_pass($db,validation_pass($db,validation_start($db)))),100);$keep=final_preserved($db);
 check(validation_service($db)->finalize($r)->code()==='completed'&&final_preserved($db)===$keep,'atomic switch preserves all Phase 3 tombstones/identities/replay evidence');
 check($db->get_var('SELECT disposition FROM '.$t->provider_claims().' WHERE id='.$old['claim'])==='deleted'&&$db->get_var('SELECT disposition FROM '.$t->provider_claims().' WHERE id='.$live['claim'])==='retired','deleted and reset-retired claims remain distinct');
}finally{baseline_cleanup($db);}
// Real source sale is already physical history; finalization never restores it.
$db=new FinalSwitchDb('c2c_final_paid_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$order=payment_fixture($db);[$payments]=payment_service($db,new ModernBoundaryProvider());check($payments->create($order,bin2hex(random_bytes(16)))->is_success(),'real guarded payment with local provider stub');$session=$db->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE order_id='.$order);$claim=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE payment_session_id='.$session->id);$payload=modern_payload($session->reference,'evt_final_paid',$claim->installation_id,$claim->epoch_identity);check(webhook_for($db)->handle($payload,hash('sha256',json_encode($payload)))->status()===200,'real paid conversion before reset');
 $r=deletion_pass($db,deletion_retained($db,retirement_pass($db,validation_pass($db,validation_start($db)))),100);$keep=final_preserved($db);check(validation_service($db)->finalize($r)->code()==='completed'&&final_preserved($db)===$keep&&$db->get_var('SELECT meta_value FROM '.$db->postmeta)==='19','atomic final switch preserves historical physical sale without stock restoration');
}finally{baseline_cleanup($db);}
echo "All atomic final-switch checks passed.\n";
