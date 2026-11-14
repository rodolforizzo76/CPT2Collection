<?php
/** D2 captured production SQL: retained 1k/10k distractors and index_merge on/off. */
declare(strict_types=1);
require __DIR__.'/support/payment-event-continuation.php';
require_once __DIR__.'/support/provider-evidence-bounds.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,StripeEventNormalizer,CommittedOperation,ProviderEvidenceRouter,PaymentEventRepository};
function d2_analyze(D2Db $db,array $queries,string $label):void {
 $t=new TableNames($db);$max=0;$count=0;
 foreach(array_unique($queries) as $q){
  if(!str_starts_with($q,'SELECT ')||str_contains($q,'information_schema.'))continue;
  $retained=false;foreach(['commerce_control','commerce_epochs','commerce_resets','provider_claims','provider_identities','order_deletions'] as $m)if(str_contains($q,'FROM '.$t->$m().' '))$retained=true;
  if(!$retained)continue;
  check(!str_contains($q,'OFFSET')&&!str_contains($q,' OR '),'D2 bounded selector has no OFFSET/OR');
  check(!preg_match('/provider_claims.*WHERE order_id\s*=/',$q),'D2 never selects ownership by order alone');
  $plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$rows=0;
  foreach(discovery_nodes($plan) as $node){$constant=in_array($node['access_type']??'',['const','system'],true);$rows+=$constant?max(1,$node['r_rows']??0)*max(1,$node['r_loops']??0):($node['r_rows']??0)*($node['r_loops']??1);check(($node['access_type']??'')!=='ALL','D2 retained query uses an index');}
  $bound=str_contains($q,'LIMIT 2')?2:1;check($rows<=$bound&&!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'D2 canonical retained rows <= '.$bound.' without filesort/temporary');$max=max($rows,$max);++$count;
 }
 echo 'D2_SQL '.$label.' statements='.count($queries).' retained_distinct='.$count.' max_rows='.$max."\n";
}
foreach([1000,10000] as $n){
 $prefix='c2c_d2_bounds_'.bin2hex(random_bytes(4)).'_';$db=new D2Db($prefix);
 try {
  validation_schema($db);$old=validation_order($db);discovery_bind($db,$old,'session','cs_d2_old');d1_delete($db,$old);discovery_finish($db,validation_start($db));$f=validation_order($db);$t=new TableNames($db);
  foreach(['commerce_epochs'=>'epochs','commerce_resets'=>'resets','provider_claims'=>'claims','provider_identities'=>'identities','order_deletions'=>'deletions'] as $m=>$kind)discovery_fill($db,$t->$m(),get_object_vars($db->get_row('SELECT * FROM '.$t->$m().' ORDER BY id LIMIT 1')),$n,$kind);
  foreach(['on','off'] as $optimizer)foreach(['association','ignored','attention','quarantine_processing','integrity_conflict'] as $stage){
   sql($db,"SET optimizer_switch='index_merge=$optimizer'");
   $p=modern_payload($f['reference'],'evt_d2_'.$optimizer.'_'.$stage,$f['installation'],$f['epoch']);$p['data']['object']['id']='cs_d2_bounds';$p['data']['object']['payment_intent']='pi_d2_bounds';$p['data']['object']['amount_total']=200;
   $receipt=(new StripeEventNormalizer())->normalize($p,hash('sha256',json_encode($p)))->receipt();$op=CommittedOperation::authenticated_receipt(discovery_claim($db,$f),$receipt,gmdate('Y-m-d H:i:s'));
   check((new ProviderEvidenceRouter($db,$t))->retain($op->candidate)->status==='retained','bounded fixture retains authenticated exact evidence');$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'bounded receipt authority');$e=(new PaymentEventRepository($db,$t))->insert_complete_receipt($receipt,gmdate('Y-m-d H:i:s'))->event();check($tx->commit(),'bounded receipt commits');
   if($stage!=='association')$e=d2_associate($db,$e,$op);
   if($stage==='quarantine_processing')$e=d2_processing($db,$e);
   if($stage==='integrity_conflict'){[, $o]=d2_services($db);check($o->ignore($e,'original_terminal',$op)->is_accepted(),'bounded terminal source');$e=d2_event($db,$e);}
   $fresh=[];[$a,$o]=d2_services($db,$fresh);$db->capture=true;$db->captured=[];$db->write_number=0;$db->wrote=false;$db->fault='COMMIT';$db->faulted=false;$db->after=true;
   $out=$stage==='association'?$a->associate($e,$op):d2_outcome($o,$stage,$e,$op);$queries=$db->captured;
   check($out->inspection?->status==='logically_completed','bounded fresh '.$stage.' inspection proves full logical postcondition');
   $recovery=$fresh[0]->captured;$db=new D2Db($prefix);sql($db,"SET optimizer_switch='index_merge=$optimizer'");
   d2_analyze($db,$queries,"$n/$optimizer/$stage/admission");d2_analyze($db,$recovery,"$n/$optimizer/$stage/inspection");
  }
  // Populated matching ranges, not just sparse distractors: two-row sentinels.
  sql($db,'UPDATE '.$t->provider_claims().' SET payment_session_id='.$f['session'].' WHERE id>=100');
  sql($db,'UPDATE '.$t->order_deletions().' SET installation_id=MD5(id), original_order_id='.$f['order'].' WHERE id>=100');
  foreach(['on','off'] as $optimizer){sql($db,"SET optimizer_switch='index_merge=$optimizer'");check((new PaymentEventRepository($db,$t))->continuation()->classify($op)->status==='ambiguous','D2 positively detects retained ambiguity among matching distractors: '.$n.'/'.$optimizer);foreach([$db->prepare('SELECT * FROM '.$t->provider_claims().' FORCE INDEX(payment_session_id) WHERE payment_session_id=%d ORDER BY id LIMIT 2',$f['session']),$db->prepare('SELECT * FROM '.$t->order_deletions().' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2',$f['order'])] as $q){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$nodes=discovery_nodes($plan);check(count($db->get_results($q))===2&&(float)array_sum(array_map(fn($x)=>($x['r_rows']??0)*($x['r_loops']??1),$nodes))===2.0,'D2 matching '.$n.' candidates use exactly two-row ambiguity sentinel: '.$optimizer);}}
 }finally{if($db->closed)$db=new D2Db($prefix);baseline_cleanup($db);}
}
echo "D2 integrated retained bounds passed.\n";
