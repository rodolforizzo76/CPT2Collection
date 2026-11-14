<?php
/** D1 descriptor integrity, positive owner classification and fresh authority isolation. */
declare(strict_types=1);
require __DIR__.'/support/committed-operation.php';
use RodolfoRizzo\Cpt2Collection\{CommittedOperation as O,CommittedOperationAuthority as A,ProviderClaimRecord,ProviderDiscoveryCandidate,ProviderRetainedDiscovery,ProviderEvidenceRouter,ProviderRegistry,TransactionManager,TableNames,CommerceContext,PaymentSessionRepository};
function d1_reject(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|TypeError|Error $e){check(true,$label);return;}throw new RuntimeException($label);}
$db=new D1Db('c2c_d1_core_'.bin2hex(random_bytes(5)).'_');
try {
 validation_schema($db);$f=validation_order($db);$t=new TableNames($db);$op=d1_operation($db,$f);$claim=$op->claim;$bytes=serialize($op);
 check($claim->id()===$f['claim']&&$claim->payment_session_id()===$f['session']&&$claim->order_id()===$f['order']&&$claim->installation_id()===$f['installation']&&$claim->epoch_identity()===$f['epoch'],'descriptor carries complete original native coordinates and request identity');
 check($op->receipt===null&&$op->source==='native_request','Event is optional for native request operation');
 d1_reject(fn()=> $op->source='other','descriptor is immutable');
 $receipt=d1_receipt($f);$event=O::authenticated_receipt($claim,$receipt,gmdate('Y-m-d H:i:s'));check($event->receipt===$receipt&&$event->candidate->event_hash===hash('sha256','body'),'authenticated Event immutable receipt facts preserved');
 foreach(['id','installation_id','epoch_id','epoch_identity','order_id','payment_session_id','creation_key_hash','request_snapshot','reference','environment'] as $field){$row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);unset($row->$field);d1_reject(fn()=>ProviderClaimRecord::from_row($row),'missing mandatory '.$field.' rejected');}
 d1_reject(fn()=>O::authenticated_receipt($claim,d1_receipt($f,live:true),gmdate('Y-m-d H:i:s')),'foreign receipt environment rejected');
 $foreign=array_merge($f,['installation'=>md5('foreign')]);d1_reject(fn()=>O::authenticated_receipt($claim,d1_receipt($foreign),gmdate('Y-m-d H:i:s')),'foreign receipt installation rejected');
 d1_reject(fn()=>O::authenticated_receipt($claim,d1_receipt($f,type:'unhandled.event'),gmdate('Y-m-d H:i:s')),'unsupported receipt source rejected');
 $row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$row->provider='other_provider';d1_reject(fn()=>O::native_request(ProviderClaimRecord::from_row($row),gmdate('Y-m-d H:i:s')),'unsupported native provider rejected');
 $truth=d1_classify($db,$op);check($truth->status==='attributed'&&$truth->fence==='current_ready','positive current ready original owner');
 $tx=new TransactionManager($db,$t);$a=new A($db,$t);
 $foreignDb=new D1Db($db->prefix);$foreignTx=new TransactionManager($foreignDb,new TableNames($foreignDb));check($a->begin($op,$foreignTx)->reason==='manager_connection_mismatch'&&!$foreignTx->is_active(),'normal continuation manager must own this exact connection');$foreignDb->close();
 check(TransactionManager::context_on($db)===null&&!(new PaymentSessionRepository($db,$t))->mark_failed($f['session'],gmdate('Y-m-d H:i:s')),'descriptor and A0 result cannot authorize operational write');
 check($a->begin($op,$tx)->status==='admitted','fresh exact normal authority obtained');$old=$tx->commerce_context();check($tx->commit(),'admission is transaction scoped');
 check(TransactionManager::context_on($db)===null&&!(new PaymentSessionRepository($db,$t))->mark_failed($f['session'],gmdate('Y-m-d H:i:s')),'old CommerceContext cannot authorize mutation after commit');
 check($a->begin($op,$tx)->status==='admitted'&&$tx->rollback(),'fresh exact admission required on every stage');
 check(serialize($op)===$bytes&&serialize(unserialize($bytes))===$bytes,'immutable original descriptor preserved across commit/rollback/stage serialization');
 $other=d1_second_attempt($db,$f);$op2=d1_operation($db,$other);check($op2->claim->order_id()===$claim->order_id()&&$op2->claim->id()!==$claim->id()&&$op2->claim->payment_session_id()!==$claim->payment_session_id(),'different legitimate attempts share original order without collapsing identity');
 check(d1_classify($db,$op)->owner->claim_id===$claim->id()&&d1_classify($db,$op2)->owner->claim_id===$op2->claim->id(),'each exact attempt inspected independently');
 $row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$row->payment_session_id=$other['session'];$bad=O::native_request(ProviderClaimRecord::from_row($row),gmdate('Y-m-d H:i:s'));check(d1_classify($db,$bad)->status==='conflict','conflicting captured claim/Session coordinates never become native authority');
 $input=ProviderDiscoveryCandidate::response($claim,'cs_d1',null,gmdate('Y-m-d H:i:s'));check((new ProviderEvidenceRouter($db,$t))->retain($input)->status==='retained','fixture A1 retained evidence');
 $etx=new TransactionManager($db,$t);$etx->begin_retained_evidence($input);check(TransactionManager::retained_context_on($db)!==null&&TransactionManager::context_on($db)===null,'A1 capability cannot become normal authority');check($a->begin($op,$etx)->status==='admission_denied'&&!(new PaymentSessionRepository($db,$t))->mark_failed($other['session'],gmdate('Y-m-d H:i:s')),'A1 is not operational continuation permission');check($etx->rollback(),'A1 fixture ended by its owner');
 discovery_bind($db,$f,'event','evt_d1',hash('sha256','body'),'checkout.session.completed');
 check(d1_classify($db,$event)->status==='attributed','same exact Event coherent');$changed=O::authenticated_receipt($claim,d1_receipt($f,'different'),gmdate('Y-m-d H:i:s'));check(d1_classify($db,$changed)->status==='conflict','same Event ID with conflicting immutable facts rejected');
 $r=validation_start($db);$before=baseline_snapshot($db);$truth=d1_classify($db,$op);check($truth->fence==='active_reset_source','active reset fences original owner');check($a->begin($op,$tx)->status==='admission_denied'&&baseline_snapshot($db)===$before,'failed admission never falls back to historical mutation');
 // Complete R1 and then start R2: E1 stays E1 independently of current target.
 $done=discovery_finish($db,$r);check(d1_classify($db,$op)->fence==='completed_retired','completed retired original source');$history=baseline_snapshot($db);
 check($a->begin($op,$tx)->status==='admission_denied','current target cannot adopt original source');
 $r2=validation_start($db);check(d1_classify($db,$op)->fence==='older_retired_during_active_reset','E1 original owner while later R2 active');
 check(serialize($op)===$bytes,'destructive erasure cannot rewrite carried original DATA');
 $row=$db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id());sql($db,'UPDATE '.$t->commerce_resets()." SET checkpoint='forged' WHERE id=".$done->id());check(d1_classify($db,$op)->status==='corrupt','corrupt canonical completed history fails closed');sql($db,'UPDATE '.$t->commerce_resets()." SET checkpoint='z1_n_0_done' WHERE id=".$done->id());
}finally{baseline_cleanup($db);}
foreach(['deleted','deleted_then_reset','missing','ambiguous','foreign'] as $mode){
 $db=new D1Db('c2c_d1_owner_'.bin2hex(random_bytes(5)).'_');try{validation_schema($db);$f=validation_order($db);$t=new TableNames($db);$op=d1_operation($db,$f);
 if(str_starts_with($mode,'deleted')){d1_delete($db,$f);if($mode==='deleted_then_reset')discovery_finish($db,validation_start($db));$truth=d1_classify($db,$op);check($truth->owner->lifecycle==='permanently_deleted'&&$truth->deletion!==null,'genuine deletion stays distinct through '.$mode);}
 elseif($mode==='missing'){sql($db,'DELETE FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$out=d1_classify($db,$op);check($out->status==='unattributable'&&$out->deletion===null,'missing retained owner is not deletion or rollback');}
 elseif($mode==='ambiguous'){$row=get_object_vars($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']));$row['id']=99;$row['reference']=md5('dup');$row['idempotency_key']='c2c_ps_'.$row['reference'];$row['creation_key_hash']=hash('sha256','dup');$r=json_decode($row['request_snapshot'],true);$r['reference']=$row['reference'];$r['idempotency_key']=$row['idempotency_key'];$row['request_snapshot']=json_encode($r);check($db->insert($t->provider_claims(),$row,array_fill(0,count($row),'%s'))===1,'corrupt duplicate Session fixture');check(d1_classify($db,$op)->status==='ambiguous','ambiguous original Session denied');}
 else{$row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$row->installation_id=md5('foreign');$req=json_decode($row->request_snapshot,true);$req['configuration']['installation_id']=$row->installation_id;$row->request_snapshot=json_encode($req);$foreign=O::native_request(ProviderClaimRecord::from_row($row),gmdate('Y-m-d H:i:s'));check(d1_classify($db,$foreign)->status==='foreign','foreign original installation remains foreign');}
 }finally{baseline_cleanup($db);}
}
echo "All D1 exact operation and authority checks passed.\n";
foreach(['read_error','begin_before','begin_after','rollback_before','rollback_after'] as $fault){
 $prefix='c2c_d1_failure_'.bin2hex(random_bytes(5)).'_';$db=new D1Db($prefix);
 try{validation_schema($db);$f=validation_order($db);$op=d1_operation($db,$f);$t=new TableNames($db);
  if($fault==='read_error')$db->read_failure='FORCE INDEX(PRIMARY) ORDER BY id LIMIT 2';
  else {$db->bookkeeping_failure=str_starts_with($fault,'begin')?'START TRANSACTION WITH CONSISTENT SNAPSHOT':'ROLLBACK';$db->bookkeeping_after=str_ends_with($fault,'after');}
  $out=(new A($db,$t))->classify($op);check($out->status===($fault==='read_error'?'database_error':'transaction_outcome_unknown')&&$out->owner===null,'D1 classification preserves explicit '.$fault.' without guessing owner/deletion');
  if($fault!=='read_error'){$db->capture=true;$db->captured=[];$again=(new A($db,$t))->classify($op);check($again->status==='transaction_outcome_unknown'&&$db->poison_sql===0&&$db->captured===[],'poisoned classification refuses all SQL and authority');}
  $db->close();$db=new D1Db($prefix);check((new A($db,$t))->classify($op)->status==='attributed','fresh classification after uncertain snapshot independently proves owner');
 }finally{baseline_cleanup($db);}
}
echo "All D1 classification uncertainty checks passed.\n";
