<?php
/** C authenticated ingress, ownership/disposition/duplicates and attempted-DML matrix. */
declare(strict_types=1);
require __DIR__.'/support/provider-webhook.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,StripeEventNormalizer,ProviderDiscoveryCandidate};
foreach(['ready','resetting','completed','later','deleted','deleted_later'] as $state)foreach(['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired','pending'] as $type){
 $db=new ResponseDb('c2c_c_matrix_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try {
  validation_schema($db);$f=validation_order($db,1);if($state==='ready')webhook_activate($db,$f);$r=null;$done=null;
  if(str_starts_with($state,'deleted'))webhook_deleted($db,$f);
  if(in_array($state,['resetting','completed','later','deleted_later'],true))$r=validation_start($db);
  if(in_array($state,['completed','later','deleted_later'],true)){$done=discovery_finish($db,$r);$r=null;}
  if(in_array($state,['later','deleted_later'],true))$r=validation_start($db);
  $history=$done===null?null:serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id()));
  $p=webhook_payload($f,type:$type==='pending'?'checkout.session.completed':$type);
  if(in_array($type,['pending','checkout.session.expired'],true)){$p['data']['object']['payment_status']='unpaid';$p['data']['object']['payment_intent']=null;}
  $before=response_business($db);$generation=$r?->evidence_generation();$controller=webhook_controller($db,$connections);$db->capture=true;$db->captured=[];
  $out=webhook_deliver($controller,$p);$db->capture=false;
  check($out->get_status()===200,'authenticated '.$state.' '.$type.' acknowledged');
  $keys=$p['data']['object']['payment_intent']===null?2:3;
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities())===($state==='ready'?3:$keys),'exact Event/Session/PI native evidence envelope');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities().' WHERE claim_id<>'.$f['claim'].' OR observed_epoch_id<>(SELECT epoch_id FROM '.$t->provider_claims().' WHERE id='.$f['claim'].')')===0,'original native owner and original observation epoch');
  if($state!=='ready'){check(response_business($db)===$before,'historical/deleted original business/stock/claim/history immutable');webhook_no_operational($db,$connections);}
  else {
   check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events())===1,'current owner operational receipt preserved');
   $stock=(int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77");
   check($stock===(in_array($type,['pending','checkout.session.expired'],true)?20:19),'current paid stock converts exactly once; lifecycle does not convert');
  }
  if($r!==null)check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===$generation+$keys,'active certification charged per changed key per A1 contract');
  $snapshot=baseline_snapshot($db);$more=[];$controller=webhook_controller($db,$more);$out=webhook_deliver($controller,$p);
  check($out->get_status()===200&&baseline_snapshot($db)===$snapshot,'identical authenticated duplicate changes zero evidence/generation/business state');response_no_operational($more);
  if($history!==null)check($history===serialize($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$done->id())),'completed reset stays byte-identical');
 }finally{baseline_cleanup($db);}
}
// Authentication failures, normalization/scope rejection never authorize retained DML.
foreach(['missing','invalid','malformed','stale','bad_json','bad_envelope','unsupported','foreign','unknown','malformed_scope','wrong_environment'] as $kind){
 $db=new ResponseDb('c2c_c_auth_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try{validation_schema($db);$f=validation_order($db,1);$r=validation_start($db);$p=webhook_payload($f);$signature='valid';
  if(in_array($kind,['missing','invalid','malformed','stale'],true))$signature=$kind;
  if($kind==='bad_json')$p='{';if($kind==='bad_envelope')unset($p['id']);if($kind==='unsupported')$p['type']='charge.succeeded';
  if($kind==='foreign')$p['data']['object']['metadata']['c2c_installation_id']=str_repeat('f',32);
  if($kind==='unknown'){$p['data']['object']['client_reference_id']=str_repeat('e',32);$p['data']['object']['metadata']['c2c_payment_session_reference']=str_repeat('e',32);}
  if($kind==='malformed_scope')$p['data']['object']['metadata']['c2c_epoch_identity']='bad';
  if($kind==='wrong_environment')$p['livemode']=true;
  $snapshot=baseline_snapshot($db);$db->capture=true;$db->captured=[];$out=webhook_deliver(webhook_controller($db,$connections),$p,$signature);$db->capture=false;
  $expected=in_array($kind,['unsupported','foreign'],true)?200:($kind==='wrong_environment'?503:400);
  check($out->get_status()===$expected,'HTTP rejection policy '.$kind.' '.$out->get_status());check(baseline_snapshot($db)===$snapshot,'authentication/ownership failure zero durable changes '.$kind);webhook_no_operational($db,$connections);
  if(in_array($kind,['missing','invalid','malformed','stale','bad_json','bad_envelope','unsupported'],true))check(!$connections,'authentication/envelope/event rejection occurs before A0/A1');
 }finally{baseline_cleanup($db);}
}
// Conflicting Event immutable facts quarantine once, never overwrite or continue.
foreach(['resetting','completed','later','deleted'] as $state){
 $db=new ResponseDb('c2c_c_conflict_'.bin2hex(random_bytes(5)).'_');$connections=[];$t=new TableNames($db);
 try {validation_schema($db);$f=validation_order($db,1);if($state==='deleted')webhook_deleted($db,$f);$r=$state==='deleted'?null:validation_start($db);if(in_array($state,['completed','later'],true)){$done=discovery_finish($db,$r);$r=$state==='later'?validation_start($db):null;}
  $p=webhook_payload($f);$controller=webhook_controller($db,$connections);check(webhook_deliver($controller,$p)->get_status()===200,'initial immutable Event retained');
  $row=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_kind='event'");$business=response_business($db);$g=$r===null?null:(int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id());
  $p['created']-=1;$out=webhook_deliver($controller,$p);check($out->get_status()===503,'same Event conflicting raw-body hash fails closed');
  $after=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_kind='event'");
  foreach(['claim_id','observed_epoch_id','first_seen_at','payload_sha256','event_type','environment','disposition'] as $field)check($row->$field===$after->$field,'conflict preserves immutable '.$field);
  check($after->binding_state==='quarantined'&&response_business($db)===$business,'quarantine is retained-only');
  if($g!==null)check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets().' WHERE id='.$r->id())===$g+1,'quarantine changes charge one key');
  $snapshot=baseline_snapshot($db);check(webhook_deliver($controller,$p)->get_status()===503&&baseline_snapshot($db)===$snapshot,'repeated quarantine zero changes/generation');
 }finally{baseline_cleanup($db);}
}
// A successful current receipt replay crosses actual reset and deletion transitions.
foreach(['start','complete','later','delete'] as $transition){
 $db=new ResponseDb('c2c_c_replay_'.bin2hex(random_bytes(5)).'_');$connections=[];
 try{validation_schema($db);$f=validation_order($db,1);webhook_activate($db,$f);$p=webhook_payload($f);check(webhook_deliver(webhook_controller($db,$connections),$p)->get_status()===200,'current original delivery commits');
  if($transition==='delete')webhook_deleted($db,$f);else{$r=validation_start($db);if(in_array($transition,['complete','later'],true))discovery_finish($db,$r);if($transition==='later')validation_start($db);}
  $snapshot=baseline_snapshot($db);$more=[];$db->capture=true;$db->captured=[];$out=webhook_deliver(webhook_controller($db,$more),$p);$db->capture=false;
  check($out->get_status()===200&&baseline_snapshot($db)===$snapshot,'duplicate across '.$transition.' never repeats business/evidence/generation');webhook_no_operational($db,$more);
 }finally{baseline_cleanup($db);}
}
echo "All C authenticated webhook matrix checks passed.\n";
