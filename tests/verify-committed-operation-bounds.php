<?php
/** Actual integrated D1 ownership SQL, fresh inspection and exact normal admission. */
declare(strict_types=1);
require __DIR__.'/support/committed-operation.php';
use RodolfoRizzo\Cpt2Collection\{CommittedOperationAuthority as A,TransactionManager,TableNames};
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

foreach([1000,10000] as $n){
 $prefix='c2c_d1_bounds_'.bin2hex(random_bytes(5)).'_';$db=new D1Db($prefix);$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);$old=d1_operation($db,$f);discovery_bind($db,$f,'session','cs_discovery');d1_delete($db,$f);discovery_finish($db,validation_start($db));$g=validation_order($db);$current=d1_operation($db,$g);
  foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $method=>$kind){$seed=get_object_vars($db->get_row('SELECT * FROM '.$t->$method().' ORDER BY id LIMIT 1'));discovery_fill($db,$t->$method(),$seed,$n,$kind);}
  $counts=[];
  foreach(['ready','later_reset'] as $state){
   if($state==='later_reset')validation_start($db);
   foreach(['on','off'] as $merge){
	sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");
	foreach(['old_classify','current_classify','old_inspect','current_inspect','current_admit'] as $route){
	 $op=str_starts_with($route,'old')?$old:$current;$db->captured=[];$db->capture=true;
	 if(str_ends_with($route,'classify')){$out=(new A($db,$t))->classify($op);check($out->status==='attributed','integrated D1 classification coherent');$queries=$db->captured;}
	 elseif(str_ends_with($route,'admit')){$tx=new TransactionManager($db,$t);$out=(new A($db,$t))->begin($op,$tx);check($out->status===($state==='ready'?'admitted':'admission_denied'),'exact fresh D1 admission bounded');$tx->rollback();$queries=$db->captured;}
	 else {
	  $before=$db->get_var('SELECT updated_at FROM '.$t->orders().' WHERE id='.$g['order']);
	  [$out,$connections]=d1_inspect(new D1Db($prefix),$op,new D1SyntheticStage($before,'2030-01-01 00:00:00'),fn(D1Db $fresh)=>sql($fresh,"SET SESSION optimizer_switch='index_merge=$merge'"));
	  check($out->status===($route==='old_inspect'?'permanently_deleted':($state==='ready'?'reentry_eligible':'fenced')),'integrated D1 inspection coherent');$queries=$connections[0]->captured;
	 }
	 $db->capture=false;$total=count($queries);$key=$state.':'.$route;$counts[$key][$merge]=$total;$max=0;$plans=[];
	 foreach(array_unique($queries) as $q){
	  if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
	  check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR '),'D1 retained ownership avoids OFFSET/OR family selection');
	  $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.preg_replace('/ (FOR UPDATE|LOCK IN SHARE MODE)$/','',$q)),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);$rows=0;
	  foreach($nodes as $node){$constant=in_array($node['access_type']??'', ['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','integrated D1 query has no full data scan');}
	  $operational=false;foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)if(str_contains($q,$t->$m()))$operational=true;
	  $bound=str_contains($q,'LIMIT 2')?2:1;if($operational)$bound=count($nodes);else $max=max($max,$rows);
	  check($rows<=$bound&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'integrated D1 retained bound or separately measured operational point join, no filesort/temp');
	  $plans[]=['query'=>$q,'index'=>array_column($nodes,'key'),'rows'=>$rows];
	 }
	 echo 'D1_SQL: '.json_encode(['fillers'=>$n,'state'=>$state,'route'=>$route,'index_merge'=>$merge,'statements'=>$total,'max_rows'=>$max,'plans'=>$plans])."\n";
	}
   }
  }
  foreach($counts as $route=>$values)check($values['on']===$values['off'],'optimizer does not change integrated D1 query count '.$route);
  foreach(['claims','deletions'] as $domain){
   if($domain==='claims')sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id='.$f['session'].' WHERE id>=100');
   else sql($db,'UPDATE '.$t->order_deletions().' SET installation_id=MD5(CAST(id AS CHAR)),original_order_id='.$f['order'].' WHERE id>=100');
   foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$db->capture=true;$db->captured=[];$out=(new A($db,$t))->classify($old);$queries=$db->captured;$db->capture=false;check($out->status==='ambiguous','D1 rejects large matching ambiguity family without arbitrary owner');
	$sentinel=array_values(array_filter($queries,fn($q)=>str_starts_with($q,'SELECT id FROM '.$t->{($domain==='claims'?'provider_claims':'order_deletions')}().' FORCE INDEX('.($domain==='claims'?'payment_session_id':'original_order_id').')')));check(count($sentinel)===1,'D1 emits one exact ambiguity sentinel');
	$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$sentinel[0]),true);$nodes=discovery_nodes($plan);check(count($nodes)===1&&$nodes[0]['r_rows']===2&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'D1 matching ambiguity selection examines exactly two of '.$n.' rows');
	echo 'D1_AMBIGUITY: '.json_encode(['fillers'=>$n,'domain'=>$domain,'index_merge'=>$merge,'rows'=>$nodes[0]['r_rows'],'index'=>$nodes[0]['key']])."\n";
   }
   if($domain==='claims')sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id=20000+(id-100) DIV 3 WHERE id>=100');
  }
 }finally{baseline_cleanup($db);}
}
echo "All D1 integrated SQL bounds passed.\n";
