<?php
/** D2 ordinary transitions, exact provenance, immutable identity and terminal guards. */
declare(strict_types=1);
require __DIR__.'/support/payment-event-continuation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommittedOperation,PaymentEventRecord,PaymentSessionRepository,TransactionManager};
foreach(['association','ignored','attention','quarantine_processing','integrity_conflict'] as $stage){
 $db=new D2Db('c2c_d2_core_'.bin2hex(random_bytes(4)).'_');
 try {
  [$f,$e,$op]=d2_fixture($db);[$a,$o,$tx]=d2_services($db);$db->capture=true;$db->captured=[];
  $out=$stage==='association'?$a->associate($e):d2_outcome($o,$stage,$e,null);
  check(($stage==='association'?$out->outcome():$out->code())==='continuation_verification_required','missing original operation never authorizes '.$stage);d2_no_dml($db);$db->capture=false;
  if($stage!=='association')$e=d2_associate($db,$e,$op);
  if($stage==='quarantine_processing')$e=d2_processing($db,$e);
  if($stage==='integrity_conflict'){check($o->ignore($e,'original_terminal',$op)->is_accepted(),'legitimate terminal established');$e=d2_event($db,$e);}
  $before=baseline_snapshot($db);$row=$db->get_row('SELECT * FROM '.(new TableNames($db))->payment_events().' WHERE id='.$e->id());$row->payload_sha256=hash('sha256','contradictory snapshot');$bad=PaymentEventRecord::from_row($row);
  $db->capture=true;$db->captured=[];$out=$stage==='association'?$a->associate($bad,$op):d2_outcome($o,$stage,$bad,$op);check(($stage==='association'?$out->outcome():$out->code())==='continuation_verification_required'&&baseline_snapshot($db)===$before,'contradictory payload snapshot rejected for '.$stage);d2_no_dml($db);$db->capture=false;
  $out=$stage==='association'?$a->associate($e,$op):d2_outcome($o,$stage,$e,$op);
  check($stage==='association'?$out->outcome()==='associated':$out->is_accepted(),'ordinary exact '.$stage.' succeeds');
  $fresh=d2_event($db,$e);$out=$stage==='association'?$a->associate($fresh,$op):d2_outcome($o,$stage,$fresh,$op);
  check($stage==='association'?$out->outcome()==='already_associated':$out->is_accepted(),'coherent duplicate '.$stage.' is safe');
  check(!$tx->is_active(),'D2 stage ends its transaction');
  if($stage==='integrity_conflict')check($fresh->processing_status()==='ignored'&&$fresh->result_code()==='original_terminal','integrity alarm preserves legitimate terminal receipt');
  if($stage!=='association'){$snapshot=serialize(d2_event($db,$e));$out=$o->attention(d2_event($db,$e),'different_terminal',operation:$op);check($out->is_accepted()&&serialize(d2_event($db,$e))===$snapshot,'terminal Event never rewritten');}
 } finally {baseline_cleanup($db);}
}
foreach(['other_attempt','foreign_epoch','missing_claim','ambiguous','cross_owner','identity_environment','identity_hash','missing_event_identity','terminal_evidence_nonterminal_receipt','deleted','reset','retired'] as $mode){
 $db=new D2Db('c2c_d2_owner_'.bin2hex(random_bytes(4)).'_');try{
  [$f,$e,$op]=d2_fixture($db);$t=new TableNames($db);
  if($mode==='other_attempt'){$other=d1_second_attempt($db,$f);$op=CommittedOperation::native_request(discovery_claim($db,$other),gmdate('Y-m-d H:i:s'));}
  elseif($mode==='foreign_epoch'){$row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$row->epoch_id=999;$op=CommittedOperation::authenticated_receipt(RodolfoRizzo\Cpt2Collection\ProviderClaimRecord::from_row($row),d2_receipt($e),gmdate('Y-m-d H:i:s'));}
  elseif($mode==='missing_claim')sql($db,'DELETE FROM '.$t->provider_claims().' WHERE id='.$f['claim']);
  elseif($mode==='ambiguous'){$row=get_object_vars($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']));$row['id']=99;$row['reference']=md5('dup');$row['creation_key_hash']=hash('sha256','dup');$row['idempotency_key']='c2c_ps_'.$row['reference'];$r=json_decode($row['request_snapshot'],true);$r['reference']=$row['reference'];$r['idempotency_key']=$row['idempotency_key'];$row['request_snapshot']=json_encode($r);check($db->insert($t->provider_claims(),$row,array_fill(0,count($row),'%s'))===1,'ambiguous exact Session fixture');}
  elseif($mode==='cross_owner'){$other=d1_second_attempt($db,$f);sql($db,'UPDATE '.$t->provider_identities().' SET claim_id='.$other['claim']." WHERE identity_kind='event'");}
  elseif($mode==='identity_environment')sql($db,'UPDATE '.$t->provider_identities()." SET environment='live' WHERE identity_kind='event'");
  elseif($mode==='identity_hash')sql($db,'UPDATE '.$t->provider_identities()." SET payload_sha256=REPEAT('d',64) WHERE identity_kind='event'");
  elseif($mode==='terminal_evidence_nonterminal_receipt')sql($db,'UPDATE '.$t->provider_identities()." SET disposition='processed' WHERE identity_kind='event'");
  elseif($mode==='missing_event_identity')sql($db,'DELETE FROM '.$t->provider_identities()." WHERE identity_kind='event'");
  elseif($mode==='deleted')d1_delete($db,$f);
  elseif($mode==='reset')validation_start($db);
  else discovery_finish($db,validation_start($db));
  [$a,$o]=d2_services($db);$before=baseline_snapshot($db);$db->capture=true;$db->captured=[];
  $result=$a->associate($e,$op);check(!in_array($result->outcome(),['associated','already_associated'],true),'non-authorizing association '.$mode);
  foreach(['ignored','attention','quarantine_processing','integrity_conflict'] as $stage)d2_outcome($o,$stage,$e,$op);
  check(baseline_snapshot($db)===$before,'historical/inconsistent original owner preserves all durable state: '.$mode);d2_no_dml($db);$db->capture=false;
 }finally{baseline_cleanup($db);}
}
foreach(['ignored','attention'] as $stage){
 $db=new D2Db('c2c_d2_unbound_'.bin2hex(random_bytes(4)).'_');try{[$f,$e,$op]=d2_fixture($db);[, $o]=d2_services($db);check(d2_outcome($o,$stage,$e,$op)->is_accepted(),'exact outcome before association: '.$stage);$after=d2_event($db,$e);check($after->payment_session_id()===null&&$after->ownership_state()==='unresolved','preassociation outcome does not manufacture association');}finally{baseline_cleanup($db);}
}
foreach(['provider','provider_session_id','provider_payment_id','epoch_id','payment_session_id','normalized_payload'] as $field){
 $db=new D2Db('c2c_d2_fields_'.bin2hex(random_bytes(4)).'_');try{[$f,$e,$op]=d2_fixture($db);$t=new TableNames($db);$row=$db->get_row('SELECT * FROM '.$t->payment_events().' WHERE id='.$e->id());$row->$field=match($field){'provider'=>'different_provider','provider_session_id'=>'cs_other','provider_payment_id'=>'pi_other','epoch_id','payment_session_id'=>999,default=>'{"installation_id":"contradiction"}'};$bad=PaymentEventRecord::from_row($row);[$a,$o]=d2_services($db);$db->capture=true;$db->captured=[];check($a->associate($bad,$op)->outcome()==='continuation_verification_required'&&!$o->ignore($bad,'mismatch',$op)->is_accepted(),'supplied '.$field.' cannot substitute original immutable provenance');d2_no_dml($db);$db->capture=false;}finally{baseline_cleanup($db);}
}
$db=new D2Db('c2c_d2_attempts_'.bin2hex(random_bytes(4)).'_');try{
 [$f,$e,$op]=d2_fixture($db);$g=d1_second_attempt($db,$f);$t=new TableNames($db);$p=modern_payload($g['reference'],'evt_second_exact',$g['installation'],$g['epoch']);$p['data']['object']['id']='cs_second_exact';$p['data']['object']['payment_intent']='pi_second_exact';$p['data']['object']['amount_total']=200;
 $receipt=(new RodolfoRizzo\Cpt2Collection\StripeEventNormalizer())->normalize($p,hash('sha256',json_encode($p)))->receipt();$second=CommittedOperation::authenticated_receipt(discovery_claim($db,$g),$receipt,gmdate('Y-m-d H:i:s'));check((new RodolfoRizzo\Cpt2Collection\ProviderEvidenceRouter($db,$t))->retain($second->candidate)->status==='retained','second exact attempt retains its own provider identities');$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'second exact receipt admission');$other=(new RodolfoRizzo\Cpt2Collection\PaymentEventRepository($db,$t))->insert_complete_receipt($receipt,gmdate('Y-m-d H:i:s'))->event();check($tx->commit(),'second exact receipt commits');
 [$a]=d2_services($db);check($a->associate($other,$op)->outcome()==='continuation_verification_required','same order never substitutes a different native attempt');check($a->associate($other,$second)->outcome()==='associated'&&d2_event($db,$other)->payment_session_id()===$g['session'],'second attempt associates only to its exact local Session');check($a->associate($e,$op)->outcome()==='associated'&&d2_event($db,$e)->payment_session_id()===$f['session'],'original attempt remains independently exact');
}finally{baseline_cleanup($db);}

echo "D2 exact Event continuation checks passed.\n";
