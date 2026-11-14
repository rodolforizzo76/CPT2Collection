<?php
/** Phase E missing combined financial/provider/shared-stock races, real independent processes. */
declare(strict_types=1);
require __DIR__.'/support/cart-reconciliation-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,ProviderEvidenceRouter,ProviderDiscoveryCandidate,StripeEventNormalizer,PaymentSessionRepository};
class ERaceDb extends D4Db {
 public $before_end=null;public array $errors=[];
 public function get_results(string $q):?array {$out=parent::get_results($q);if($this->last_error!=='')$this->errors[]=['errno'=>$this->last_errno,'error'=>$this->last_error,'query'=>$q];return $out;}
 public function query(string $q):int|false {
  if(in_array($q,['COMMIT','ROLLBACK'],true)&&$this->before_end!==null&& (TransactionManager::admitted_on($this)||TransactionManager::retained_context_on($this)!==null)){$f=$this->before_end;$this->before_end=null;$f();}
  return parent::query($q);
 }
}
function e_wait($socket,wpdb $observer,array $tables):void {
 $id=(int)trim((string)fgets($socket));$deadline=microtime(true)+10;
 do{$row=$observer->get_row("SELECT INFO,TIME_MS FROM information_schema.PROCESSLIST WHERE ID=$id");$waiting=$row!==null&&count(array_filter($tables,static fn($table)=>str_contains($row->INFO??'',$table)))>0&&(float)$row->TIME_MS>=200;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
 $read=[$socket];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,0)===0,'E real independent waiter observed >=200ms: '.($row->INFO??'missing'));
}
function e_race_end($socket,int $pid):array {$line=trim((string)fgets($socket));pcntl_waitpid($pid,$status);fclose($socket);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'E independent actor exits cleanly: '.$line);$r=json_decode($line,true);check(is_array($r),'E actor supplies typed result');return $r;}
$count=0;
foreach(['shared_orders','provider_duplicate','provider_event_conflict','competing_attempts','association_outcome'] as $mode)foreach([false,true] as $reverse){
 $prefix='c2c_e_race_'.bin2hex(random_bytes(4)).'_';$db=new ERaceDb($prefix);$pid=null;
 try{
  $two=null;$other_event=null;$other_operation=null;$candidate=null;
  if($mode==='competing_attempts'){
   validation_schema($db);sql($db,"INSERT INTO {$db->posts} VALUES(78,'collection_item')");sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(78,'_cpt2collection_stock_quantity','20')");$one=validation_order($db);$two=d1_second_attempt($db,$one);webhook_activate($db,$two);[$other_event,$other_operation]=d3_event($db,$two,'checkout.session.completed','evt_e_current_attempt');[$event,$operation]=d3_event($db,$one,'checkout.session.completed','evt_e_original_attempt');
  }elseif($mode==='association_outcome'){[$one,$event,$operation]=d2_fixture($db);}
  else {[$one,$event,$operation]=d3_fixture($db);}
  if($mode==='shared_orders'){$two=validation_order($db);webhook_activate($db,$two);[$other_event,$other_operation]=d3_event($db,$two,'checkout.session.completed','evt_e_second_order');}
  elseif($mode==='provider_duplicate')$candidate=ProviderDiscoveryCandidate::response($operation->claim,'cs_gap_'.$one['reference'],'pi_'.$one['reference'],gmdate('Y-m-d H:i:s'));
  elseif($mode==='provider_event_conflict'){$p=webhook_payload($one,'evt_d3');$p['created']=strtotime($operation->receipt->provider_created_at().' UTC');$p['created']-=1;$receipt=(new StripeEventNormalizer())->normalize($p,hash('sha256',json_encode($p)))->receipt();$candidate=ProviderDiscoveryCandidate::verified_receipt($receipt,gmdate('Y-m-d H:i:s'));}
  $jobs=['sale','other'];if($reverse)$jobs=array_reverse($jobs);$stock=d3_stock($db);$db->close();
  $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();check($pid!==-1,'E competing actor forked');
  $run=static function(D2Db $actor,string $job)use($one,$event,$operation,$two,$other_event,$other_operation,$mode,$candidate):array {
   $actor->capture=true;$actor->captured=[];
   if($mode==='association_outcome'){[$a,$o]=d2_services($actor);$out=$job==='sale'?$a->associate($event,$operation):$o->ignore($event,'e_competing_outcome',$operation);return ['code'=>$job==='sale'?$out->outcome():$out->code(),'success'=>$job==='sale'?in_array($out->outcome(),['associated','already_associated'],true):$out->is_accepted(),'errors'=>$actor->errors];}
   if($job==='sale'){$out=d3_run($actor,'conversion',$one,$event,$operation);if($mode==='competing_attempts')foreach($actor->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'superseded attempt attempts zero DML under contention');return ['code'=>$out->code(),'success'=>$out->is_success(),'confirmed_rollback'=>$out->has_confirmed_rollback(),'errors'=>$actor->errors];}
   if(in_array($mode,['shared_orders','competing_attempts'],true)){$out=d3_run($actor,'conversion',$two,$other_event,$other_operation);return ['code'=>$out->code(),'success'=>$out->is_success(),'confirmed_rollback'=>$out->has_confirmed_rollback(),'errors'=>$actor->errors];}
   $out=(new ProviderEvidenceRouter($actor,new TableNames($actor)))->retain($candidate);return ['code'=>$out->status,'success'=>in_array($out->status,['duplicate','conflict'],true)];
  };
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],20);fgets($pair[1]);try{$child=new ERaceDb($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);$out=$run($child,$jobs[1]);$child->close();fwrite($pair[1],json_encode($out)."\n");exit(0);}catch(Throwable $x){fwrite($pair[1],'FAIL:'.$x->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new ERaceDb($prefix);$observer=new D4Db($prefix);$t=new TableNames($db);
  $db->before_end=function()use($pair,$observer,$t,$mode,$db){check(TransactionManager::admitted_on($db)||TransactionManager::retained_context_on($db)!==null,'E first actor holds actual production transaction authority');fwrite($pair[0],"go\n");fflush($pair[0]);e_wait($pair[0],$observer,match($mode){'shared_orders'=>[$db->posts,$t->payment_sessions()],'competing_attempts','association_outcome'=>[$t->orders()],default=>[$t->commerce_control()]});};
  $first=$run($db,$jobs[0]);check(!$db->closed,'E first actor retains a healthy connection after confirmed termination');check($db->before_end===null,'E first actor reached real COMMIT barrier');$second=e_race_end($pair[0],$pid);$pid=null;
  $outputs=[$jobs[0]=>$first,$jobs[1]=>$second];
  if($mode==='shared_orders'){
   $successful=(int)$first['success']+(int)$second['success'];check(d3_stock($db)===array_map(static fn($n)=>$n-$successful,$stock),'two independent orders have exactly one stock unit per committed sale');
   foreach([$first,$second] as $out)if(!$out['success'])check($out['confirmed_rollback']&&$out['code']==='database_error','shared-stock loser has confirmed database rollback, never partial sale');
   // Independent new admission verifies a conservative loser; this is not unknown-COMMIT replay.
   if(!$outputs['sale']['success'])check(d3_run($db,'conversion',$one,$event,$operation)->is_success(),'fresh admitted original first order completes after confirmed failure');
   if(!$outputs['other']['success'])check(d3_run($db,'conversion',$two,$other_event,$other_operation)->is_success(),'fresh admitted original second order completes after confirmed failure');
   check(d3_stock($db)===array_map(static fn($n)=>$n-2,$stock),'two coherent paid orders decrement shared products exactly twice');
   check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders()." WHERE status='paid'")===2&&(int)$db->get_var('SELECT COUNT(*) FROM '.$t->reservations()." WHERE status='converted'")===4,'both complete financial tuples committed');
   check(d4_service($db)->reconcile($one['order'],$operation)->is_completed()&&d4_service($db)->reconcile($two['order'],$other_operation)->is_completed(),'each paid order independently reconciles exact original cart');
   check((int)$db->get_var('SELECT SUM(content_revision) FROM '.$t->carts())===2,'shared product sales advance each independent cart once');
  }elseif($mode==='competing_attempts'){
   check(!$outputs['sale']['success']&&in_array($outputs['sale']['code'],['continuation_verification_required','database_error'],true),'old operation never acquires competing attempt authority');if($outputs['sale']['code']==='database_error')check($outputs['sale']['confirmed_rollback'],'stale old-attempt database snapshot is confirmed rolled back');$db->capture=true;$db->captured=[];check(d3_run($db,'conversion',$one,$event,$operation)->code()==='continuation_verification_required','fresh original attempt is positively refused as superseded');foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'fresh superseded attempt zero attempted DML');
   if(!$outputs['other']['success'])check(d3_run($db,'conversion',$two,$other_event,$other_operation)->is_success(),'current exact attempt completes under independent fresh admission');
   check(d3_stock($db)===array_map(static fn($n)=>$n-1,$stock),'competing attempts on one Order decrement only for exact completing owner');
   check($db->get_var('SELECT provider_event_id FROM '.$t->payment_sessions().' WHERE id='.$two['session'])==='evt_e_current_attempt','only exact second Session records completing Event');
  }elseif($mode==='association_outcome'){
   $current=d2_event($db,$event);if($current->processing_status()!=='ignored'){[, $o]=d2_services($db);check($o->ignore($current,'e_competing_outcome',$operation)->is_accepted(),'fresh exact outcome completes after conservative concurrent refusal');}
   check(d2_event($db,$event)->processing_status()==='ignored'&&d3_stock($db)===$stock,'association/outcome contention preserves terminal receipt and zero stock writes');
  }else{
   check($outputs['other']['code']===($mode==='provider_duplicate'?'duplicate':'conflict'),'concurrent provider envelope preserves typed evidence disposition');
   $paid=$mode==='provider_duplicate'||!$reverse;
   check(d3_stock($db)===($paid?array_map(static fn($n)=>$n-1,$stock):$stock),'provider serialization authorizes at most the coherent single sale');
   if($mode==='provider_event_conflict')check($db->get_var("SELECT binding_state FROM {$t->provider_identities()} WHERE identity_kind='event'")==='quarantined','conflicting authenticated Event remains quarantined');
   if(!$paid)check(!$outputs['sale']['success'],'quarantine-first cannot acquire financial authority');
  }
  echo 'E_RACE '.json_encode(['case'=>$mode,'first'=>$jobs[0],'first_result'=>$first,'second_result'=>$second,'stock'=>d3_stock($db)])."\n";$observer->close();++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}d3_cleanup($db);}
}
echo "Phase E combined independent-process races passed ($count scenarios).\n";
