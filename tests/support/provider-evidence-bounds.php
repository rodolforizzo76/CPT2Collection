<?php
declare(strict_types=1);
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
