<?php
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
require_once __DIR__.'/support/provider-evidence-bounds.php';
require_once dirname(__DIR__).'/includes/class-provider-evidence-router.php';
use RodolfoRizzo\Cpt2Collection\{ProviderEvidenceRouter,ProviderDiscoveryCandidate as I,ProviderClaimLocator as L,TableNames,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,TransactionManager,UtcClock};
foreach([1000,10000] as $n){$db=new DiscoveryDb('c2c_a1_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try {
  validation_schema($db);$f=validation_order($db);discovery_bind($db,$f,'session','cs_discovery');discovery_bind($db,$f,'payment','pi_discovery');discovery_bind($db,$f,'event','evt_discovery',hash('sha256','body'),'checkout.session.completed');
  $del=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock());check($del->delete($f['order'])->code()==='deleted','bounded genuine deletion');$claim=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$done=discovery_finish($db,validation_start($db));
  foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $m=>$kind)discovery_fill($db,$t->$m(),get_object_vars($db->get_row('SELECT * FROM '.$t->$m().' ORDER BY id LIMIT 1')),$n,$kind);
  $inputs=[];foreach([L::reference($f['reference']),L::id($f['claim']),L::idempotency($claim->idempotency_key),L::attempt($f['installation'],(int)$claim->epoch_id,$f['order'],$claim->creation_key_hash),L::payment_session($f['session'])] as $l)$inputs[]=I::correlated('stripe_checkout','test',$l,['session'=>'cs_discovery','payment'=>'pi_discovery'],gmdate('Y-m-d H:i:s'));
  $inputs[]=discovery_webhook($f,payment:'pi_discovery');
  foreach(['ready','resetting'] as $state){if($state==='resetting')$active=validation_start($db);
   foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$queries=[];
	foreach($inputs as $input){$db->capture=true;$db->captured=[];$out=(new ProviderEvidenceRouter($db,$t))->retain($input);$db->capture=false;check($out->status==='duplicate','bounded A1 admission');$queries=array_merge($queries,$db->captured);echo 'ROUTE_COST: filler='.$n.' state='.$state.' index_merge='.$merge.' keys='.count($input->keys).' statements='.count($db->captured).' data_selects='.count(array_filter($db->captured,fn($q)=>str_starts_with($q,'SELECT ')&&!str_contains($q,'information_schema.')))."\n";}
	$db->capture=true;$db->captured=[];$out=(new ProviderEvidenceRouter($db,$t))->retain(discovery_input($db,$f,'cs_new_'.$state.'_'.$merge));$db->capture=false;check($out->status==='retained','bounded insert path');$queries=array_merge($queries,$db->captured);$report=[];
	foreach(array_unique($queries) as $q){
	 if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
	 check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR '),'no ownership OFFSET/OR');
	 $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);$rows=0;
	 foreach($nodes as $node){$constant=in_array($node['access_type']??'',['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','no full data scan');}
	 $bound=str_contains($q,'LIMIT 2')?2:1;check($rows<=$bound&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'actual bounded A1 query rows '.$rows.' <= '.$bound);
	 $report[]=['query'=>$q,'indexes'=>array_column($nodes,'key'),'rows'=>$rows,'bound'=>$bound];
	}
	foreach(array_unique($queries) as $q)if(str_starts_with($q,'UPDATE ')){check(str_contains($q,'WHERE `id`=')||str_contains($q,'WHERE id='),'every update uses exact validated primary key');$report[]=['write'=>$q,'bound'=>1];}
	echo 'EVIDENCE: filler='.$n.' state='.$state.' index_merge='.$merge.' '.json_encode($report)."\n";
   }
  }

  foreach(['commit','rollback'] as $recovery_case){
   if($recovery_case==='commit'){$db->fault='after';$out=(new ProviderEvidenceRouter($db,$t))->retain(discovery_input($db,$f,'cs_bounds_recovery'));}
   else{
	$input=I::response(discovery_claim($db,$f),'cs_bounds_rollback','pi_bounds_rollback',gmdate('Y-m-d H:i:s'));
	$db->failure='cs_bounds_rollback';$db->bookkeeping_failure='ROLLBACK';
	$out=(new ProviderEvidenceRouter($db,$t))->retain($input);
   }
   check($out->status==='transaction_outcome_unknown'&&$out->recovery!==null&&$out->mutation_possible,'bounded recovery descriptor for '.$recovery_case);
   $ticket=$out->recovery;$prefix=$db->prefix;$db->close();$db=new DiscoveryDb($prefix);$t=new TableNames($db);
  foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$db->capture=true;$db->captured=[];$out=(new ProviderEvidenceRouter($db,$t))->recover($ticket);$db->capture=false;check($out->status===($recovery_case==='commit'?'already_present':'reentry_required'),'fresh recovery under 1k/10k fillers');$report=[];
   foreach(array_unique($db->captured) as $q){if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);$rows=0;
	foreach($nodes as $node){$constant=in_array($node['access_type']??'',['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','recovery no full data scan');}
	check($rows<=(str_contains($q,'LIMIT 2')?2:1)&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'bounded locked recovery SQL');$report[]=['query'=>$q,'rows'=>$rows,'indexes'=>array_column($nodes,'key')];
   }
   echo 'RECOVERY_EVIDENCE: case='.$recovery_case.' filler='.$n.' index_merge='.$merge.' statements='.count($db->captured).' '.json_encode($report)."\n";
  }
  }
  foreach(['provider_claims'=>'payment_session_id','order_deletions'=>'original_order_id'] as $m=>$key){
   if($m==='provider_claims')sql($db,'UPDATE '.$t->$m().' SET payment_session_id='.$f['session'].' WHERE id>=100');else sql($db,'UPDATE '.$t->$m().' SET installation_id=MD5(CAST(id AS CHAR)),original_order_id='.$f['order'].' WHERE id>=100');
   foreach(['on','off'] as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=$merge'");$db->capture=true;$db->captured=[];$out=(new ProviderEvidenceRouter($db,$t))->retain($inputs[0]);$db->capture=false;check($out->status==='corrupt'||$out->status==='ambiguous','matching duplicate range refuses authority');
	foreach($db->captured as $q)if(str_starts_with($q,'SELECT id FROM '.$t->$m().' FORCE INDEX('.$key.')')){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);check($nodes[0]['r_rows']===2,'1k/10k matching duplicates examine exactly two');}
   }
   if($m==='provider_claims')sql($db,'UPDATE '.$t->$m().' SET payment_session_id=20000+(id-100) DIV 3 WHERE id>=100');
  }
 }finally{baseline_cleanup($db);}
}
echo "All A1 retained access bounds passed.\n";
