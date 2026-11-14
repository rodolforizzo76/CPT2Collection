<?php
/** Actual erasure SQL: access-only synthetic PK fixtures, independently rolled back. */
declare(strict_types=1);
require __DIR__.'/support/reset-deletion.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetDeletion as D,TableNames,TransactionManager,CommerceResetRepository};
function erase_plan_tables(array $p):array {$out=[];foreach($p as $k=>$v){if($k==='table'&&is_array($v))$out[]=$v;elseif(is_array($v))$out=array_merge($out,erase_plan_tables($v));}return $out;}
function erase_fill(wpdb $db,string $table,object $seed,int $n,string $method):void {
 sql($db,'DELETE FROM '.$table);$fields=array_keys(get_object_vars($seed));
 for($start=1;$start<=$n;$start+=200){$values=[];for($i=$start;$i<min($start+200,$n+1);++$i){$row=get_object_vars($seed);$row['id']=100+3*$i;
  foreach(['token_hash','checkout_attempt_hash','creation_key_hash'] as $field)if(array_key_exists($field,$row))$row[$field]=hash('sha256',$method.$i);
  foreach(['cart_id','order_id','order_item_id'] as $field)if(array_key_exists($field,$row))$row[$field]=100000+$i;
  if(isset($row['reference']))$row['reference']=md5($method.$i);if(isset($row['provider_event_id']))$row['provider_event_id']='evt_bound_'.$i;
  $values[]='('.implode(',',array_map(fn($f)=>$row[$f]===null?'NULL':$db->prepare('%s',(string)$row[$f]),$fields)).')';
 }sql($db,'INSERT INTO '.$table.' (`'.implode('`,`',$fields).'`) VALUES '.implode(',',$values));}
}
foreach([1000,10000] as $filler){$db=new DeletionDb('c2c_erase_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r]=deletion_setup($db,1);$stock=deletion_stock($db);$report=[];
 foreach(D::STAGES as $stage=>$method){$seed=$db->get_row('SELECT * FROM '.$t->$method().' LIMIT 1');erase_fill($db,$t->$method(),$seed,$filler,$method);
  foreach(['on','off'] as $merge)foreach([1,100] as $batch)foreach([0,100+3*intdiv($filler,2),100+3*$filler] as $cursor){
   sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");sql($db,'START TRANSACTION');sql($db,$db->prepare('DELETE FROM '.$t->$method().' WHERE id<=%d',$cursor));
   // Synthetic access position, not a semantic certificate; every measurement rolls back.
   sql($db,$db->prepare('UPDATE '.$t->commerce_resets().' SET checkpoint=%s,`cursor`=0 WHERE id=%d',D::prefix($stage,$cursor).'done',$r->id()));sql($db,'COMMIT');
   $fresh=validation_service($db)->resume($r->operation_identity())->operation();$tx=new TransactionManager($db,$t);$db->writes=[];$db->reads=[];$db->observe=true;check($tx->begin_reset_deletion($fresh),'bounded production erasure admission');$next=(new CommerceResetRepository($db,$t))->delete_operational($fresh,$batch,gmdate('Y-m-d H:i:s'));check($next!==false&&$next->status()==='running'&&$tx->rollback(),'actual bounded erasure/progress rolled back');$db->observe=false;
   $queries=array_unique(array_filter(array_merge($db->reads,$db->writes),fn($q)=>str_contains($q,$t->$method())&&(str_starts_with($q,'SELECT id ')||str_starts_with($q,'DELETE FROM '))));
   check(count($queries)>=2,'actual stage selection and DELETE/emptiness captured');
   foreach($queries as $q){sql($db,'START TRANSACTION');$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);sql($db,'ROLLBACK');$nodes=erase_plan_tables($plan);$count=0;
	check(count($nodes)===1,'one physical table in erasure plan');foreach($nodes as $node){$count+=($node['r_rows']??0)*($node['r_loops']??1);check(($node['key']??'')==='PRIMARY'&&($node['access_type']??'')!=='ALL','PRIMARY access independent of optimizer index_merge');}
	$limit=str_starts_with($q,'DELETE ')?1:(str_contains($q,'id>')?$batch:1);check($count<=$limit,'actual examined rows bounded by batch or unique point');check(!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'no sort or temporary materialization');
	$kind=str_starts_with($q,'DELETE ')?'delete':(str_contains($q,'id>')?'selection':'probe');$report[$method]['batch_'.$batch][$kind]=max($report[$method]['batch_'.$batch][$kind]??0,$count);
   }
   // Restore filler for next independently measured cursor.
   erase_fill($db,$t->$method(),$seed,$filler,$method);
  }
  // Positive behind-frontier probe uses the exact captured production query and examines one row.
  $cursor=100+3*intdiv($filler,2);sql($db,$db->prepare('UPDATE '.$t->commerce_resets().' SET checkpoint=%s,`cursor`=0 WHERE id=%d',D::prefix($stage,$cursor).'done',$r->id()));$fresh=validation_service($db)->resume($r->operation_identity())->operation();$tx=new TransactionManager($db,$t);$db->reads=[];$db->observe=true;check($tx->begin_reset_deletion($fresh),'synthetic behind-frontier access owns deletion');$next=(new CommerceResetRepository($db,$t))->delete_operational($fresh,1,gmdate('Y-m-d H:i:s'));check($next!==false&&$next->status()==='blocked'&&$tx->rollback(),'positive behind-frontier anomaly blocks without deletion');$db->observe=false;
  $queries=array_filter($db->reads,fn($q)=>str_contains($q,$t->$method())&&str_contains($q,'WHERE id<='));check(count($queries)===1,'actual positive behind-frontier query captured');foreach($queries as $q){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=erase_plan_tables($plan);check(count($nodes)===1&&$nodes[0]['key']==='PRIMARY'&&$nodes[0]['r_rows']==1,'nonempty probe examines exactly one PRIMARY row');$report[$method]['positive_probe']=1;}
 }
 check($stock===deletion_stock($db),'access measurements preserve physical stock');echo 'EVIDENCE: filler='.$filler.'; batch=1,100; beginning/middle/end-empty; sparse PK gaps; index_merge=on,off; max actual rows='.json_encode($report)."\n";
 // Actual all-seven-empty certificate query capture, not a final epoch switch.
 foreach(D::STAGES as $method)sql($db,'DELETE FROM '.$t->$method());sql($db,"UPDATE {$t->commerce_resets()} SET checkpoint='z1_x_0_done',`cursor`=0");$fresh=validation_service($db)->resume($r->operation_identity())->operation();$db->reads=[];$db->observe=true;$out=validation_service($db)->delete_operational($fresh);$db->observe=false;check(D::certified_empty($out->operation()),'actual empty certificate after seven completed access stages');foreach(D::STAGES as $method)check(count(array_filter($db->reads,fn($q)=>str_contains($q,$t->$method())&&str_starts_with($q,'SELECT id ')))===1,'one bounded final emptiness probe for '.$method);
 }finally{baseline_cleanup($db);}}
echo "All operational erasure access bounds passed.\n";
