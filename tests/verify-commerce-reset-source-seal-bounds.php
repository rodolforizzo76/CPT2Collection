<?php
/** Adversarial physical streams and all actual point probes, including admission. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,ProviderRegistry,TransactionManager};
class SourceBoundDb extends wpdb {
 public bool $observe=false; public array $reads=[];
 public function get_results(string $q):?array{if($this->observe)$this->reads[]=$q;return parent::get_results($q);}
 public function get_row(string $q):?object{if($this->observe)$this->reads[]=$q;return parent::get_row($q);}
}
function seal_examined(array $p):float{$n=0;foreach($p as $k=>$v){if($k==='table'&&is_array($v))$n+=($v['r_rows']??0)*($v['r_loops']??0);elseif(is_array($v))$n+=seal_examined($v);}return $n;}
function seal_fill(wpdb $db,string $table,object $seed,int $n,array $fixed,array $unique):void{
 $fields=array_keys(get_object_vars($seed));$fields=array_values(array_diff($fields,['id']));
 for($start=1;$start<=$n;$start+=200){$values=[];for($i=$start;$i<min($start+200,$n+1);++$i){$row=array_merge(get_object_vars($seed),$fixed);foreach($unique as $field=>$factory)$row[$field]=$factory($i);$values[]='('.implode(',',array_map(fn($field)=>$row[$field]===null?'NULL':$db->prepare('%s',(string)$row[$field]),$fields)).')';}sql($db,'INSERT INTO '.$table.' (`'.implode('`,`',$fields).'`) VALUES '.implode(',',$values));}
}
foreach([1000,10000] as $n){$db=new SourceBoundDb('c2c_source_bound_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);baseline_epoch_switch($db);$epoch=$db->get_row('SELECT * FROM '.$t->commerce_epochs().' ORDER BY id DESC LIMIT 1');$b=json_decode($db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()),true);$b['first_epoch_id']=(int)$epoch->id;$b['first_epoch_identity']=$epoch->identity;sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET baseline_contract=%s',json_encode($b)));
 $f=validation_order($db);$g=validation_order($db);$e=validation_receipt($db,$f,'evt_bound');
 $tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'bounds fixture owns commerce');check((new ProviderRegistry($db,$t))->bind('stripe_checkout','session','cs_bound',$f['claim'],gmdate('Y-m-d H:i:s'),null,null,'test')==='bound','bounds Session identity');sql($db,"UPDATE {$t->payment_sessions()} SET provider_session_id='cs_bound' WHERE id={$f['session']}");check($tx->commit(),'bounds fixture commits');
 $delete=new \RodolfoRizzo\Cpt2Collection\OrderDeletionService(new \RodolfoRizzo\Cpt2Collection\OrderDeletionRepository($db,$t),new \RodolfoRizzo\Cpt2Collection\OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new \RodolfoRizzo\Cpt2Collection\UtcClock());check($delete->delete($g['order'])->code()==='deleted','genuine deletion seed');
 $r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'s1_s');$legacy=$db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE id=1');
 // Synthetic pre-baseline filler added AFTER A1 only for access measurements.
 // These independently rolled-back batches do not establish a semantic seal.
 seal_fill($db,$t->payment_sessions(),$db->get_row('SELECT * FROM '.$t->payment_sessions().' LIMIT 1'),$n,['epoch_id'=>1,'active_slot'=>null],['reference'=>fn($i)=>md5('s'.$i),'order_id'=>fn($i)=>1000000+$i,'creation_key_hash'=>fn($i)=>hash('sha256','s'.$i),'provider_session_id'=>fn($i)=>'cs_filler_'.$i]);
 $seed=$db->get_row('SELECT * FROM '.$t->payment_events().' LIMIT 1');$payload=json_decode($seed->normalized_payload,true);$payload['epoch_identity']=$legacy;
 seal_fill($db,$t->payment_events(),$seed,$n,['epoch_id'=>null,'payment_session_id'=>null,'normalized_payload'=>json_encode($payload)],['provider_event_id'=>fn($i)=>'evt_filler_'.$i]);
 seal_fill($db,$t->provider_claims(),$db->get_row('SELECT * FROM '.$t->provider_claims().' LIMIT 1'),$n,['epoch_id'=>1,'epoch_identity'=>$legacy],['reference'=>fn($i)=>md5('c'.$i),'idempotency_key'=>fn($i)=>'c2c_ps_'.md5('c'.$i),'payment_session_id'=>fn($i)=>1000000+$i,'order_id'=>fn($i)=>1000000+$i]);
 seal_fill($db,$t->provider_identities(),$db->get_row('SELECT * FROM '.$t->provider_identities().' LIMIT 1'),$n,['observed_epoch_id'=>1,'claim_id'=>null,'binding_state'=>'unresolved'],['identity_value'=>fn($i)=>'identity_filler_'.$i]);
 seal_fill($db,$t->order_deletions(),$db->get_row('SELECT * FROM '.$t->order_deletions().' LIMIT 1'),$n,['epoch_id'=>1,'epoch_identity'=>$legacy],['original_order_id'=>fn($i)=>1000000+$i,'cart_id'=>fn($i)=>1000000+$i,'checkout_attempt_hash'=>fn($i)=>hash('sha256','d'.$i)]);
 foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$db->reads=[];
  foreach(['s1_s','s1_e','s1_c','s1_d','s1_i','s1_dc_0','z1_e_0_c','z1_e_0_d','z1_e_0_i','z1_e_0_dc_0'] as $cp)foreach([0,intdiv($n,2),$n+10] as $cursor){if(str_ends_with($cp,'dc_0'))$cursor=1;$post=str_starts_with($cp,'z1_');
   sql($db,$db->prepare('UPDATE '.$t->commerce_resets().' SET phase=%s,checkpoint=%s,`cursor`=%d WHERE id=%d',$post?'deleting':'terminalizing_events',$cp,$cursor,$r->id()));$fresh=validation_service($db)->resume($r->operation_identity())->operation();$tx=new TransactionManager($db,$t);$db->observe=true;check($tx->begin_reset_maintenance($fresh),'production batch owns exact synthetic access snapshot');$repo=new \RodolfoRizzo\Cpt2Collection\CommerceResetRepository($db,$t);$next=$post?$repo->certify_retained($fresh,1,gmdate('Y-m-d H:i:s')):$repo->retire($fresh,1,gmdate('Y-m-d H:i:s'));check($next!==false&&$next->status()==='running','actual source-seal batch completes');check($tx->rollback(),'access batch rolls back');$db->observe=false;
  }
  $max=0;$keys=[];foreach(array_unique($db->reads) as $q){if(str_contains($q,'information_schema.'))continue;if(!str_starts_with($q,'SELECT ')||!str_contains($q,$db->prefix.'c2c_'))continue;$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$count=seal_examined($plan);$max=max($max,$count);check($count<=2,'actual production query examines <=2 rows independently of filler');$json=json_encode($plan);check(!str_contains($json,'filesort')&&!str_contains($json,'temporary_table'),'no filesort or temporary materialization');
   $walk=function(array $p)use(&$walk,&$keys):void{foreach($p as $k=>$v){if($k==='table'&&is_array($v)){$keys[$v['table_name']??'?'][$v['key']??($v['access_type']??'?')]=true;check(($v['access_type']??'')!=='ALL','no production full-table scan');}elseif(is_array($v))$walk($v);}};$walk($plan);
  }
  check($max>0&&count($keys)>=6,'captured actual production queries and nonempty execution plans');
  echo 'EVIDENCE: filler='.$n.' each Session/receipt/claim/identity/deletion stream; cursors before/middle/after; index_merge='.$merge.'; batch=1; max examined='.$max.'; indexes='.json_encode($keys)."\n";
 }
}finally{baseline_cleanup($db);}}
echo "All source-seal production access-bound checks passed.\n";
