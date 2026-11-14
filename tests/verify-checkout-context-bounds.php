<?php
/** Phase 4.7 actual new retained queries: indexed 1k/10k histories, optimizer on/off. */
declare(strict_types=1);
require __DIR__.'/support/checkout-context.php';
require_once __DIR__.'/support/provider-evidence-bounds.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
function c47_analyze(D4Db $db,array $queries,string $label):void {
 $t=new TableNames($db);$plans=[];
 foreach(array_unique($queries) as $q){
  if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
  $retained=false;foreach(['commerce_control','commerce_epochs','commerce_resets','provider_claims','provider_identities','order_deletions'] as $m)if(str_contains($q,'FROM '.$t->$m().' '))$retained=true;
  if(!$retained)continue;
  // Existing cart/attempt replay barrier is a scoped operational-family read, not historical owner discovery.
  if(str_contains($q,'WHERE epoch_id = ')&&str_contains($q,'AND cart_id = ')){check(!str_contains($q,'OFFSET'),'existing cart replay barrier has no OFFSET');continue;}
  check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR '),'47 retained ownership has no OFFSET/OR substitute');
  $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);$rows=0;
  foreach($nodes as $node){check(($node['access_type']??'')!=='ALL','47 retained point uses an index');$rows+=in_array($node['access_type']??'',['const','system'],true)?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);}
  check($rows<=(str_contains($q,'LIMIT 2')?2:1)&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'47 retained candidate bound without filesort/temporary');$plans[]=['query'=>$q,'rows'=>$rows,'indexes'=>array_column($nodes,'key')];
 }
 echo 'C47_SQL '.json_encode(['route'=>$label,'statements'=>count($queries),'plans'=>$plans])."\n";
}
foreach([1000,10000] as $n){$db=new D4Db('c2c_47_bounds_'.bin2hex(random_bytes(4)).'_');try{
 validation_schema($db);$old=validation_order($db,1);discovery_bind($db,$old,'session','cs_47_bound');check(c47_delete($db,$old['order'])->code()==='deleted','47 bound fixture genuine deletion');discovery_finish($db,validation_start($db));$t=new TableNames($db);
 foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $m=>$kind)discovery_fill($db,$t->$m(),get_object_vars($db->get_row('SELECT * FROM '.$t->$m().' ORDER BY id LIMIT 1')),$n,$kind);
 $f=c47_new($db);
 foreach(['on','off'] as $merge){sql($db,"SET optimizer_switch='index_merge=$merge'");$db->capture=true;$db->captured=[];check(c47_delete($db,$old['order'])->code()==='already_deleted','47 bounded historical D remains positive');$queries=$db->captured;c47_no_dml($db);$db->capture=false;c47_analyze($db,$queries,"$n/$merge/historical_delete");
  $db->capture=true;$db->captured=[];$context=c47_token($db,$f);$queries=$db->captured;c47_no_dml($db);$db->capture=false;c47_analyze($db,$queries,"$n/$merge/context_issue");
  $db->capture=true;$db->captured=[];$prepared=c47_prepare($db,$f,str_repeat('a',32),$context['token']);check($prepared->is_success(),'47 bounded current checkout remains functional');$queries=$db->captured;$db->capture=false;c47_analyze($db,$queries,"$n/$merge/current_checkout");
  $connections=[];$provider=new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider(new ModernBoundaryProvider(),new \RodolfoRizzo\Cpt2Collection\UtcClock());$db->capture=true;$db->captured=[];check(response_service($db,$provider,$connections)->create($prepared->get_order_id(),str_repeat('b',32),$prepared->commerce_context(),$prepared->browser_context()['token'])->is_success(),'bounded signed payment claim/replay remains functional');$queries=$db->captured;$db->capture=false;c47_analyze($db,$queries,"$n/$merge/payment_claim");
 }
 sql($db,'UPDATE '.$t->order_deletions().' SET installation_id=MD5(id),original_order_id='.$old['order'].' WHERE id>=100');
 foreach(['on','off'] as $merge){sql($db,"SET optimizer_switch='index_merge=$merge'");$db->capture=true;$db->captured=[];check(c47_delete($db,$old['order'])->code()==='evidence_conflict','47 global ambiguity never selects first historical owner');$queries=$db->captured;c47_no_dml($db);$db->capture=false;
  foreach($queries as $q)if(str_starts_with($q,'SELECT ')&&str_contains($q,'FORCE INDEX(original_order_id)')){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);check((float)discovery_nodes($plan)[0]['r_rows']===2.0,'47 populated global ambiguity examines exactly two candidates');}
  c47_analyze($db,$queries,"$n/$merge/ambiguity");
 }
 sql($db,'ALTER TABLE '.$t->order_deletions().' DROP KEY original_order_id');$db->capture=true;$db->captured=[];check(c47_delete($db,$old['order'])->code()==='database_error','missing required global index fails closed without scan fallback');c47_no_dml($db);
}finally{d3_cleanup($db);}}
echo "47 indexed history bounds passed.\n";
