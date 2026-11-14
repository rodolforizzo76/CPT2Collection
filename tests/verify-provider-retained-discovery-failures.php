<?php
/** Deterministic corrupt/rejected/read failure vs unknown snapshot bookkeeping. */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
use RodolfoRizzo\Cpt2Collection\{ProviderRetainedDiscovery,ProviderDiscoveryCandidate as I,ProviderClaimLocator as L,TableNames,CommerceResetRecord};
foreach(['read','begin_before','begin_after','isolation','rollback_before','rollback_after'] as $mode){
 $prefix='c2c_a0_failure_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);
 try{
  validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f);$before=baseline_snapshot($db);
  if($mode==='read')$db->read_failure='FORCE INDEX(reference)';
  else{$db->bookkeeping_failure=match($mode){'isolation'=>'SET TRANSACTION','begin_before','begin_after'=>'START TRANSACTION',default=>'ROLLBACK'};$db->bookkeeping_after=str_ends_with($mode,'after');}
  $out=(new ProviderRetainedDiscovery($db,new TableNames($db)))->discover($input);
  check($out->status===($mode==='read'?'database_error':'transaction_outcome_unknown')&&$out->owner===null&&$out->fence===null,'typed '.$mode.' failure returns no guessed ownership');
  if($mode!=='read')check(!$db->transaction_connection_usable()&&(new ProviderRetainedDiscovery($db,new TableNames($db)))->discover($input)->status==='transaction_outcome_unknown','poisoned '.$mode.' connection cannot classify again');
  $db->close();$db=new DiscoveryDb($prefix);check(baseline_snapshot($db)===$before,'fresh connection proves snapshot failure no mutation');check((new ProviderRetainedDiscovery($db,new TableNames($db)))->discover($input)->attributed(),'fresh snapshot resolves after discard');
 }finally{baseline_cleanup($db);}
}
$db=new DiscoveryDb('c2c_a0_corrupt_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f);$row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);
 foreach(['epoch_identity'=>md5('wrong identity'),'environment'=>'live','tombstoned_at'=>'2030-01-01 00:00:00','evidence_state'=>'legacy','request_snapshot'=>'{bad json'] as $key=>$bad){sql($db,'UPDATE '.$t->provider_claims().' SET `'.$key.'`='.$db->prepare('%s',$bad).' WHERE id='.$f['claim']);check(discovery_run($db,$input)->status==='corrupt','corrupt claim '.$key.' fails closed');sql($db,'UPDATE '.$t->provider_claims().' SET `'.$key.'`='.($row->$key===null?'NULL':$db->prepare('%s',$row->$key)).' WHERE id='.$f['claim']);}
 $clone=get_object_vars($row);$clone['id']=null;$clone['reference']=md5('duplicate session');$clone['creation_key_hash']=hash('sha256','second');$clone['idempotency_key']='c2c_ps_'.$clone['reference'];$request=json_decode($row->request_snapshot,true);$request['reference']=$clone['reference'];$request['idempotency_key']=$clone['idempotency_key'];$clone['request_snapshot']=json_encode($request);sql($db,'INSERT INTO '.$t->provider_claims().' (`'.implode('`,`',array_keys($clone)).'`) VALUES('.implode(',',array_map(fn($v)=>$v===null?'NULL':$db->prepare('%s',(string)$v),$clone)).')');check(discovery_run($db,$input)->status==='ambiguous','duplicate original Session native owners fails without first-row selection');sql($db,'DELETE FROM '.$t->provider_claims().' WHERE reference=\''.$clone['reference'].'\'');
 $r=validation_start($db);$seed=get_object_vars($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$r->id()));
 foreach(['checkpoint'=>'v_nonsense','target_epoch_id'=>999,'source_epoch_identity'=>md5('wrong source'),'validation_generation'=>999,'phase'=>'nonsense'] as $key=>$bad){sql($db,'UPDATE '.$t->commerce_resets().' SET `'.$key.'`='.$db->prepare('%s',(string)$bad).' WHERE id='.$r->id());check(discovery_run($db,$input)->status==='corrupt','malformed active '.$key.' fails closed');sql($db,'UPDATE '.$t->commerce_resets().' SET `'.$key.'`='.($seed[$key]===null?'NULL':$db->prepare('%s',(string)$seed[$key])).' WHERE id='.$r->id());}
 // A reserved target appearing before completion is corrupt, never a source substitute.
 sql($db,$db->prepare('INSERT INTO '.$t->commerce_epochs().' VALUES(999,%s,%s,NULL)',$r->target_epoch_identity(),$r->started_at()));check(discovery_run($db,$input)->status==='corrupt','allocated active target rejected');sql($db,'DELETE FROM '.$t->commerce_epochs().' WHERE id=999');
}finally{baseline_cleanup($db);}
// Genuine baseline/purge transition excludes retained epoch-one legacy history.
$db=new DiscoveryDb('c2c_a0_legacy_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 maintenance_schema($db);check(\RodolfoRizzo\Cpt2Collection\CommerceBaselineMigration::run($db)&&\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db)&&\RodolfoRizzo\Cpt2Collection\CommerceOrderSourceIndexMigration::run($db)&&\RodolfoRizzo\Cpt2Collection\CommerceClaimSessionIndexMigration::run($db)&&\RodolfoRizzo\Cpt2Collection\CommerceDeletedOrderIndexesMigration::run($db),'legacy fixture reaches pending baseline 0.19');
 // Use existing purge fixture helper instead of creating historical ownership.
 check(discovery_run($db,I::correlated('stripe_checkout','test',L::id(1),[],gmdate('Y-m-d H:i:s')))->status==='unsupported','canonical prepared baseline denies modern discovery');
 $b=json_decode($db->get_var('SELECT baseline_contract FROM '.$t->commerce_control()),true);$first=$b['first_epoch_id'];$identity=$b['first_epoch_identity'];$inst=$b['installation_id'];
 // Scope-only fixture, explicitly synthetic completion for the known pre-modern classification.
 $at=gmdate('Y-m-d H:i:s');sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET retired_at=%s WHERE id=1',$at));sql($db,$db->prepare('INSERT INTO '.$t->commerce_epochs().' VALUES(%d,%s,%s,NULL)',$first,$identity,$at));$b['status']='completed';sql($db,$db->prepare('UPDATE '.$t->commerce_control()." SET current_epoch_id=%d,state='ready',baseline_contract=%s WHERE id=1",$first,json_encode($b)));
 $old=$db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE id=1');check(discovery_run($db,I::correlated('stripe_checkout','test',L::id(1),[],gmdate('Y-m-d H:i:s'),$inst,$old,1))->status==='pre_baseline','known pre-modern source excluded before native parsing');
}finally{baseline_cleanup($db);}
// No parent row participates even in a current candidate classification.
$db=new DiscoveryDb('c2c_a0_parentless_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);$f=validation_order($db);$input=discovery_input($db,$f);$before=discovery_run($db,$input);
 foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)sql($db,'DELETE FROM '.$t->$m());
 $after=discovery_run($db,$input);check(serialize($before)===serialize($after),'seven-table presence/absence changes no retained classification, even current candidate');
 $tx=new \RodolfoRizzo\Cpt2Collection\TransactionManager($db,$t);check($tx->begin_commerce_shared(),'existing owner fixture');
 check((new ProviderRetainedDiscovery($db,$t))->discover($input)->status==='database_error'&&$tx->is_commerce_admitted(),'A0 never borrows or ends another transaction owner');check($tx->rollback(),'existing owner ends itself');
 sql($db,'ALTER TABLE '.$t->provider_identities().' DROP INDEX identity');check((new ProviderRetainedDiscovery($db,$t))->discover($input)->status==='database_error','missing schema key fails before unbounded lookup');
}finally{baseline_cleanup($db);}
echo "All A0 retained failure distinctions passed.\n";
