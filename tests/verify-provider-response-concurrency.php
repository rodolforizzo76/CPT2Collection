<?php
/** Independent actors at the actual production provider call and evidence/activation seams. */
declare(strict_types=1);
require __DIR__.'/support/provider-response.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,ProviderEvidenceRouter,ProviderDiscoveryCandidate,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['start','final','later_start','later_active','delete','between'] as $mode)foreach(['success','exception'] as $kind){
 $prefix='c2c_b_network_'.bin2hex(random_bytes(5)).'_';$db=new ResponseDb($prefix);validation_schema($db);$f=validation_order($db);$capture=response_captured($db,$f);$r=null;$done=null;
 if(in_array($mode,['final','later_start','later_active'],true))$r=validation_start($db);
 if($mode==='final'){$r=retirement_pass($db,validation_pass($db,$r,100),100);$r=deletion_pass($db,deletion_retained($db,$r,100),100);}
 if(in_array($mode,['later_start','later_active'],true)){$done=discovery_finish($db,$r);if($mode==='later_active')$r=validation_start($db);}
 $db->close();
 $worker=response_worker($prefix,function(ResponseDb $c,$socket)use($capture,$kind,$mode):string{
  $p=new ModernBoundaryProvider();$p->exception=$kind==='exception';$p->during_call=function()use($socket,$c){check(TransactionManager::context_on($c)===null&&TransactionManager::retained_context_on($c)===null,'network stage carries DATA and holds no foundation authority');response_send($socket,'NETWORK');check(response_signal($socket)==='RETURN','network barrier resumes');};$connections=[];
  $configure=$mode==='between'?function(ResponseDb $e)use($socket){$e->on_close=function()use($socket){response_send($socket,'EVIDENCE_END');check(response_signal($socket)==='CONTINUE','evidence/operational gap resumes');};}:null;
  $svc=response_service($c,$p,$connections,$configure);$c->capture=true;$c->captured=[];$out=response_resume($svc,$capture);
  check(!$out->is_success(),'network fence refuses checkout success');
  foreach($c->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'fenced response attempts zero operational DML');response_no_operational($connections);return $out->get_code();
 });
 check(response_signal($worker[1])==='NETWORK','actual production network seam paused '.$mode.' '.$kind);$db=new ResponseDb($prefix);$t=new TableNames($db);
 try{
  if($mode==='between'){response_send($worker[1],'RETURN');check(response_signal($worker[1])==='EVIDENCE_END','A1 committed and released before reset wins');}
  if(in_array($mode,['start','between','later_start'],true))$r=validation_start($db);
  elseif($mode==='final'){check(validation_service($db)->finalize($r)->code()==='completed','final switch completes while provider HTTP paused');$done=validation_service($db)->resume($r->operation_identity())->operation();}
  elseif($mode==='delete')check((new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock()))->delete($f['order'])->code()==='deleted','permanent deletion wins during HTTP');
  $before=response_business($db);$history=$done===null?null:serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));
  response_send($worker[1],$mode==='between'?'CONTINUE':'RETURN');response_join($worker);
  check($before===response_business($db),'network winner permits evidence only, zero business/stock/claim/foundation mutation');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities().' WHERE claim_id='.$f['claim'])===2,'late response retains Session/PI under original native owner');
  if($history!==null)check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'completed history byte stable across network response');
  if($r!==null&&$mode!=='final')check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===($mode==='between'?0:2),'correct active generation domain across network gap');
 }finally{baseline_cleanup($db);}
}
// Operational admission wins: reset start must wait behind its shared control lock.
foreach(['success','exception'] as $kind){
 $prefix='c2c_b_operational_'.bin2hex(random_bytes(5)).'_';$db=new ResponseDb($prefix);validation_schema($db);$f=validation_order($db);$capture=response_captured($db,$f);$stock=deletion_stock($db);$db->close();
 $op=response_worker($prefix,function(ResponseDb $c,$s)use($capture,$kind):string{
  $p=new ModernBoundaryProvider();$p->exception=$kind==='exception';$connections=[];$enabled=false;$held=false;
  $c->after_read=function($q)use($c,$s,&$enabled,&$held){if($enabled&&!$held&&TransactionManager::context_on($c)!==null){$held=true;response_send($s,'OPERATION_HELD');check(response_signal($s)==='RELEASE','operational authority barrier releases');}};
  $svc=response_service($c,$p,$connections,function(ResponseDb $e)use(&$enabled){$e->on_close=function()use(&$enabled){$enabled=true;};});
  $out=response_resume($svc,$capture);response_no_operational($connections);return $out->get_code();
 });check(response_signal($op[1])==='OPERATION_HELD','fresh exact operational authority obtained before reset');
 $reset=response_worker($prefix,function(ResponseDb $c)use($f):string{return validation_service($c)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();});$db=new ResponseDb($prefix);
 try{response_wait($db,$reset[2]);response_send($op[1],'RELEASE');$code=response_join($op);check($code===($kind==='success'?'payment_session_created':'provider_response_unknown'),'valid in-flight operational continuation preserves behavior');check(response_join($reset)==='started','reset starts only after operational transaction ends');check(deletion_stock($db)===$stock,'operational activation performs no stock conversion');}
 finally{baseline_cleanup($db);}
}
// Evidence wins: certification/finalization/duplicate/conflict observers queue at control.
foreach(['validation','final','duplicate','conflict'] as $mode){
 $prefix='c2c_b_evidence_'.bin2hex(random_bytes(5)).'_';$db=new ResponseDb($prefix);validation_schema($db);$f=validation_order($db);$other=$mode==='conflict'?validation_order($db):$f;$capture=response_captured($db,$f);$other_claim=discovery_claim($db,$other);$r=validation_start($db);
 if($mode==='final'){$r=retirement_pass($db,validation_pass($db,$r,100),100);$r=deletion_pass($db,deletion_retained($db,$r,100),100);}$db->close();
 $op=response_worker($prefix,function(ResponseDb $c,$s)use($capture):string{
  $p=new ModernBoundaryProvider();$connections=[];$held=false;$svc=response_service($c,$p,$connections,function(ResponseDb $e)use($s,&$held){$e->after_write=function($q)use($e,$s,&$held){if(!$held&&str_starts_with($q,'INSERT INTO')&&str_contains($q,'provider_identities')){$held=true;response_send($s,'EVIDENCE_HELD');check(response_signal($s)==='RELEASE','evidence barrier releases');}};});
  $out=response_resume($svc,$capture);response_no_operational($connections);return $out->get_code();
 });check(response_signal($op[1])==='EVIDENCE_HELD','actual B identity DML owns evidence control lock');
 $waiter=response_worker($prefix,function(ResponseDb $c)use($mode,$r,$other_claim,$capture):string{
  if($mode==='validation')return validation_service($c)->validate($r,1)->code();if($mode==='final')return validation_service($c)->finalize($r)->code();
  $i=ProviderDiscoveryCandidate::response($other_claim,'cs_gap_'.$capture[1]->reference(),'pi_'.$capture[1]->reference(),gmdate('Y-m-d H:i:s'));$c->capture=true;$out=(new ProviderEvidenceRouter($c,new TableNames($c)))->retain($i);
  check(!array_filter($c->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'duplicate/conflicting observer attempts zero DML');return $out->status;
 });$db=new ResponseDb($prefix);$t=new TableNames($db);
 try{response_wait($db,$waiter[2]);response_send($op[1],'RELEASE');check(response_join($op)==='commerce_reset_fenced','evidence-first response remains non-operational');$code=response_join($waiter);
  check($code===match($mode){'validation'=>'stale','final'=>'stale','duplicate'=>'duplicate','conflict'=>'ambiguous'},'fresh waiting actor revalidates generation/identity '.$mode.' '.$code);
  check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===2,'evidence changes charge exactly two; duplicate/conflicting observer charges zero');
 }finally{baseline_cleanup($db);}
}

