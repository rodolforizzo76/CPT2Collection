<?php
/** Focused real-InnoDB verification for the atomic order-deletion service. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { echo "SKIP: mysqli and C2C_TEST_DB_NAME are required.\n"; exit( 0 ); }
function __( string $message, string $domain = '' ): string { return $message; }
require __DIR__ . '/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{CommercePhaseThreeCheckpointOneMigration,OrderDeletionRegistry,OrderDeletionRepository,OrderDeletionResult,OrderDeletionService,ProviderRegistry,TableNames,TransactionManager,UtcClock};

class DeletionFailureDb extends wpdb {
	public string $fail_match = '';
	public function query( string $query ): int|false {
		if ( '' !== $this->fail_match && str_contains( $query, $this->fail_match ) ) { $this->fail_match = ''; $this->last_error = 'injected deletion failure'; return false; }
		return parent::query( $query );
	}
}
class DeletionCommitLossDb extends wpdb {
	public string $loss = '';
	public function query( string $query ): int|false {
		if ( 'COMMIT' === $query && '' !== $this->loss ) { $loss=$this->loss;$this->loss='';if('after'===$loss){parent::query($query);}$this->last_error='injected lost commit acknowledgement';return false; }
		return parent::query($query);
	}
}
function deletion_service( wpdb $db ): OrderDeletionService { $t=new TableNames($db);return new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock()); }
function deletion_snapshot( wpdb $db, TableNames $t, int $order ): string {
	$data=[];foreach(['orders','order_items','reservations','payment_sessions','payment_events','provider_claims','provider_identities','order_deletions','carts'] as $m){$data[$m]=$db->get_results('SELECT * FROM '.$t->$m().' ORDER BY id');}$data['stock']=$db->get_results('SELECT * FROM '.$db->postmeta.' ORDER BY meta_id');return serialize($data);
}
function fixture_order( wpdb $db, TableNames $t, int $id, bool $cart=true ): void {
	$at='2026-09-28 12:00:00';if($cart){sql($db,"INSERT INTO {$t->carts()}(epoch_id,id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES(1,$id,LPAD('$id',64,'c'),7,3,'$at','$at','$at','2030-01-01')");}
	$cart_id=$cart?(string)$id:'NULL';$revision=$cart?'7':'NULL';$generation=$cart?'3':'NULL';
	sql($db,"INSERT INTO {$t->orders()}(epoch_id,id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,cart_reconciliation_status,cart_reconciliation_code,cart_reconciliation_updated_at,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at) VALUES(1,$id,$cart_id,$revision,$generation,REPEAT('d',64),'completed','cart_reconciled','$at','paid','EUR',400,400,'$at','$at','$at')");
}
function add_item( wpdb $db, TableNames $t, int $order, int $id, int $post, ?string $reservation=null ): void {
	$at='2026-09-28 12:00:00';sql($db,"INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES($id,$order,$post,'Item $id',100,1,100,100,'EUR','$at')");
	if(null!==$reservation){$released=in_array($reservation,['released','expired'],true)?"'$at'":'NULL';$converted='converted'===$reservation?"'$at'":'NULL';sql($db,"INSERT INTO {$t->reservations()}(epoch_id,id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at,released_at,converted_at) VALUES(1,$id,$order,$id,$post,1,'$reservation','$at','$at','2030-01-01',$released,$converted)");}
}
function add_attempt( wpdb $db, TableNames $t, int $order, int $id, string $status, string $evidence='native' ): array {
	$at='2026-09-28 12:00:00';$ref=str_pad(dechex($id),32,'a',STR_PAD_LEFT);$key=str_pad(dechex($id),64,'b',STR_PAD_LEFT);$slot=in_array($status,['creating','active'],true)?'1':'NULL';$ps="cs_$id";$pi="pi_$id";$pe="evt_session_$id";
	sql($db,"INSERT INTO {$t->payment_sessions()}(epoch_id,id,reference,order_id,provider,provider_session_id,provider_payment_id,provider_event_id,creation_key_hash,status,active_slot,currency,amount_minor,created_at,updated_at,reconciliation_required_at,reconciliation_code) VALUES(1,$id,'$ref',$order,'stripe_checkout','$ps','$pi','$pe','$key','$status',$slot,'EUR',400,'$at','$at','$at','review')");
	sql($db,"INSERT INTO {$t->provider_claims()}(id,installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,request_snapshot,environment,evidence_state,disposition,created_at) VALUES($id,REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout','$ref',$order,$id,'$key','c2c_ps_$ref',2,'{\"amount\":400}','test','$evidence','open','$at')");
	foreach(['session'=>$ps,'payment'=>$pi,'event'=>$pe] as $kind=>$value){sql($db,"INSERT INTO {$t->provider_identities()}(provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,payload_sha256,event_type,environment,disposition) VALUES('stripe_checkout','$kind','$value',$id,'bound','$evidence',1,'$at',NULL,NULL,'test','pending')");}
	native_provider_claim_fixture($db,$id);
	return [$ref,$ps,$pi];
}
function add_event( wpdb $db, TableNames $t, int $id, ?int $session, string $ref, string $ps, string $pi, int $claim, bool $quarantine=false ): void {
	$at='2026-09-28 12:00:00';$event="evt_$id";$hash=hash('sha256',$event);$sid=null===$session?'NULL':(string)$session;
	sql($db,"INSERT INTO {$t->payment_events()}(epoch_id,ownership_state,id,provider,provider_event_id,event_type,livemode,payment_session_id,provider_session_id,local_reference,provider_payment_id,processing_status,attempt_count,received_at,payload_sha256) VALUES(1,'bound',$id,'stripe_checkout','$event','checkout.session.completed',0,$sid,'$ps','$ref','$pi','received',0,'$at','$hash')");
	$binding=$quarantine?'quarantined':'bound';$conflict=$quarantine?"'duplicate_integrity_conflict'":'NULL';sql($db,"INSERT INTO {$t->provider_identities()}(provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,payload_sha256,event_type,environment,disposition,conflict_code) VALUES('stripe_checkout','event','$event',$claim,'$binding','native',1,'$at','$hash','checkout.session.completed','test','received',$conflict)");
	if(null!==$session){sql($db,"UPDATE {$t->payment_sessions()} SET reconciliation_event_id=$id WHERE id=$session");}
}

$prefix='c2c_del_'.bin2hex(random_bytes(5)).'_';$db=new DeletionFailureDb($prefix);$t=new TableNames($db);$created=phase_two_schema($db);
try {
	check(CommercePhaseThreeCheckpointOneMigration::run($db),'checkpoint-one deletion schema is available');$created[]=$t->order_deletions(); attest_modern_runtime_fixture($db);provider_response_schema($db);$created[]=$t->commerce_resets();
	sql($db,"CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB");$created[]=$db->postmeta;
	for($p=101;$p<=110;$p++){sql($db,"INSERT INTO {$db->postmeta} VALUES($p,$p,'_cpt2collection_stock_quantity','".($p-90)."')");}

	fixture_order($db,$t,1,true);foreach(['active','expired','released','converted'] as $i=>$state){add_item($db,$t,1,$i+1,101+$i,$state);}
	$statuses=['creating','failed','expired','cancelled','completed'];$attempts=[];foreach($statuses as $i=>$status){$attempts[]=add_attempt($db,$t,1,20+$i,$status,'native');}
	add_event($db,$t,40,20,$attempts[0][0],$attempts[0][1],$attempts[0][2],20,true);add_event($db,$t,41,null,$attempts[1][0],$attempts[1][1],$attempts[1][2],21,false);
	// Independently constructed provider-connected order B must be byte-for-byte isolated.
	fixture_order($db,$t,90,true);add_item($db,$t,90,190,110,'active');[$bref,$bps,$bpi]=add_attempt($db,$t,90,290,'active');add_event($db,$t,390,290,$bref,$bps,$bpi,290);
	$order_b_before=serialize(array($db->get_row("SELECT * FROM {$t->orders()} WHERE id=90"),$db->get_results("SELECT * FROM {$t->order_items()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->reservations()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->payment_sessions()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->payment_events()} WHERE payment_session_id=290 ORDER BY id"),$db->get_results("SELECT * FROM {$t->provider_claims()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->provider_identities()} WHERE claim_id=290 ORDER BY id"),$db->get_row("SELECT * FROM {$t->carts()} WHERE id=90"),$db->get_results("SELECT * FROM {$db->postmeta} WHERE post_id=110 ORDER BY meta_id")));
	// Unresolved unrelated evidence must not be swept into the deletion.
	$hash=hash('sha256','unrelated');sql($db,"INSERT INTO {$t->payment_events()}(epoch_id,ownership_state,id,provider,provider_event_id,event_type,livemode,processing_status,attempt_count,received_at,payload_sha256) VALUES(NULL,'unresolved',99,'stripe_checkout','evt_unrelated','checkout.session.completed',0,'received',0,'2026-09-28 12:00:00','$hash')");sql($db,"INSERT INTO {$t->provider_identities()}(provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,payload_sha256,event_type,environment,disposition) VALUES('stripe_checkout','event','evt_unrelated',NULL,'unresolved','native',1,'2026-09-28 12:00:00','$hash','checkout.session.completed','test','received')");
	$cart_before=serialize($db->get_row("SELECT * FROM {$t->carts()} WHERE id=1"));$stock_before=serialize($db->get_results("SELECT * FROM {$db->postmeta} ORDER BY meta_id"));
	$result=deletion_service($db)->delete(1);check($result->is_success()&&OrderDeletionResult::DELETED===$result->code(),'paid aggregate with all attempt and reservation states deletes atomically');
	check(0===(int)$db->get_var("SELECT COUNT(*) FROM {$t->orders()} WHERE id=1")&&0===(int)$db->get_var("SELECT COUNT(*) FROM {$t->payment_sessions()} WHERE order_id=1")&&0===(int)$db->get_var("SELECT COUNT(*) FROM {$t->order_items()} WHERE order_id=1")&&0===(int)$db->get_var("SELECT COUNT(*) FROM {$t->reservations()} WHERE order_id=1"),'all target operational dependencies are absent');
	check(5===(int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_claims()} WHERE order_id=1 AND disposition='deleted' AND tombstoned_at IS NOT NULL"),'native claims survive permanently closed');
	check(2===(int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE identity_kind='event' AND identity_value IN ('evt_40','evt_41') AND disposition='neutralized_order_deleted'"),'associated, pre-association, and quarantined event identities are terminalized');
	check(1===(int)$db->get_var("SELECT COUNT(*) FROM {$t->payment_events()} WHERE id=99")&&'received'===$db->get_var("SELECT disposition FROM {$t->provider_identities()} WHERE identity_value='evt_unrelated'"),'unresolved unrelated event remains untouched');
	check($cart_before===serialize($db->get_row("SELECT * FROM {$t->carts()} WHERE id=1"))&&$stock_before===serialize($db->get_results("SELECT * FROM {$db->postmeta} ORDER BY meta_id")),'cart revision and physical stock are byte-for-byte unchanged');
	$order_b_after=serialize(array($db->get_row("SELECT * FROM {$t->orders()} WHERE id=90"),$db->get_results("SELECT * FROM {$t->order_items()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->reservations()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->payment_sessions()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->payment_events()} WHERE payment_session_id=290 ORDER BY id"),$db->get_results("SELECT * FROM {$t->provider_claims()} WHERE order_id=90 ORDER BY id"),$db->get_results("SELECT * FROM {$t->provider_identities()} WHERE claim_id=290 ORDER BY id"),$db->get_row("SELECT * FROM {$t->carts()} WHERE id=90"),$db->get_results("SELECT * FROM {$db->postmeta} WHERE post_id=110 ORDER BY meta_id")));
	check($order_b_before===$order_b_after,'deleting A leaves provider-connected order B and all operational/durable/cart/stock evidence byte-for-byte unchanged');
	$post_delete_tx=new TransactionManager($db,$t);check($post_delete_tx->begin_commerce_shared(),'post-delete provider evidence fixture is admitted');$post_delete_registry=new ProviderRegistry($db,$t);check('bound'===$post_delete_registry->bind('stripe_checkout','event','evt_after_delete',20,'2026-09-28 12:01:00',hash('sha256','after-delete'),'checkout.session.completed','test'),'provider evidence can append to a tombstoned claim');check($post_delete_tx->commit(),'post-delete provider evidence commits');check('deleted'===$db->get_var("SELECT disposition FROM {$t->provider_claims()} WHERE id=20")&&'20'===(string)$db->get_var("SELECT claim_id FROM {$t->provider_identities()} WHERE identity_value='evt_after_delete'"),'new evidence preserves the deleted claim and immutable ownership');
	$durable_before=serialize(array($db->get_row("SELECT * FROM {$t->order_deletions()} WHERE original_order_id=1"),$db->get_results("SELECT * FROM {$t->provider_claims()} WHERE order_id=1 ORDER BY id"),$db->get_results("SELECT i.* FROM {$t->provider_identities()} i JOIN {$t->provider_claims()} c ON c.id=i.claim_id WHERE c.order_id=1 ORDER BY i.id"),$db->get_row("SELECT * FROM {$t->carts()} WHERE id=1"),$db->get_results("SELECT * FROM {$db->postmeta} ORDER BY meta_id")));
	check(OrderDeletionResult::ALREADY_DELETED===deletion_service($db)->delete(1)->code(),'duplicate coherent deletion is idempotent');
	$durable_after=serialize(array($db->get_row("SELECT * FROM {$t->order_deletions()} WHERE original_order_id=1"),$db->get_results("SELECT * FROM {$t->provider_claims()} WHERE order_id=1 ORDER BY id"),$db->get_results("SELECT i.* FROM {$t->provider_identities()} i JOIN {$t->provider_claims()} c ON c.id=i.claim_id WHERE c.order_id=1 ORDER BY i.id"),$db->get_row("SELECT * FROM {$t->carts()} WHERE id=1"),$db->get_results("SELECT * FROM {$db->postmeta} ORDER BY meta_id")));
	check($durable_before===$durable_after,'duplicate deletion preserves deletion timestamp, tombstones, identities, cart, and stock byte-for-byte');
	check(OrderDeletionResult::ABSENCE_UNPROVABLE===deletion_service($db)->delete(777)->code(),'missing order without evidence is non-success absence_unprovable and fabricates no tombstone');
	$at='2026-09-28 12:00:00';$ref=str_repeat('e',32);$key=str_repeat('f',64);sql($db,"INSERT INTO {$t->provider_claims()}(installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,environment,evidence_state,disposition,created_at) VALUES(REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout','$ref',778,778,'$key','c2c_ps_$ref',2,'test','native','open','$at')");check(OrderDeletionResult::INCONSISTENT_STATE===deletion_service($db)->delete(778)->code()&&0===(int)$db->get_var("SELECT COUNT(*) FROM {$t->order_deletions()} WHERE original_order_id=778"),'missing order with open durable provider evidence fails closed without fabricating deletion evidence');

	fixture_order($db,$t,2,true);add_item($db,$t,2,10,110,null);check(OrderDeletionResult::DELETED===deletion_service($db)->delete(2)->code(),'cart-backed order with no payment session deletes');

	fixture_order($db,$t,3,true);add_item($db,$t,3,11,109,'active');[$ref,$ps,$pi]=add_attempt($db,$t,3,30,'failed');add_event($db,$t,50,30,$ref,$ps,$pi,30);
	// Contradictory durable event binding to another order fails closed.
	fixture_order($db,$t,4,true);add_item($db,$t,4,12,108,null);add_attempt($db,$t,4,31,'failed');sql($db,"UPDATE {$t->provider_identities()} SET claim_id=31 WHERE identity_kind='event' AND identity_value='evt_50'");$before=deletion_snapshot($db,$t,3);$conflict=deletion_service($db)->delete(3);check(!$conflict->is_success()&&OrderDeletionResult::EVIDENCE_CONFLICT===$conflict->code()&&$before===deletion_snapshot($db,$t,3),'cross-order event evidence fails closed with full rollback');
	sql($db,"UPDATE {$t->provider_identities()} SET claim_id=30 WHERE identity_kind='event' AND identity_value='evt_50'");

	fixture_order($db,$t,5,true);add_item($db,$t,5,13,105,null);sql($db,"UPDATE {$t->orders()} SET epoch_id=2 WHERE id=5");check(OrderDeletionResult::EVIDENCE_CONFLICT===deletion_service($db)->delete(5)->code(),'wrong order epoch ownership fails closed');
	fixture_order($db,$t,6,true);add_item($db,$t,6,14,105,'active');sql($db,"UPDATE {$t->reservations()} SET post_id=999 WHERE order_id=6");check(OrderDeletionResult::EVIDENCE_CONFLICT===deletion_service($db)->delete(6)->code(),'contradictory reservation ownership fails closed');
	fixture_order($db,$t,7,true);add_item($db,$t,7,15,105,null);add_attempt($db,$t,7,32,'failed');sql($db,"UPDATE {$t->payment_sessions()} SET epoch_id=2 WHERE id=32");check(OrderDeletionResult::EVIDENCE_CONFLICT===deletion_service($db)->delete(7)->code(),'contradictory payment-session ownership fails closed');
	fixture_order($db,$t,8,true);add_item($db,$t,8,16,105,null);add_attempt($db,$t,8,33,'failed');sql($db,"UPDATE {$t->provider_claims()} SET installation_id=REPEAT('f',32) WHERE id=33");check(OrderDeletionResult::EVIDENCE_CONFLICT===deletion_service($db)->delete(8)->code(),'wrong claim installation ownership fails closed');
	fixture_order($db,$t,9,true);add_item($db,$t,9,17,105,null);add_attempt($db,$t,9,34,'failed');sql($db,"DELETE FROM {$t->provider_claims()} WHERE id=34");check(OrderDeletionResult::EVIDENCE_CONFLICT===deletion_service($db)->delete(9)->code(),'missing mandatory provider claim fails closed');
	fixture_order($db,$t,92,true);add_item($db,$t,92,192,104,null);fixture_order($db,$t,91,true);add_item($db,$t,91,191,104,null);check(OrderDeletionResult::DELETED===deletion_service($db)->delete(91)->code()&&1===(int)$db->get_var("SELECT COUNT(*) FROM {$t->orders()} WHERE id=92")&&1===(int)$db->get_var("SELECT COUNT(*) FROM {$t->order_items()} WHERE order_id=92"),'successful deletion leaves another order and its history intact');

	foreach(['c2c_order_deletions','c2c_provider_claims','c2c_provider_identities','c2c_payment_events','c2c_payment_sessions','c2c_reservations','c2c_order_items','c2c_orders'] as $needle){
		$id=100+array_search($needle,['c2c_order_deletions','c2c_provider_claims','c2c_provider_identities','c2c_payment_events','c2c_payment_sessions','c2c_reservations','c2c_order_items','c2c_orders'],true);fixture_order($db,$t,$id,true);add_item($db,$t,$id,200+$id,107,'active');[$r,$s,$p]=add_attempt($db,$t,$id,300+$id,'failed');add_event($db,$t,400+$id,300+$id,$r,$s,$p,300+$id);$before=deletion_snapshot($db,$t,$id);$db->fail_match=$needle;$failed=deletion_service($db)->delete($id);check(!$failed->is_success()&&$before===deletion_snapshot($db,$t,$id),"$needle failure rolls the complete aggregate back");
	}

	// A deletion identity alongside surviving operational state is impossible.
	fixture_order($db,$t,600,true);sql($db,"INSERT INTO {$t->order_deletions()}(installation_id,epoch_id,epoch_identity,original_order_id,deleted_at,checkout_disposition,cart_id,cart_revision,checkout_generation,checkout_attempt_hash) VALUES(REPEAT('a',32),1,REPEAT('b',32),600,'2026-09-28 12:00:00','revision',600,7,3,REPEAT('d',64))");check(OrderDeletionResult::INCONSISTENT_STATE===deletion_service($db)->delete(600)->code(),'deletion evidence with a surviving order fails closed');

	foreach(['before','after'] as $loss){$id='before'===$loss?700:701;fixture_order($db,$t,$id,true);add_item($db,$t,$id,800+$id,106,'active');$probe=new DeletionCommitLossDb($prefix);$probe->loss=$loss;$unknown=deletion_service($probe)->delete($id);check(OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN===$unknown->code()&&!$probe->transaction_connection_usable(),"$loss commit acknowledgement loss poisons the deletion connection");$probe->close();$present=(int)$db->get_var("SELECT COUNT(*) FROM {$t->orders()} WHERE id=$id");$evidence=(int)$db->get_var("SELECT COUNT(*) FROM {$t->order_deletions()} WHERE original_order_id=$id");check(('before'===$loss&&1===$present&&0===$evidence)||('after'===$loss&&0===$present&&1===$evidence),"$loss commit loss yields complete rollback or complete committed deletion");$retry=deletion_service($db)->delete($id);check(('before'===$loss?OrderDeletionResult::DELETED:OrderDeletionResult::ALREADY_DELETED)===$retry->code(),"$loss commit loss is resolved safely on a fresh connection");}
} finally { foreach(array_unique($created) as $table){$db->query('DROP TABLE IF EXISTS '.$table);} $db->close(); }
echo "All atomic order-deletion service checks passed.\n";
