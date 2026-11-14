<?php
/** C independently authenticated actors; real MariaDB control waits, both orderings. */
declare(strict_types=1);
require __DIR__.'/support/provider-webhook.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['start','duplicate_start','conflict_start','final','later_start','already_later','validation','operational','duplicates','conflicts','delete','retirement','noop'] as $mode)foreach(['webhook_first','other_first'] as $ordering){
 $prefix='c2c_c_race_'.bin2hex(random_bytes(5)).'_';$db=new ResponseDb($prefix);$t=new TableNames($db);$actors=[];$observer=null;
 try{
  validation_schema($db);$f=validation_order($db,1);$p=webhook_payload($f);$r=null;$done=null;
  $current=in_array($mode,['start','duplicate_start','conflict_start','operational','duplicates','conflicts','delete'],true);
  if($current)webhook_activate($db,$f);
  if(in_array($mode,['duplicate_start','conflict_start'],true)){$connections=[];check(webhook_deliver(webhook_controller($db,$connections),$p)->get_status()===200,'original delivery before reset race');}
  if($mode==='conflict_start')$p['created']-=1;
  if($mode==='conflicts')$p['type']='checkout.session.async_payment_succeeded';
  if(in_array($mode,['later_start','already_later'],true))$done=discovery_finish($db,validation_start($db));
  if(in_array($mode,['final','validation','retirement','noop','already_later'],true))$r=validation_start($db);
  if($mode==='noop'){$connections=[];check(webhook_deliver(webhook_controller($db,$connections),$p)->get_status()===200,'historical noop original retained');$r=validation_service($db)->resume($r->operation_identity())->operation();}
  if($mode==='retirement')$r=validation_pass($db,$r,100);
  if($mode==='final'){$r=retirement_pass($db,validation_pass($db,$r,100),100);$r=deletion_pass($db,deletion_retained($db,$r,100),100);}
  $history=$done===null?null:serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));$stock=deletion_stock($db);$db->close();
  $webhook=function(ResponseDb $actor,$socket,bool $hold)use($p,$mode):string{
   $connections=[];$held=false;$gate=function(string $q)use($socket,$hold,&$held):void{
	if($hold&&!$held&&str_contains($q,'c2c_commerce_control')&&(str_ends_with($q,'FOR UPDATE')||str_ends_with($q,'LOCK IN SHARE MODE'))){$held=true;response_send($socket,'HELD');check(response_signal($socket)==='release','deterministic webhook barrier released');}
   };
   if($mode==='operational')$actor->after_read=function($q)use($gate){if(str_ends_with($q,'LOCK IN SHARE MODE'))$gate($q);};
   $controller=webhook_controller($actor,$connections,function(ResponseDb $c)use($gate,$mode){if($mode!=='operational')$c->after_read=fn($q)=>$gate($q);});
   $actor->capture=true;$actor->captured=[];$result=webhook_deliver($controller,$p);$actor->capture=false;
   response_no_operational($connections);
   if(!in_array($mode,['start','duplicate_start','conflict_start','operational','duplicates','conflicts','delete'],true))foreach($actor->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q),'historical racing operational actor zero DML attempts');
   return (string)$result->get_status();
  };
  $other=function(ResponseDb $actor,$socket,bool $hold)use($f,$p,$r,$mode,$webhook):string{
   if(in_array($mode,['duplicates','conflicts'],true)){
	// Both requests are signed independently. First original has different hash in conflicts.
	$other=$p;if($mode==='conflicts')$other['type']='checkout.session.completed';
	$held=false;$gate=function($q)use($socket,$hold,&$held){if($hold&&!$held&&str_contains($q,'c2c_commerce_control')&&str_ends_with($q,'FOR UPDATE')){$held=true;response_send($socket,'HELD');check(response_signal($socket)==='release','deterministic second-delivery barrier released');}};
	$connections=[];$out=webhook_deliver(webhook_controller($actor,$connections,fn($c)=>$c->after_read=fn($q)=>$gate($q)),$other);response_no_operational($connections);return (string)$out->get_status();
   }
   $held=false;$actor->after_read=function($q)use($socket,$hold,&$held){if($hold&&!$held&&str_contains($q,'c2c_commerce_control')&&str_ends_with($q,'FOR UPDATE')){$held=true;response_send($socket,'HELD');check(response_signal($socket)==='release','deterministic reset/deletion barrier released');}};
   if($mode==='delete'){webhook_deleted($actor,$f);return 'deleted';}
   if(in_array($mode,['start','duplicate_start','conflict_start','later_start','operational'],true))return validation_service($actor)->start(bin2hex(random_bytes(16)),$actor->get_var('SELECT identity FROM '.(new TableNames($actor))->commerce_epochs().' WHERE retired_at IS NULL'))->code();
   if($mode==='final')return validation_service($actor)->finalize($r)->code();
   if($mode==='retirement')return validation_service($actor)->retire($r,1)->code();
   return validation_service($actor)->validate($r,1)->code();
  };
  $first=$ordering==='webhook_first'?$webhook:$other;$second=$ordering==='webhook_first'?$other:$webhook;
  $a=response_worker($prefix,fn($actor,$socket)=>$first($actor,$socket,true));$actors[]=$a;check(response_signal($a[1])==='HELD','first independent actor owns foundation before contender');
  $b=response_worker($prefix,fn($actor,$socket)=>$second($actor,$socket,false));$actors[]=$b;$observer=new ResponseDb($prefix);response_wait($observer,$b[2]);$observer->close();$observer=null;
  response_send($a[1],'release');$one=response_join($a);array_shift($actors);$two=response_join($b);array_shift($actors);
  $db=new ResponseDb($prefix);$t=new TableNames($db);$web=$ordering==='webhook_first'?$one:$two;$otherResult=$ordering==='webhook_first'?$two:$one;
  check(in_array($web,['200','500','503'],true),'racing authenticated webhook has explicit fail-closed/ack disposition '.$mode.' '.$ordering.' '.$web);
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims())===1,'races never manufacture a second claim');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities().' WHERE claim_id<>'.$f['claim'])===0,'races preserve original native owner');
  if($mode==='duplicates')check((int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77")===19,'racing coherent duplicates decrement physical stock once');
  if($mode==='conflicts')check(in_array((int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77"),[19,20],true),'racing quarantine permits zero conversion if it wins, never a second stock decrement');
  if(!$current)check(deletion_stock($db)===$stock,'historical race never writes physical stock');
  if($history!==null)check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'older completed reset stays frozen during later race');
  if($mode==='conflicts')check($db->get_var("SELECT event_type FROM {$t->provider_identities()} WHERE identity_kind='event'")===($ordering==='webhook_first'?'checkout.session.async_payment_succeeded':'checkout.session.completed'),'same-Event conflicting accepted type never overwrites first authenticated type');
  if($mode==='noop')check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===$r->evidence_generation(),'historical no-op never invalidates active certification');
  if(in_array($mode,['conflict_start','conflicts'],true))check($db->get_var("SELECT binding_state FROM {$t->provider_identities()} WHERE identity_kind='event'")==='quarantined','conflicting racing Event quarantines original immutable owner');
  echo "C_RACE: $mode $ordering webhook=$web other=$otherResult\n";
 }finally{foreach($actors as $a){@fwrite($a[1],"release\n");pcntl_waitpid($a[0],$status);}if($observer!==null)$observer->close();if($db->closed)$db=new ResponseDb($prefix);baseline_cleanup($db);}
}
echo "All independent C authenticated webhook races passed.\n";