// Duplicate/conflicting identity observations commit before the paused response returns.
foreach(['duplicate_first','conflict_first'] as $mode){
 $prefix='c2c_b_observer_'.bin2hex(random_bytes(5)).'_';$db=new ResponseDb($prefix);validation_schema($db);$f=validation_order($db);$other=$mode==='conflict_first'?validation_order($db):$f;$capture=response_captured($db,$f);$claim=discovery_claim($db,$other);$db->close();
 $w=response_worker($prefix,function(ResponseDb $c,$s)use($capture):string{$p=new ModernBoundaryProvider();$p->during_call=function()use($s){response_send($s,'NETWORK');check(response_signal($s)==='RETURN','observer network resumes');};$connections=[];$out=response_resume(response_service($c,$p,$connections),$capture);check(!$out->is_success(),'observer-first response cannot activate fenced/conflicting session');foreach($connections as $e)check(!array_filter($e->captured,fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)),'observer-first response attempts no retained DML');return $out->get_code();});check(response_signal($w[1])==='NETWORK','observer races actual paused provider call');$db=new ResponseDb($prefix);$t=new TableNames($db);
 try{$r=validation_start($db);$input=ProviderDiscoveryCandidate::response($claim,'cs_gap_'.$capture[1]->reference(),'pi_'.$capture[1]->reference(),gmdate('Y-m-d H:i:s'));check((new ProviderEvidenceRouter($db,$t))->retain($input)->status==='retained','independent observer commits first');$before=baseline_snapshot($db);response_send($w[1],'RETURN');response_join($w);check($before===baseline_snapshot($db),'duplicate/conflict response leaves all committed owner/generation/business facts unchanged');}
 finally{baseline_cleanup($db);}
}
// Certification authority wins first and commits before B can invalidate its generation.
$prefix='c2c_b_certification_'.bin2hex(random_bytes(5)).'_';$db=new ResponseDb($prefix);validation_schema($db);$f=validation_order($db);$capture=response_captured($db,$f);$db->close();
$w=response_worker($prefix,function(ResponseDb $c,$s)use($capture):string{$p=new ModernBoundaryProvider();$p->during_call=function()use($s){response_send($s,'NETWORK');check(response_signal($s)==='RETURN','certification network resumes');};$connections=[];return response_resume(response_service($c,$p,$connections),$capture)->get_code();});check(response_signal($w[1])==='NETWORK','certification races paused network');$db=new ResponseDb($prefix);$r=validation_start($db);$db->close();
$validator=response_worker($prefix,function(ResponseDb $c,$s)use($r):string{$held=false;$c->after_write=function($q)use($c,$s,&$held){if(!$held&&TransactionManager::reset_context_on($c)!==null&&str_starts_with($q,'UPDATE ')){$held=true;response_send($s,'CERTIFICATION_HELD');check(response_signal($s)==='RELEASE','certification lock releases');}};return validation_service($c)->validate($r,1)->code();});check(response_signal($validator[1])==='CERTIFICATION_HELD','production certification transaction owns control before response');$db=new ResponseDb($prefix);$t=new TableNames($db);
try{response_send($w[1],'RETURN');response_wait($db,$w[2]);response_send($validator[1],'RELEASE');check(response_join($validator)==='progressed','certification commits first');check(response_join($w)==='commerce_reset_fenced','later evidence stays non-operational');$current=validation_service($db)->resume($r->operation_identity())->operation();check($current->evidence_generation()===2&&$current->validation_generation()===0,'later response invalidates only the active certification generation');}
finally{baseline_cleanup($db);}
echo "All twenty-one independent synchronous B network/concurrency scenarios passed.\n";
