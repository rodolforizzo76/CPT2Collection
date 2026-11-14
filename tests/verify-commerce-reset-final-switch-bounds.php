<?php
/** Captured production admission/switch/recovery queries against adversarial history. */
declare(strict_types=1);
require __DIR__.'/support/reset-final-switch.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceResetRepository};
function final_plan_nodes(array $p):array {$out=[];foreach($p as $k=>$v){if($k==='table'&&is_array($v))$out[]=$v;elseif(is_array($v))$out=array_merge($out,final_plan_nodes($v));}return $out;}
function final_probe_fill(wpdb $db,string $table,object $seed,int $n,string $method):void {
 sql($db,'DELETE FROM '.$table);$fields=array_keys(get_object_vars($seed));
 for($start=1;$start<=$n;$start+=200){$values=[];for($i=$start;$i<min($start+200,$n+1);++$i){$row=get_object_vars($seed);$row['id']=100+3*$i;
  foreach(['token_hash','checkout_attempt_hash','creation_key_hash'] as $field)if(array_key_exists($field,$row))$row[$field]=hash('sha256',$method.$i);
  foreach(['cart_id','order_id','order_item_id'] as $field)if(array_key_exists($field,$row))$row[$field]=100000+$i;
  if(isset($row['reference']))$row['reference']=md5($method.$i);if(isset($row['provider_event_id']))$row['provider_event_id']='evt_bound_'.$i;
  $values[]='('.implode(',',array_map(fn($f)=>$row[$f]===null?'NULL':$db->prepare('%s',(string)$row[$f]),$fields)).')';
 }sql($db,'INSERT INTO '.$table.' (`'.implode('`,`',$fields).'`) VALUES '.implode(',',$values));}
}
foreach([1000,10000] as $n){$db=new FinalSwitchDb('c2c_final_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 [$r,$fixtures,$seeds]=final_setup($db,1);$at=$r->updated_at();$created=$r->started_at();$seed=get_object_vars($db->get_row('SELECT * FROM '.$t->commerce_resets()));
 for($start=1;$start<=$n;$start+=200){$epochs=[];$resets=[];for($i=$start;$i<min($start+200,$n+1);++$i){$id=100+3*$i;$identity=md5('epoch filler '.$i);$epochs[]=$db->prepare('(%d,%s,%s,%s)',$id,$identity,$created,$at);
  $row=$seed;$row['installation_id']=md5('filler installation');$row['id']=$i+1;$row['operation_identity']=md5('operation filler '.$i);$row['source_epoch_id']=$id;$row['source_epoch_identity']=$identity;$row['target_epoch_identity']=md5('reserved filler '.$i);$row['active_slot']=null;$row['status']='completed';$row['phase']='completed';$row['completed_at']=$at;
  $resets[]='('.implode(',',array_map(fn($v)=>$v===null?'NULL':$db->prepare('%s',(string)$v),array_values($row))).')';
 }sql($db,'INSERT INTO '.$t->commerce_epochs().' VALUES '.implode(',',$epochs));sql($db,'INSERT INTO '.$t->commerce_resets().' (`'.implode('`,`',array_keys($seed)).'`) VALUES '.implode(',',$resets));}
 // Access-only synthetic retired epoch/reset fillers; semantic completion tested separately.
 foreach(['beginning','middle','end'] as $position){
 if($position!=='beginning'){
  $source=100+3*($position==='middle'?intdiv($n,2):$n);$identity=md5('source '.$position.$n);
  sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET retired_at=%s WHERE id=%d',$at,$r->source_epoch_id()));
  sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET identity=%s,retired_at=NULL WHERE id=%d',$identity,$source));
  sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET current_epoch_id=%d WHERE id=1',$source));
  sql($db,$db->prepare('UPDATE '.$t->commerce_resets().' SET source_epoch_id=%d,source_epoch_identity=%s WHERE id=%d',$source,$identity,$r->id()));
  $r=validation_service($db)->resume($r->operation_identity())->operation();
 }
 foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$tx=new TransactionManager($db,$t);$db->reads=[];$db->observe=true;
  check($tx->begin_reset_finalization($r),'production final admission with '.$n.' historical fillers');$out=(new CommerceResetRepository($db,$t))->finalize($r,$at);check($out!==false&&$out->target_epoch_id()===101+3*$n,'bounded sparse high-end allocation');$db->observe=false;check($tx->rollback(),'measurement switch rolls back');
  $queries=array_unique($db->reads);$report=[];
  foreach($queries as $q){if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema'))continue;
   sql($db,'START TRANSACTION');$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);sql($db,'ROLLBACK');$nodes=final_plan_nodes($plan);$rows=0;
   foreach($nodes as $node){$rows+=($node['r_rows']??(in_array($node['access_type']??'', ['const','system'],true)?1:0))*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','actual query has no full data scan: '.$q);}
   $limit=str_contains($q,'LIMIT 2')?2:1;check($rows<=$limit,'actual rows <= fixed probe bound '.$limit.': '.$rows.'; '.$q);
   check(!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'no filesort/temp: '.$q);
   $report[]=['query'=>$q,'indexes'=>array_column($nodes,'key'),'rows'=>$rows];
  }
  echo 'EVIDENCE: filler='.$n.' source='.$position.' index_merge='.$merge.' '.json_encode($report)."\n";
 }
 }
 // Positive final probes: same production path, access-only residuals, rollback block.
 foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $method){
  final_probe_fill($db,$t->$method(),$seeds[$method],$n,$method);
  $tx=new TransactionManager($db,$t);$db->reads=[];$db->observe=true;check($tx->begin_reset_finalization($r),'positive-probe fixture owns final fence');
  $blocked=(new CommerceResetRepository($db,$t))->finalize($r,$r->updated_at());$db->observe=false;
  check($blocked!==false&&$blocked->status()==='blocked'&&$blocked->error_code()==='deletion_residual_'.$method&&$tx->rollback(),'actual finalizer blocks filled '.$method.' without erasure');
  $positive=array_values(array_filter($db->reads,fn($q)=>str_starts_with($q,'SELECT id FROM '.$t->$method().' FORCE')));
  check(count($positive)===1,'one captured positive final probe');
  sql($db,'START TRANSACTION');$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$positive[0]),true,512,JSON_THROW_ON_ERROR);sql($db,'ROLLBACK');$nodes=final_plan_nodes($plan);
  check(count($nodes)===1&&$nodes[0]['key']==='PRIMARY'&&$nodes[0]['r_rows']==1&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'positive final probe examines exactly one PRIMARY row at '.$n.' filler');
  sql($db,'DELETE FROM '.$t->$method());
 }
 // Canonical terminal recovery on actual completed source/target, fillers retained.
 $done=validation_service($db)->finalize($r)->operation();$db->reads=[];$db->observe=true;$fresh=validation_service($db)->resume($r->operation_identity());$db->observe=false;
 check($fresh->operation()->equals($done),'actual canonical recovery with retained history fillers');
 foreach(array_unique($db->reads) as $q){if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema'))continue;
  $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=final_plan_nodes($plan);$rows=0;foreach($nodes as $node)$rows+=($node['r_rows']??(in_array($node['access_type']??'', ['const','system'],true)?1:0))*($node['r_loops']??1);
  check($rows<=2&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'recovery probes bounded by at most two indexed rows: '.$q);
 }
}finally{baseline_cleanup($db);}}
echo "All final-switch access bounds passed.\n";
