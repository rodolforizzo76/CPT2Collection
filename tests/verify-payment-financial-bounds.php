<?php
/** D3 captured admission/recovery/probe ownership SQL at 1k/10k, index_merge on/off. */
declare(strict_types=1);
require __DIR__.'/support/payment-financial-continuation.php';
require_once __DIR__.'/support/provider-evidence-bounds.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
class D3ProbeLossDb extends D2Db {
 public bool $lose_probe=false;
 public function query(string $q):int|false {if($this->lose_probe&&$q==='ROLLBACK'&&TransactionManager::admitted_on($this)){$this->last_error='lost read-only probe end';$this->lose_probe=false;return false;}return parent::query($q);}
}
function d3_analyze(D2Db $db,array $queries,string $label):void {
 $t=new TableNames($db);$max=0;$count=0;
 foreach(array_unique($queries) as $q){
  if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
  $retained=false;foreach(['commerce_control','commerce_epochs','commerce_resets','provider_claims','provider_identities','order_deletions'] as $m)if(str_contains($q,'FROM '.$t->$m().' '))$retained=true;
  if(!$retained)continue;
  check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR '),'D3 bounded retained selector has no OFFSET/OR');check(!preg_match('/provider_claims.*WHERE order_id\s*=/',$q),'D3 ownership never selected by order alone');
  $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$rows=0;
  foreach(discovery_nodes($plan) as $node){$constant=in_array($node['access_type']??'',['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','D3 retained query uses index');}
  $bound=str_contains($q,'LIMIT 2')?2:1;check($rows<=$bound&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'D3 retained candidate bound '.$bound.' without filesort/temporary');$max=max($rows,$max);++$count;
 }
 echo 'D3_SQL '.$label.' statements='.count($queries).' retained_distinct='.$count.' max_rows='.$max."\n";
}
foreach([1000,10000] as $n){
 $prefix='c2c_d3_bounds_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);
 try{
  validation_schema($db);sql($db,"INSERT INTO {$db->posts} VALUES(78,'collection_item')");sql($db,"INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(78,'_cpt2collection_stock_quantity','20')");
  $old=validation_order($db);discovery_bind($db,$old,'session','cs_d3_old');d1_delete($db,$old);discovery_finish($db,validation_start($db));$t=new TableNames($db);
  foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $m=>$kind)discovery_fill($db,$t->$m(),get_object_vars($db->get_row('SELECT * FROM '.$t->$m().' ORDER BY id LIMIT 1')),$n,$kind);
  foreach(['on','off'] as $optimizer)foreach(['conversion','expiration','processed_replay'] as $stage){
   sql($db,"SET optimizer_switch='index_merge=$optimizer'");$f=validation_order($db);webhook_activate($db,$f);[$e,$op]=d3_event($db,$f,$stage==='expiration'?'checkout.session.expired':'checkout.session.completed','evt_d3_'.$optimizer.'_'.$stage);
   if($stage==='processed_replay'){check(d3_run($db,'conversion',$f,$e,$op)->is_success(),'bounded processed sale commits');$e=d2_event($db,$e);$db->close();$db=new D3ProbeLossDb($prefix);$db->lose_probe=true;}else {$db->fault='COMMIT';$db->after=true;$db->faulted=false;}
   $fresh=[];$db->capture=true;$db->captured=[];
   $out=d3_run($db,$stage,$f,$e,$op,$fresh,function($c)use($optimizer){sql($c,"SET optimizer_switch='index_merge=$optimizer'");});$queries=$db->captured;
   check($out->inspection?->status==='logically_completed','bounded fresh '.$stage.' verifies full logical completion');$inspection=$fresh[0]->captured;
   foreach($fresh as $c)d2_no_dml($c);$db=new D2Db($prefix);sql($db,"SET optimizer_switch='index_merge=$optimizer'");d3_analyze($db,$queries,"$n/$optimizer/$stage/admission");d3_analyze($db,$inspection,"$n/$optimizer/$stage/inspection");
  }
  sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id='.$f['session'].' WHERE id>=100 AND id<100000');
  sql($db,'UPDATE '.$t->order_deletions().' SET installation_id=MD5(id), original_order_id='.$f['order'].' WHERE id>=100');
  foreach(['on','off'] as $optimizer){
   sql($db,"SET optimizer_switch='index_merge=$optimizer'");check((new \RodolfoRizzo\Cpt2Collection\CommittedOperationAuthority($db,$t))->classify($op)->status==='ambiguous','D3 positive ambiguity with '.$n.' matching rows/'.$optimizer);
   foreach([$db->prepare('SELECT * FROM '.$t->provider_claims().' FORCE INDEX(payment_session_id) WHERE payment_session_id=%d ORDER BY id LIMIT 2',$f['session']),$db->prepare('SELECT * FROM '.$t->order_deletions().' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2',$f['order'])] as $q){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);check(count($db->get_results($q))===2&&(float)array_sum(array_map(fn($x)=>($x['r_rows']??0)*($x['r_loops']??1),discovery_nodes($plan)))===2.0,'D3 populated ambiguity sentinel examines exactly two rows');}
  }
 }finally{d3_cleanup($db);}
}
echo "D3 integrated retained SQL bounds passed.\n";
