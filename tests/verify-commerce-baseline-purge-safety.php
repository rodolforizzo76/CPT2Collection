<?php
declare(strict_types=1);
require __DIR__.'/support/baseline-purge.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselinePurgeRecord as Record,TableNames};
foreach(['future_owner','foreign_claim','missing_epoch','stock_trigger','unsafe_tombstone','cursor_residual','revision_exhausted'] as $case){
 $db=new wpdb('c2c_purgesafe_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{
  $b=purge_fixture($db);$svc=purge_service($db);$r=$svc->step($b,null,1);$preserved=purge_preserved($db);
  if($case==='future_owner')sql($db,'UPDATE '.$t->payment_events().' SET epoch_id='.$b['first_epoch_id'].' WHERE id=1');
  if($case==='stock_trigger')sql($db,"CREATE TRIGGER {$db->prefix}stock_trigger AFTER DELETE ON {$t->payment_events()} FOR EACH ROW UPDATE {$db->postmeta} SET meta_value='99' WHERE meta_id=1");
  if($case==='foreign_claim'){while($r->operation()->data()['stage']!==array_search('provider_claims',Record::STAGES,true))$r=$svc->step($b,$r->operation(),1);sql($db,"UPDATE {$t->provider_claims()} SET installation_id=REPEAT('9',32)");}
  if($case==='missing_epoch')sql($db,'UPDATE '.$t->payment_events().' SET epoch_id=0 WHERE id=1');
  if($case==='unsafe_tombstone'){while($r->operation()->data()['stage']!==array_search('order_deletions',Record::STAGES,true))$r=$svc->step($b,$r->operation(),1);sql($db,'UPDATE '.$t->order_deletions().' SET original_order_id=1000');}
  if($case==='cursor_residual'){$r=$svc->step($b,$r->operation(),1);$r=$svc->step($b,$r->operation(),1);sql($db,"INSERT INTO {$t->payment_events()}(id,provider,provider_event_id,event_type,livemode,processing_status,received_at,payload_sha256,ownership_state) VALUES(1,'stripe_checkout','evt_external','checkout.session.completed',0,'received','2026-01-01',REPEAT('f',64),'unresolved')");}
  if($case==='revision_exhausted'){$d=$r->operation()->data();$d['revision']=PHP_INT_MAX;$expected=new Record($d);sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET baseline_purge=%s',$expected->json()));$r=$svc->inspect($b);}
  $snap=baseline_snapshot($db);$out=$svc->step($b,$r->operation(),1);check($out->code()==='inconsistent_state'&&baseline_snapshot($db)===$snap,$case.': contradictory/unsafe state blocks without partial deletion');
  check(purge_preserved($db)===$preserved&&$db->get_var('SELECT state FROM '.$t->commerce_control())==='baseline_pending',$case.': no stock mutation and no automatic reopening');
 }finally{baseline_cleanup($db);}
}
