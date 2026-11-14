<?php
/** Actual emitted A0 SQL with 1k/10k retained fillers and optimizer on/off. */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
use RodolfoRizzo\Cpt2Collection\{ProviderRetainedDiscovery,ProviderDiscoveryCandidate as I,ProviderClaimLocator as L,TableNames,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,TransactionManager,UtcClock};
function discovery_nodes(array $plan):array{$out=[];foreach($plan as $k=>$v){if($k==='table'&&is_array($v))$out[]=$v;elseif(is_array($v))$out=array_merge($out,discovery_nodes($v));}return $out;}
function discovery_fill(wpdb $db,string $table,array $seed,int $n,string $kind):void{
 $fields=array_keys($seed);
 for($start=1;$start<=$n;$start+=200){$values=[];for($i=$start;$i<min($start+200,$n+1);++$i){$row=$seed;$row['id']=100+3*$i;
  if($kind==='epochs'){$row['identity']=md5('epoch'.$i);$row['retired_at']=$seed['created_at'];}
  if($kind==='resets'){$row['operation_identity']=md5('reset'.$i);$row['installation_id']=md5('other inst');$row['source_epoch_id']=100+3*$i;$row['source_epoch_identity']=md5('epoch'.$i);$row['target_epoch_identity']=md5('target'.$i);$row['target_epoch_id']=null;$row['active_slot']=null;}
  if($kind==='claims'){$row['reference']=md5('claim'.$i);$row['idempotency_key']='c2c_ps_'.$row['reference'];$row['order_id']=10000+$i;$row['payment_session_id']=20000+$i;$row['creation_key_hash']=hash('sha256','claim'.$i);}
  if($kind==='identities'){$row['identity_value']='cs_filler_'.$i;}
  if($kind==='deletions'){$row['original_order_id']=10000+$i;$row['cart_id']=30000+$i;$row['checkout_attempt_hash']=hash('sha256','delete'.$i);}
  $values[]='('.implode(',',array_map(fn($v)=>$v===null?'NULL':$db->prepare('%s',(string)$v),array_values($row))).')';
 }sql($db,'INSERT INTO '.$table.' (`'.implode('`,`',$fields).'`) VALUES '.implode(',',$values));}
}
foreach([1000,10000] as $n){$db=new DiscoveryDb('c2c_a0_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);discovery_bind($db,$f,'session','cs_discovery');discovery_bind($db,$f,'payment','pi_discovery');discovery_bind($db,$f,'event','evt_discovery',hash('sha256','body'),'checkout.session.completed');
  $del=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock());check($del->delete($f['order'])->code()==='deleted','bounded positive real deletion owner');
  $claim=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);
  $done=discovery_finish($db,validation_start($db));
  foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $method=>$kind){$seed=get_object_vars($db->get_row('SELECT * FROM '.$t->$method().' ORDER BY id LIMIT 1'));discovery_fill($db,$t->$method(),$seed,$n,$kind);}
  $inputs=[];foreach([L::reference($f['reference']),L::id($f['claim']),L::idempotency($claim->idempotency_key),L::attempt($f['installation'],(int)$claim->epoch_id,$f['order'],$claim->creation_key_hash),L::payment_session($f['session'])] as $l)$inputs[]=I::correlated('stripe_checkout','test',$l,['session'=>'cs_discovery','payment'=>'pi_discovery'],gmdate('Y-m-d H:i:s'));
  $inputs[]=discovery_webhook($f,payment:'pi_discovery');$inputs[]=I::correlated('stripe_checkout','test',L::reference(md5('absent')),['session'=>'cs_missing'],gmdate('Y-m-d H:i:s'));
  // Capture old completed owner both with control ready and a later active certification domain.
  foreach(['ready','resetting'] as $state){
   if($state==='resetting')$active=validation_start($db);
   foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$queries=[];
	foreach($inputs as $input){$db->capture=true;$db->captured=[];$out=(new ProviderRetainedDiscovery($db,$t))->discover($input);$db->capture=false;check(in_array($out->status,['attributed','unattributable'],true),'actual bounded discovery under '.$n.' filler');$queries=array_merge($queries,$db->captured);}
	$report=[];
	foreach(array_unique($queries) as $q){if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
	 check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR ')&&!str_contains($q,'FOR UPDATE')&&!str_contains($q,'LOCK IN SHARE MODE'),'captured query avoids OR/OFFSET/row locks');
	 $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);$rows=0;
	 foreach($nodes as $node){$constant=in_array($node['access_type']??'', ['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','actual query no full data scan');}
	 $bound=str_contains($q,'LIMIT 2')?2:1;
	 check($rows<=$bound&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'actual query bound '.$bound.' examined '.$rows.' without sort/temp');
	 $report[]=['query'=>$q,'indexes'=>array_column($nodes,'key'),'rows'=>$rows,'bound'=>$bound];
	}
	echo 'EVIDENCE: filler='.$n.' state='.$state.' index_merge='.$merge.' '.json_encode($report)."\n";
   }
  }
  // A LIMIT 2 must bound a huge matching duplicate range, not just unrelated filler.
  foreach(['claims','deletions'] as $domain){
   if($domain==='claims')sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id='.$f['session'].' WHERE id>=100');
   else sql($db,'UPDATE '.$t->order_deletions().' SET installation_id=MD5(CAST(id AS CHAR)),original_order_id='.$f['order'].' WHERE id>=100');
   foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$db->capture=true;$db->captured=[];$out=(new ProviderRetainedDiscovery($db,$t))->discover($inputs[0]);$db->capture=false;check($out->status==='ambiguous','huge duplicate '.$domain.' range rejected without arbitrary owner');
	$matching=array_filter($db->captured,fn($q)=>str_starts_with($q,'SELECT id FROM '.$t->{($domain==='claims'?'provider_claims':'order_deletions')}().' FORCE INDEX('.($domain==='claims'?'payment_session_id':'original_order_id').')'));
	check(count($matching)===1,'one actual duplicate sentinel query');$q=reset($matching);$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);
	check(count($nodes)===1&&$nodes[0]['r_rows']===2&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'matching '.$n.' duplicate rows examines exactly two indexed candidates');
	echo 'DUPLICATE_EVIDENCE: filler='.$n.' domain='.$domain.' index_merge='.$merge.' '.json_encode(['query'=>$q,'index'=>$nodes[0]['key'],'rows'=>$nodes[0]['r_rows']])."\n";
   }
   if($domain==='claims')sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id=20000+(id-100) DIV 3 WHERE id>=100');
  }
 }finally{baseline_cleanup($db);}
}
echo "All A0 captured retained access bounds passed.\n";
