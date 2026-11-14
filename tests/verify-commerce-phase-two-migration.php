<?php
/** Inject failures at actual production SQL boundaries, then restart on a fresh connection. */
declare(strict_types=1);
require __DIR__.'/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,CommercePhaseTwoMigration,TransactionManager};
final class InterruptedMigrationDb extends wpdb {
 public string $boundary='';public bool $fired=false;private int $commits=0;
 public function query(string $query):int|false {
  $result=parent::query($query);if('COMMIT'===$query){++$this->commits;}
  $match=match($this->boundary){
   'fence'=> $query==='COMMIT'&&$this->commits===1,
   'first_table'=>str_starts_with($query,'CREATE TABLE IF NOT EXISTS')&&str_contains($query,'provider_claims'),
   'column'=>str_starts_with($query,'ALTER TABLE')&&str_contains($query,'orders ADD epoch_id'),
   'backfill'=>str_starts_with($query,'INSERT INTO')&&str_contains($query,'provider_identities'),
   'backfill_commit'=>$query==='COMMIT'&&$this->commits===2,
   'not_null'=>str_starts_with($query,'ALTER TABLE')&&str_contains($query,'orders MODIFY epoch_id'),
   'index'=>str_starts_with($query,'ALTER TABLE')&&str_contains($query,'ADD KEY epoch_stock'),
   'ready'=>str_starts_with($query,'UPDATE')&&str_contains($query,"SET state='ready'"),
   'ready_commit'=>$query==='COMMIT'&&$this->commits===3,
   default=>false,
  };
  if($match&&!$this->fired){$this->fired=true;$this->last_error='injected result loss after production statement';return false;}
  return $result;
 }
}
function seed_paid(wpdb $db,TableNames $t):void {
 $at='2026-01-01 00:00:00';
 sql($db,"CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB");
 sql($db,"INSERT INTO {$db->postmeta} VALUES(1,10,'_cpt2collection_stock_quantity','7')");
 sql($db,"INSERT INTO {$t->orders()}(id,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at) VALUES(1,'paid','EUR',100,100,'$at','$at','$at')");
 sql($db,"INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,10,'Legacy',100,1,100,100,'EUR','$at')");
 sql($db,"INSERT INTO {$t->reservations()}(order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at,converted_at) VALUES(1,1,10,1,'converted','$at','$at','$at','$at')");
 sql($db,"INSERT INTO {$t->payment_sessions()}(id,reference,order_id,provider,provider_session_id,provider_payment_id,provider_event_id,creation_key_hash,status,currency,amount_minor,created_at,updated_at,completed_at) VALUES(1,REPEAT('c',32),1,'stripe_checkout','cs_old','pi_old','evt_old',REPEAT('d',64),'completed','EUR',100,'$at','$at','$at')");
 sql($db,"INSERT INTO {$t->payment_events()}(provider,provider_event_id,event_type,livemode,payment_session_id,provider_session_id,provider_payment_id,local_reference,processing_status,received_at,payload_sha256) VALUES('stripe_checkout','evt_old','checkout.session.completed',0,1,'cs_old','pi_old',REPEAT('c',32),'processed','$at',REPEAT('e',64))");
}
function original_state(wpdb $db,TableNames $t):string {
 $rows=[];foreach(['orders','order_items','reservations','payment_sessions','payment_events'] as $method){$rows[$method]=$db->get_results('SELECT * FROM '.$t->$method().' ORDER BY id');foreach($rows[$method] as $r){unset($r->epoch_id,$r->ownership_state);}}
 $rows['stock']=$db->get_results('SELECT * FROM '.$db->postmeta.' ORDER BY meta_id');return serialize($rows);
}
foreach(['fence','first_table','column','backfill','backfill_commit','not_null','index','ready','ready_commit'] as $boundary){
 $prefix='c2c_p2mig_'.bin2hex(random_bytes(5)).'_';$db=new InterruptedMigrationDb($prefix);$t=new TableNames($db);$tables=phase_one_schema($db);seed_paid($db,$t);$before=original_state($db,$t);$identities=serialize([$db->get_results('SELECT installation_id,current_epoch_id FROM '.$t->commerce_control()),$db->get_results('SELECT * FROM '.$t->commerce_epochs())]);
 try {
  $db->boundary=$boundary;check(!CommercePhaseTwoMigration::run($db)&&$db->fired,"$boundary: production migration interruption is reported");$db->close();$db=new wpdb($prefix);
  $tx=new TransactionManager($db,$t);
  if($boundary!=='ready_commit'){check(!$tx->begin_commerce_shared()&&$tx->admission_error()==='baseline_unavailable',"$boundary: committed fence blocks commerce");}
  else {check(CommercePhaseTwoMigration::verify($db,$t)&&!$tx->begin_commerce_shared(),"$boundary: complete old schema still lacks modern authority");$tx->rollback();}
  check(original_state($db,$t)===$before,"$boundary: paid state and stock unchanged");
  if($boundary==='backfill'){check(0===(int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims()),'partial backfill rolls back as one transaction');}
  $durable=in_array($boundary,['backfill_commit','not_null','index','ready','ready_commit'],true)?serialize([$db->get_results('SELECT * FROM '.$t->provider_claims()),$db->get_results('SELECT * FROM '.$t->provider_identities())]):null;
  check(CommercePhaseTwoMigration::run($db),"$boundary: fresh-connection retry completes");
  check($identities===serialize([$db->get_results('SELECT installation_id,current_epoch_id FROM '.$t->commerce_control()),$db->get_results('SELECT * FROM '.$t->commerce_epochs())]),"$boundary: installation/epoch identities preserved");
  check(1===(int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims())&&3===(int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_identities()),"$boundary: no duplicate claims or bindings");
  if(null!==$durable){check($durable===serialize([$db->get_results('SELECT * FROM '.$t->provider_claims()),$db->get_results('SELECT * FROM '.$t->provider_identities())]),"$boundary: committed evidence remains byte-for-byte identical");}
  check(original_state($db,$t)===$before&&!$tx->begin_commerce_shared(),"$boundary: complete old-schema retry preserves commerce without granting modern admission");$tx->rollback();
 }finally{foreach(array_merge($tables,[$t->provider_claims(),$t->provider_identities(),$db->postmeta]) as $table){$db->query('DROP TABLE IF EXISTS '.$table);}$db->close();}
}
// An existing partial registry must be transactional before backfill starts.
foreach(['nontransactional','contradictory_owner','quarantined'] as $fault){
 $prefix='c2c_p2bad_'.bin2hex(random_bytes(5)).'_';$db=new wpdb($prefix);$t=new TableNames($db);$tables=phase_one_schema($db);seed_paid($db,$t);$before=original_state($db,$t);
 try {
  if($fault==='nontransactional'){
   foreach(CommercePhaseTwoMigration::ddl($t) as $ddl){sql($db,$ddl);}
   sql($db,'ALTER TABLE '.$t->provider_claims().' ENGINE=MyISAM');
  }else{
   check(CommercePhaseTwoMigration::run($db),"$fault: seed completed migration");
   if($fault==='contradictory_owner'){sql($db,'UPDATE '.$t->provider_identities()." SET claim_id=999 WHERE identity_kind='session'");}
   else{sql($db,'UPDATE '.$t->provider_identities()." SET binding_state='quarantined',conflict_code='identity_conflict' WHERE identity_kind='session'");}
  }
  $evidence=serialize($db->get_results('SELECT * FROM '.$t->provider_identities().' ORDER BY id'));
  check(!CommercePhaseTwoMigration::run($db),"$fault: migration fails closed");
  $tx=new TransactionManager($db,$t);check(!$tx->begin_commerce_shared(),"$fault: commerce remains fenced");
  check(original_state($db,$t)===$before&&$evidence===serialize($db->get_results('SELECT * FROM '.$t->provider_identities().' ORDER BY id')),"$fault: no historical evidence or paid stock state rewritten");
  if($fault==='nontransactional'){check(0===(int)$db->get_var('SELECT COUNT(*) FROM '.$t->provider_claims()),'invalid partial structure rejected before first claim write');}
 }finally{foreach(array_merge($tables,[$t->provider_claims(),$t->provider_identities(),$db->postmeta]) as $table){$db->query('DROP TABLE IF EXISTS '.$table);}$db->close();}
}
