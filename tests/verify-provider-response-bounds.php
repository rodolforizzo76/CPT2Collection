<?php
/** Actual integrated SQL at growing retained history, both optimizer settings. */
declare(strict_types=1);
require __DIR__.'/support/provider-response.php';
require_once __DIR__.'/support/provider-evidence-bounds.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock};
function response_analyze(wpdb $db,array $queries,string $label):void{
 $t=new TableNames($db);$report=[];
 foreach(array_unique($queries) as $q){
  if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
  $ownership=false;foreach(['commerce_control','commerce_epochs','commerce_resets','provider_claims','provider_identities','order_deletions'] as $m)if(str_contains($q,'FROM '.$t->$m().' '))$ownership=true;
  if(!$ownership)continue;
  check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR '),'integrated ownership has no OFFSET/OR');
  $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);$rows=0;
  foreach($nodes as $node){$constant=in_array($node['access_type']??'',['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','integrated ownership uses bounded index');}
  $bound=str_contains($q,'LIMIT 2')?2:1;check($rows<=$bound&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'integrated actual ownership rows '.$rows.' <= '.$bound);
  $report[]=['query'=>$q,'rows'=>$rows,'indexes'=>array_column($nodes,'key')];
 }
 echo 'B_SQL: '.$label.' statements='.count($queries).' data_selects='.count(array_filter($queries,fn($q)=>str_starts_with($q,'SELECT ')&&!str_contains($q,'information_schema.'))).' '.json_encode($report)."\n";
}
foreach([1000,10000] as $n){$db=new ResponseDb('c2c_b_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{
  validation_schema($db);$old=validation_order($db);$old_capture=response_captured($db,$old);discovery_bind($db,$old,'session','cs_gap_'.$old['reference']);discovery_bind($db,$old,'payment','pi_'.$old['reference']);
  check((new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock()))->delete($old['order'])->code()==='deleted','bounds real deletion');discovery_finish($db,validation_start($db));$current=validation_order($db);$current_capture=response_captured($db,$current);
  foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $m=>$kind)discovery_fill($db,$t->$m(),get_object_vars($db->get_row('SELECT * FROM '.$t->$m().' ORDER BY id LIMIT 1')),$n,$kind);
  foreach(['ready','resetting'] as $state){if($state==='resetting')validation_start($db);
   foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");
	foreach(['old_duplicate','old_insert','current','recover_commit','recover_rollback'] as $route){$capture=$route==='current'?$current_capture:$old_capture;$connections=[];
	 $p=new ModernBoundaryProvider();if(in_array($route,['old_insert','recover_commit','recover_rollback'],true))$p=new class implements \RodolfoRizzo\Cpt2Collection\PaymentProviderInterface{public function name():string{return 'stripe_checkout';}public function create_session(\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest $r):\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult{return new \RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult('cs_new_'.bin2hex(random_bytes(4)),'https://example.test/pay',$r->reservation_deadline(),'pi_new_'.bin2hex(random_bytes(4)));}};
	 $svc=response_service($db,$p,$connections,function(ResponseDb $c,int $number)use($merge,$route){sql($c,"SET SESSION optimizer_switch='index_merge=$merge'");$c->captured=[];if($c instanceof ResponseFaultDb&&$number===1){$c->mode='commit';$c->executed=$route==='recover_commit';}},str_starts_with($route,'recover_')?fn($prefix)=>new ResponseFaultDb($prefix):null);$db->capture=true;$db->captured=[];$out=response_resume($svc,$capture);$db->capture=false;
	 if(str_starts_with($route,'recover_'))check(count($connections)===2,'integrated optional recovery uses separate fresh connection');
	 check($route!=='current'||$state!=='ready'||$out->is_success()||$out->get_code()==='payment_session_reconciliation_required','bounded current result');
	 $queries=$db->captured;foreach($connections as $c)$queries=array_merge($queries,$c->captured);response_analyze($db,$queries,"fillers=$n state=$state merge=$merge route=$route");
	}
   }
  }
  foreach(['provider_claims'=>'payment_session_id','order_deletions'=>'original_order_id'] as $m=>$key){
   if($m==='provider_claims')sql($db,'UPDATE '.$t->$m().' SET payment_session_id='.$old['session'].' WHERE id>=100');else sql($db,'UPDATE '.$t->$m().' SET installation_id=MD5(CAST(id AS CHAR)),original_order_id='.$old['order'].' WHERE id>=100');
   foreach(['on','off'] as $merge){$connections=[];$svc=response_service($db,new ModernBoundaryProvider(),$connections,function(ResponseDb $c)use($merge){sql($c,"SET SESSION optimizer_switch='index_merge=$merge'");$c->captured=[];});$out=response_resume($svc,$old_capture);check(!$out->is_success(),'integrated ambiguity never chooses owner');
	foreach($connections[0]->captured as $q)if(str_starts_with($q,'SELECT id FROM '.$t->$m().' FORCE INDEX('.$key.')')){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);check(discovery_nodes($plan)[0]['r_rows']===2,'integrated 1k/10k matching ambiguity examines exactly two');}
   }
   if($m==='provider_claims')sql($db,'UPDATE '.$t->$m().' SET payment_session_id=20000+(id-100) DIV 3 WHERE id>=100');
  }
 }finally{baseline_cleanup($db);}
}
echo "All integrated synchronous B access bounds passed.\n";
