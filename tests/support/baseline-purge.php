<?php
declare(strict_types=1);
require_once __DIR__.'/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceBaselinePurgeService,TableNames,UtcClock};
function purge_service(wpdb $db):CommerceBaselinePurgeService{return new CommerceBaselinePurgeService($db,new TableNames($db),new UtcClock());}
function purge_baseline(wpdb $db):array{return json_decode($db->get_var('SELECT baseline_contract FROM '.(new TableNames($db))->commerce_control()),true,512,JSON_THROW_ON_ERROR);}
function purge_fixture(wpdb $db):array{
 maintenance_schema($db);$t=new TableNames($db);
 sql($db,"CREATE TABLE {$db->posts}(ID BIGINT PRIMARY KEY,post_type VARCHAR(32),post_title VARCHAR(64)) ENGINE=InnoDB");
 sql($db,"INSERT INTO {$db->posts} VALUES(10,'collection_item','Preserved product')");
 sql($db,"INSERT INTO {$db->postmeta} VALUES(2,10,'price','123'),(3,10,'description','preserved')");
 // Model a genuine deleted high order; its AUTO_INCREMENT counter must survive.
 sql($db,"INSERT INTO {$t->orders()}(id,status,currency,subtotal_minor,total_minor,created_at,updated_at,epoch_id) VALUES(99,'paid','EUR',100,100,'2026-01-01','2026-01-01',1)");sql($db,"DELETE FROM {$t->orders()} WHERE id=99");
 // All statuses/evidence vocabulary are disposable, including native and unresolved.
 sql($db,"UPDATE {$t->provider_claims()} SET evidence_state='native' WHERE id=1");
 sql($db,"INSERT INTO {$t->payment_events()}(provider,provider_event_id,event_type,livemode,processing_status,received_at,payload_sha256,ownership_state,epoch_id) VALUES('stripe_checkout','evt_unresolved','checkout.session.completed',0,'received','2026-01-01',REPEAT('f',64),'unresolved',NULL)");
 sql($db,"INSERT INTO {$t->order_items()}(order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(999,10,'Orphan',1,1,1,1,'EUR','2026-01-01')");
 sql($db,"INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,created_at,updated_at) VALUES(999,10,1,'2026-01-01','2026-01-01')");
 // Retired source history/reset history remains durable; no active reset.
 sql($db,"UPDATE {$t->commerce_epochs()} SET retired_at='2026-01-02' WHERE id=1");
 sql($db,"INSERT INTO {$t->commerce_epochs()} VALUES(2,REPEAT('e',32),'2026-01-02',NULL)");
 sql($db,"UPDATE {$t->commerce_control()} SET current_epoch_id=2");
 sql($db,"INSERT INTO {$t->commerce_resets()}(operation_identity,installation_id,source_epoch_id,source_epoch_identity,target_epoch_identity,target_epoch_id,status,phase,started_at,updated_at,completed_at) VALUES(REPEAT('f',32),REPEAT('a',32),1,REPEAT('b',32),REPEAT('e',32),2,'completed','completed','2026-01-01','2026-01-02','2026-01-02')");
 check(CommerceBaselineMigration::run($db),'Checkpoint 1 prepares populated multi-epoch history');$b=purge_baseline($db);$snap=baseline_snapshot($db);
 check(CommerceBaselinePurgeMigration::run($db),'v16 adds progress without executing purge');
 $after=unserialize(baseline_snapshot($db));$before=unserialize($snap);unset($after['commerce_control'][0]->baseline_purge);check(serialize($after)===serialize($before),'v16 migration preserves all old state and boundary');return $b;
}
function purge_preserved(wpdb $db):string{
 $t=new TableNames($db);return serialize([$db->get_results('SELECT * FROM '.$db->posts.' ORDER BY ID'),$db->get_results('SELECT * FROM '.$db->postmeta.' ORDER BY meta_id'),$db->get_results('SELECT * FROM '.$t->commerce_resets().' ORDER BY id'),$GLOBALS['baseline_options']??[]]);
}
function purge_to_end(wpdb $db,array $b):void{
 for($i=0;$i<100;$i++){$r=purge_service($db)->inspect($b);check($r->is_success(),'locked restart inspection');if($r->code()==='already_completed')return;$n=purge_service($db)->step($b,$r->operation(),1);check($n->is_success(),'one-row purge step: '.$n->code());}throw new RuntimeException('Purge did not complete');
}
