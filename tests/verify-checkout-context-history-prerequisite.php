<?php
/** Phase 4.7 prerequisite diagnostic: reset erasure loses unclaimed Order provenance.
 * Historical STOP reproduction retained; default verifies the approved conservative contract.
 * Only disposable MariaDB and real production writers/reset/deletion are used.
 */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
use RodolfoRizzo\Cpt2Collection\{CartRepository,OrderRepository,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,TransactionManager,TableNames,UtcClock};

$historical=in_array('--historical-baseline',$argv,true);
$db=new DiscoveryDb('c2c_47_history_'.bin2hex(random_bytes(4)).'_');
try {
 validation_schema($db);
 $tables=new TableNames($db);
 // Checkout preparation may commit an Order before payment claim creation.
 $erased=validation_order($db,1,false,false);
 check($db->get_var('SELECT id FROM '.$tables->orders().' WHERE id='.$erased['order'])!==null,'unclaimed original Order is genuinely committed before reset');
 $tx=new TransactionManager($db,$tables);$clock=new UtcClock();$now=$clock->now_mysql();
 check($tx->begin_commerce_shared(),'separate never-committed checkout fixture admitted');
 $cart=(new CartRepository($db,$tables))->create(hash('sha256',random_bytes(32)),$now,$now,$now,'2030-01-01 00:00:00');
 $never=(new OrderRepository($db,$tables))->create($cart->id(),'pending','EUR',100,100,$now,$now,hash('sha256',random_bytes(32)),0,0);
 check(is_object($never)&&$tx->rollback(),'another allocated Order ID never becomes a committed Order');
 check($db->get_var('SELECT id FROM '.$tables->orders().' WHERE id='.$never->id())===null,'allocated gap is not an existing checkout history');
 $deleted=validation_order($db,1,false,false);
 $service=static fn()=>new OrderDeletionService(new OrderDeletionRepository($db,$tables),new OrderDeletionRegistry($db,$tables),new TransactionManager($db,$tables),$clock);
 check($service()->delete($deleted['order'])->code()==='deleted','positive control uses supported Phase 3 deletion');
 check($service()->delete($deleted['order'])->code()==='already_deleted','positive control repeats as already_deleted before reset');
 $record=discovery_finish($db,validation_start($db));
 check($record->status()==='completed','real reset erasure and atomic epoch switch complete');
 foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $method)check((int)$db->get_var('SELECT COUNT(*) FROM '.$tables->$method())===0,'reset actually erases '.$method);
 check((int)$db->get_var('SELECT COUNT(*) FROM '.$tables->provider_claims())===0,'neither unclaimed Order leaves provider claim provenance');
 check((int)$db->get_var('SELECT COUNT(*) FROM '.$tables->order_deletions())===1,'reset fabricates no per-Order Phase 3 deletion witness');
 $before=baseline_snapshot($db);$db->capture=true;$db->captured=[];
 $outcomes=[];
 foreach(['reset_erased'=>$erased['order'],'never_committed'=>$never->id(),'phase3_deleted'=>$deleted['order']] as $kind=>$id){
  $outcomes[$kind]=['order_id'=>$id,'code'=>$service()->delete($id)->code()];
 }
 $db->capture=false;
 foreach($db->captured as $query)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$query),'diagnostic retries attempt zero operational or retained DML');
 check(baseline_snapshot($db)===$before,'all absence classifications preserve stock, business and retained evidence');
 check($outcomes['reset_erased']['code']===($historical?'already_absent':'absence_unprovable')&&$outcomes['never_committed']['code']===($historical?'already_absent':'absence_unprovable'),'current endpoint service cannot distinguish erased unclaimed Order from never-committed ID');
 check($outcomes['phase3_deleted']['code']===($historical?'evidence_conflict':'already_deleted'),'separate existing historical already_deleted compatibility gap is reproduced');
 echo 'PHASE_47_PREREQUISITE '.json_encode(['schema'=>'0.19.0','reset_completed'=>true,'results'=>$outcomes,'provider_claims'=>0,'retained_deletions'=>1,'stock'=>(int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77"),'per_order_erased_proof'=>false,'verdict'=>$historical?'historical STOP reproduced':'approved conservative absence and positive historical deletion contract verified'])."\n";
} finally {baseline_cleanup($db);}
