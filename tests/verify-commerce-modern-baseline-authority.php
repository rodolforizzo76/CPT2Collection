<?php
declare(strict_types=1);
require __DIR__.'/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceEvidenceContext,CommerceResetRecord,CommerceResetRepository,CommerceResetService,ProviderClaimRecord,ProviderRegistry,TransactionManager,TableNames,UtcClock,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry};
$db=new wpdb('c2c_baseauth_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 baseline_schema($db);check(CommerceBaselineMigration::run($db),'modern authority fixture baselined');check(\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db),'modern authority fixture reaches v16 without destructive work');
 foreach(['current','retired'] as $mode){
  $order=payment_fixture($db);$p=new ModernBoundaryProvider();$p->exception=true;
  if($mode==='retired')$p->during_call=fn()=>baseline_epoch_switch($db);
  [$svc]=payment_service($db,$p);$out=$svc->create($order,str_repeat($mode==='current'?'3':'4',32));
  $ref=$db->get_var('SELECT reference FROM '.$t->payment_sessions().' WHERE order_id='.$order);
  check(is_object((new ProviderRegistry($db,$t))->identity('stripe_checkout','session','cs_gap_'.$ref))===($mode==='current'), $mode.': current exception retains evidence; synthetic noncanonical retirement is excluded');
  check($db->get_var('SELECT status FROM '.$t->payment_sessions().' WHERE order_id='.$order)==='creating',$mode.': invalid provider response cannot activate session');
 }
 // Existing single-order deletion guards remain authoritative in a modern epoch.
 $order=payment_fixture($db);[$svc]=payment_service($db,new ModernBoundaryProvider());check($svc->create($order,str_repeat('5',32))->is_success(),'modern deletion fixture payment active');
 $tx=new TransactionManager($db,$t);$clock=new UtcClock();$registry=new ProviderRegistry($db,$t);
 $deletion=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),$tx,$clock);
 $stock=$db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77');
 $result=$deletion->delete($order);check($result->code()==='deleted','modern permanent deletion remains allowed');
 check($db->get_var('SELECT meta_value FROM '.$db->postmeta.' WHERE post_id=77')===$stock,'modern deletion preserves physical stock');
 $order=payment_fixture($db);[$svc]=payment_service($db,new ModernBoundaryProvider());check($svc->create($order,str_repeat('6',32))->is_success(),'modern reset fixture payment active');
 $claim=ProviderClaimRecord::from_row($db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE order_id='.$order));
 $before=baseline_snapshot($db);check($registry->bind('stripe_checkout','session','cs_no_authority',$claim->id(),$clock->now_mysql())==='database_error'&&baseline_snapshot($db)===$before,'scope never substitutes for transaction authority');
 check($tx->begin_commerce_shared(),'direct modern registry writer admitted');
 $before=baseline_snapshot($db);check($registry->bind('stripe_checkout','session','cs_unscoped',null,$clock->now_mysql())==='scope_rejected'&&baseline_snapshot($db)===$before,'unscoped direct binding cannot introduce evidence');
 check($registry->bind('stripe_checkout','session','cs_wrong_environment',$claim->id(),$clock->now_mysql(),null,null,'live')==='conflict'&&baseline_snapshot($db)===$before,'claim environment still required after scope eligibility');
 $prior=$db->get_row('SELECT * FROM '.$t->provider_identities()." WHERE identity_kind='session' AND claim_id<>".$claim->id().' ORDER BY id LIMIT 1');
 check($registry->bind('stripe_checkout','session',$prior->identity_value,$claim->id(),$clock->now_mysql())==='conflict','modern cross-claim collision remains conflicting');
 $after=$registry->identity('stripe_checkout','session',$prior->identity_value);
 check((string)$after->claim_id===(string)$prior->claim_id&&$after->binding_state==='quarantined','immutable owner retained with durable conflict evidence');check($tx->commit(),'modern conflict evidence commits under normal authority');
 $reset=new CommerceResetService(new CommerceResetRepository($db,$t),$tx,$clock);
 $source=$db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE retired_at IS NULL');$started=$reset->start(str_repeat('7',32),$source);
 check($started->code()==='started','completed baseline permits normal reset start');
 $before=baseline_snapshot($db);check(CommerceBaselineMigration::run($db)&&baseline_snapshot($db)===$before,'completed baseline retry preserves active reset without a new preparation');
 check(!$tx->begin_commerce_shared(),'reset continues to deny normal authority');check(\RodolfoRizzo\Cpt2Collection\CommerceBaselinePurgeMigration::run($db)&&baseline_snapshot($db)===$before,'v16 idempotent migration preserves running modern reset');
 $op=$started->operation();$evidence=new CommerceEvidenceContext($op,$claim);
 check($tx->begin_historical_evidence($evidence),'modern historical evidence admits during reset');
 check($registry->bind_historical($evidence,'stripe_checkout','session','cs_historical_new',$clock->now_mysql(),'test')==='bound','common registry permits modern evidence-only binding');
 check($tx->commit(),'identity and generation commit together');
 check((int)$db->get_var('SELECT evidence_generation FROM '.$t->commerce_resets())===1,'one evidence-generation increment');
 $op=CommerceResetRecord::from_row($db->get_row('SELECT * FROM '.$t->commerce_resets()));$evidence=new CommerceEvidenceContext($op,$claim);
 check($tx->begin_historical_evidence($evidence),'fresh evidence snapshot admitted');$before=baseline_snapshot($db);
 check($registry->bind_historical($evidence,'stripe_checkout','session','cs_historical_new',$clock->now_mysql(),'test')==='bound','duplicate historical identity idempotent');check($tx->commit()&&baseline_snapshot($db)===$before,'duplicate increments no evidence generation');
 check($tx->begin_historical_evidence($evidence),'rollback fixture admitted');check($registry->bind_historical($evidence,'stripe_checkout','payment','pi_rollback',$clock->now_mysql(),'test')==='bound'&&$tx->rollback(),'historical identity rollback succeeds');check(baseline_snapshot($db)===$before,'rollback preserves identity and generation atomically');
 check($tx->begin_reset_maintenance($op),'exact reset maintenance still admitted');check($registry->bind('stripe_checkout','session','cs_wrong_mode',$claim->id(),$clock->now_mysql())==='database_error','maintenance cannot bind provider evidence');$tx->rollback();
}catch(Throwable $e){throw $e;}finally{baseline_cleanup($db);}
