<?php
declare(strict_types=1);
require __DIR__.'/support/baseline-purge.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselinePurgeRecord as Record,CommerceBaselineMigration,CommerceBaselinePurgeMigration,TableNames,TransactionManager,CartRepository,OrderRepository};
$db=new BaselineWriteProbeDb('c2c_purge_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 $GLOBALS['baseline_options']=['fixture_config'=>'preserve','fixture_credential'=>'fixture_only'];$b=purge_fixture($db);$preserved=purge_preserved($db);$epochs=$db->get_results('SELECT * FROM '.$t->commerce_epochs().' ORDER BY id');$prefix=$db->prefix;
 $wrong=$b;$wrong['identity']=str_repeat('9',32);check(purge_service($db)->step($wrong)->code()==='stale','wrong prepared attestation cannot start');
 $r=purge_service($db)->inspect($b);check($r->code()==='prepared'&&$r->operation()===null,'interruption before first deletion remains prepared');
 $r=purge_service($db)->step($b,null,1);check($r->operation()->data()['stage']===0&&$r->operation()->data()['cursor']===0,'progress creation performs no deletion');
 $initial=$r->operation();$r=purge_service($db)->step($b,$initial,1);check($r->operation()->data()['cursor']===1&&(int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_events())===1,'batch size one deletes exactly one receipt and commits cursor');
 check(purge_service($db)->step($b,$initial,1)->code()==='stale','stale worker cannot advance conflicting progress');
 $expected=$r->operation();$db->close();$db=new BaselineWriteProbeDb($prefix);$t=new TableNames($db);check(purge_service($db)->inspect($b)->operation()->equals($expected),'process restart resumes exact committed cursor');
 $seen=[];
 while(true){
  $svc=purge_service($db);$r=$svc->inspect($b);$d=$r->operation()->data();$seen[$d['stage']]=true;
  if($d['stage']===count(Record::STAGES))break;
  $tx=new TransactionManager($db,$t);check(!$tx->begin_commerce_shared()&&!$tx->begin_reset_control(),'normal commerce/reset remain fenced during every stage');
  $p=modern_payload(str_repeat('c',32),'evt_during_'.$d['revision']);$writes=$db->attempted_writes;$snapshot=baseline_snapshot($db);
  check(webhook_for($db)->handle($p,hash('sha256',json_encode($p)))->code()==='pre_baseline'&&$db->attempted_writes===$writes&&baseline_snapshot($db)===$snapshot,'old ingress remains excluded without attempted writes while deleting');
  $n=$svc->step($b,$r->operation(),1);check($n->code()==='progressed','bounded deletion/stage transition');
 }
 check(array_keys($seen)===range(0,count(Record::STAGES)),'canonical ten-stage order reached');
 check($db->get_var('SELECT state FROM '.$t->commerce_control())==='baseline_pending'&&$db->get_var('SELECT id FROM '.$t->commerce_epochs().' WHERE id='.$b['first_epoch_id'])===null,'ready-for-finalization does not prematurely activate reserved epoch');
 // Exact residual predicate blocks a row even behind a previously completed stage.
 sql($db,"INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,created_at,updated_at) VALUES(888,10,1,'2026-01-01','2026-01-01')");$before=baseline_snapshot($db);
 check(purge_service($db)->step($b,$r->operation(),1)->code()==='inconsistent_state'&&baseline_snapshot($db)===$before,'final predicate rejects residual commerce atomically');sql($db,'DELETE FROM '.$t->cart_items().' WHERE cart_id=888');
 $db->close();$db=new BaselineWriteProbeDb($prefix);$t=new TableNames($db);$svc=purge_service($db);$ready=$svc->inspect($b);$done=$svc->step($b,$ready->operation(),1);
 check($done->code()==='completed'&&$done->operation()->data()['completed'],'restart immediately before finalization completes');
 $now=purge_baseline($db);check($now['status']==='completed'&&array_diff_assoc($b,$now)===['status'=>'prepared'],'same reserved baseline/epoch identities complete');
 check((int)$db->get_var('SELECT current_epoch_id FROM '.$t->commerce_control())===$b['first_epoch_id']&&$db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE id='.$b['first_epoch_id'])===$b['first_epoch_identity'],'atomic final switch uses exact previously reserved modern epoch');
 check($db->get_var('SELECT retired_at FROM '.$t->commerce_epochs().' WHERE id=2')!==null&&serialize($db->get_row('SELECT * FROM '.$t->commerce_epochs().' WHERE id=1'))===serialize($epochs[0]),'source retired and earlier epoch history unchanged');
 foreach(Record::STAGES as $m)check($db->get_var('SELECT id FROM '.$t->$m().' LIMIT 1')===null,$m.': disposable native/legacy/unresolved/orphan state absent');
 check(purge_preserved($db)===$preserved,'products metadata byte-equivalent stock configuration and reset history preserved');
 $snapshot=baseline_snapshot($db);$writes=$db->attempted_writes;
 check($svc->step($b,$initial,1)->code()==='already_completed'&&$svc->inspect($b)->code()==='already_completed'&&$db->attempted_writes===$writes&&baseline_snapshot($db)===$snapshot,'stale worker after completion performs zero deletion/epoch/provider/stock writes');
 foreach(['valid','foreign','missing','malformed','unknown'] as $kind){$p=modern_payload(str_repeat('c',32),'evt_brandnew_postpurge_'.$kind);if($kind==='foreign')$p['data']['object']['metadata']['c2c_installation_id']=str_repeat('f',32);if($kind==='missing')unset($p['data']['object']['metadata']);if($kind==='malformed')$p['data']['object']['metadata']['c2c_epoch_identity']='bad';if($kind==='unknown')$p['data']['object']['metadata']['c2c_epoch_identity']=str_repeat('9',32);$res=webhook_for($db)->handle($p,hash('sha256',json_encode($p)));check($res->status()>=400||in_array($res->code(),['pre_baseline','foreign_installation'],true),'post-purge excluded scope: '.$kind);check($db->attempted_writes===$writes&&baseline_snapshot($db)===$snapshot,'post-purge old Session/PaymentIntent new Event cannot recreate evidence: '.$kind);}
 $tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'modern admission reopened after durable completion');
 $cart=new CartRepository($db,$t);check($cart->find_active_by_token_hash(str_repeat('c',64),gmdate('Y-m-d H:i:s'))===null&&$cart->find_for_update(1)===false,'old cookie/cart cannot recover authority');
 $order=new OrderRepository($db,$t);check($order->find(1)===null&&$order->find(99)===null,'old order/access references remain absent');
 $now=gmdate('Y-m-d H:i:s');$new_cart=$cart->create(hash('sha256','postpurge cart'),$now,$now,$now,'2030-01-01 00:00:00');$o=$order->create($new_cart->id(),'pending','EUR',100,100,$now,$now,hash('sha256','postpurge attempt'),0,0);check($o!==false&&$o->id()>99&&$tx->commit(),'new modern order cannot collide with deleted order/tombstone');
 $new_order=payment_fixture($db,10);[$pay]=payment_service($db,new ModernBoundaryProvider());check($pay->create($new_order,str_repeat('6',32))->is_success(),'post-purge modern provider response activates normally');
 $ref=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$new_order);$p=modern_payload($ref,'evt_postpurge_modern',$b['installation_id'],$b['first_epoch_identity']);$hash=hash('sha256',json_encode($p));
 check(webhook_for($db)->handle($p,$hash)->is_accepted()&&$db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE meta_id=1')==='6','new modern payment converts physical stock exactly once');
 $snap=baseline_snapshot($db);check(webhook_for($db)->handle($p,$hash)->is_accepted()&&baseline_snapshot($db)===$snap,'duplicate modern payment does not reconvert stock');
 $writes=$db->attempted_writes;check(purge_service($db)->step($b,$initial,1)->code()==='already_completed'&&$db->attempted_writes===$writes&&baseline_snapshot($db)===$snap,'completed purge cannot delete newly created modern commerce');
 check(\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db)&&baseline_snapshot($db)===$snap,'v16 migration retry preserves completed purge and new modern commerce');
}finally{baseline_cleanup($db);}
$db=new BaselineWriteProbeDb('c2c_purgefresh_'.bin2hex(random_bytes(5)).'_');
try{baseline_schema($db);check(CommerceBaselineMigration::run($db)&&CommerceBaselinePurgeMigration::run($db),'pristine baseline reaches v16');$b=purge_baseline($db);$snap=baseline_snapshot($db);$w=$db->attempted_writes;check(purge_service($db)->step($b)->code()==='already_completed'&&$db->attempted_writes===$w&&baseline_snapshot($db)===$snap,'fresh completed baseline never enters destructive purge');}finally{baseline_cleanup($db);}
