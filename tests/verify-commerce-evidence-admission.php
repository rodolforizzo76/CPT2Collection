<?php
/** Real SQL historical admission, concrete isolation, registry and failure boundaries. */
declare( strict_types=1 );
require __DIR__ . '/support/historical-evidence.php';
use RodolfoRizzo\Cpt2Collection\{CommerceEvidenceContext,CommerceContext,CommerceResetContext,CommerceResetRecord,ProviderClaimRecord,ProviderRegistry,TransactionManager,TableNames,CommerceResetRepository,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,PaymentEventRepository,AdminStockRepository,LockedCatalogReader,OrderDeletionRegistry,EpochOwnership};
class EvidenceFailureDb extends wpdb {
	public array $trace = array(); public string $failure = ''; public string $boundary = ''; public string $kind = 'error';
	public function get_results( string $query ): ?array {
		$this->trace[] = $query;
		if ( '' !== $this->failure && str_contains( $query, $this->failure ) ) {
			if ( 'throw' === $this->kind ) { throw new RuntimeException( 'injected read failure' ); }
			if ( 'null' === $this->kind ) { return null; }
			$this->last_error = 'injected ambiguity'; return array();
		}
		return parent::get_results( $query );
	}
	public function query( string $query ): int|false {
		if ( $query === $this->boundary ) { $this->last_error = 'injected uncertain boundary'; return false; }
		return parent::query( $query );
	}
}
$prefix = 'c2c_p42b_' . bin2hex( random_bytes(5) ) . '_'; $db = new EvidenceFailureDb( $prefix ); $t = new TableNames( $db );
$at = '2026-01-02 00:00:00';
try {
	$expected = evidence_fixture( $db ); $reset = $expected->operation(); $tx = new TransactionManager( $db, $t ); $registry = new ProviderRegistry( $db, $t );
	$bind = function( $context = null, $provider = 'stripe_checkout', $kind = 'session', $value = 'cs_late', $environment = 'test', $hash = null, $type = null ) use ($registry, &$expected, $at) { return $registry->bind_historical( $context ?? $expected, $provider, $kind, $value, $at, $environment, $hash, $type ); };
	check( 'database_error' === $bind(), 'constructed evidence context alone grants no authority' );
	check( $tx->begin() && 'database_error' === $bind(), 'bare transaction cannot write historical evidence' ); $tx->rollback();
	sql( $db, "UPDATE {$t->commerce_control()} SET state='ready'" );
	check( $tx->begin_commerce_shared() && null === $tx->evidence_context() && 'database_error' === $bind(), 'normal authority cannot call evidence-only operation' );
	$order = (new OrderRepository($db,$t))->find(1); $session = (new PaymentSessionRepository($db,$t))->find(1); $normal = $tx->commerce_context(); $tx->commit();
	check( !$tx->begin_historical_evidence($expected), 'ready rejects evidence mode' );
	sql( $db, "UPDATE {$t->commerce_control()} SET state='resetting'" );
	check( $tx->begin_reset_maintenance($reset) && 'database_error' === $bind() && null === $tx->evidence_context(), 'maintenance cannot impersonate evidence' ); $tx->rollback();
	$before = evidence_preserved_snapshot($db); $db->trace = array();
	check( $tx->begin_historical_evidence($expected), 'exact reset boundary and native claim admit evidence' );
	$locks = array_values(array_filter($db->trace,fn($q)=>str_contains($q,'FOR UPDATE')||str_contains($q,'LOCK IN SHARE MODE')));
	check(str_contains($locks[0],'c2c_commerce_control'),'baseline control lock precedes runtime locks');array_shift($locks);
	check(4===count($locks)&&str_contains($locks[0],'c2c_commerce_control')&&str_contains($locks[1],'c2c_commerce_epochs')&&str_contains($locks[2],'c2c_commerce_resets')&&str_contains($locks[3],'c2c_provider_claims')&&str_contains($locks[0],'LOCK IN SHARE MODE')&&str_contains($locks[1],'LOCK IN SHARE MODE')&&str_contains($locks[2],'FOR UPDATE')&&str_contains($locks[3],'FOR UPDATE'),'shared control/epoch then exclusive reset/claim, without operational locks');
	check(null===$tx->commerce_context()&&null===$tx->reset_context()&&!TransactionManager::admitted_on($db)&&!$tx->is_commerce_admitted(),'evidence exposes neither normal nor maintenance authority');
	foreach([$normal,new CommerceResetContext($reset)] as $other){$rejected=false;try{$other->equals($tx->evidence_context());}catch(TypeError $error){$rejected=true;}check($rejected,'existing context equality rejects evidence type');}
	check(!$tx->with_commerce_context($normal,fn()=>$tx->begin_commerce_shared($normal)),'manually supplied normal context cannot authorize evidence owner');
	check(false===(new CartRepository($db,$t))->touch(1,$at,$at,$at),'cart write denied');
	check(false===(new OrderRepository($db,$t))->create(1,'pending','EUR',100,100,$at,$at),'order creation denied');
	check(false===(new PaymentSessionRepository($db,$t))->create_attempt(str_repeat('f',32),1,'stripe_checkout',str_repeat('f',64),'EUR',100,$at),'payment session creation denied');
	check(false===(new ReservationRepository($db,$t))->create(1,1,10,1,'active',$at,$at,$at),'reservation creation denied');
	check(false===(new OrderRepository($db,$t))->update_status(1,'paid','cancelled',$at),'order write denied');
	check(false===(new OrderRepository($db,$t))->insert_item(1,10,'Forbidden',100,1,100,100,'EUR',$at),'order-item creation denied');
	check(false===(new ReservationRepository($db,$t))->update_status(1,'converted','released',$at),'reservation write denied');
	check(false===(new PaymentSessionRepository($db,$t))->mark_failed(1,$at),'payment session write denied');
	check(false===(new PaymentEventRepository($db,$t))->quarantine_ownership(1),'payment event mutation denied');
	$receipt=(new PaymentEventRepository($db,$t))->insert_receipt('stripe_checkout','evt_forbidden','checkout.session.completed',null,false,$at,str_repeat('a',64));
	check('invalid_input'===$receipt->outcome(),'operational event creation denied');
	check(false===(new OrderDeletionRegistry($db,$t))->persist($order,$at),'deletion evidence write denied');
	check(false===(new CommerceResetRepository($db,$t))->find_for_maintenance(new CommerceResetContext($reset))&&false===(new CommerceResetRepository($db,$t))->find_active(true),'maintenance and normal reset repository access denied');
	check(false===(new AdminStockRepository($db,$tx))->set_exact(10,1,7,6)&&false===(new AdminStockRepository($db,$tx))->initialize(10)&&false===(new LockedCatalogReader($db,$tx))->decrement_stock(10,7,1),'stock set/initialize/decrement denied');
	check(false===$registry->create_claim($session,$normal,null,'test',$at),'manually supplied context cannot authorize new claim');
	check('database_error'===$registry->bind('stripe_checkout','session','cs_wrong',1,$at)&&!$registry->terminal('stripe_checkout','evt_old','changed'),'normal registry binding and terminalization denied');
	$nested=new TransactionManager($db,$t);check(!$nested->begin_historical_evidence($expected)&&!$nested->begin_commerce_shared($normal)&&!$nested->begin_reset_maintenance($reset),'nested modes cannot borrow connection ownership');
	$other=new wpdb($prefix);check('database_error'===(new ProviderRegistry($other,new TableNames($other)))->bind_historical($expected,'stripe_checkout','session','cs_other',$at,'test'),'context cannot authorize another connection');$other->close();
	check('database_error'===$bind(null,'other'),'wrong provider rejected');
	check('conflict'===$bind(null,'stripe_checkout','session','cs_live','live'),'claim environment mismatch rejected without inventing identity');
	check('database_error'===$bind(null,'stripe_checkout','event','evt_missing'),'event requires hash and type');
	check('bound'===$bind(),'late Session durably observed');
	check('bound'===$bind(null,'stripe_checkout','payment','pi_late'),'late PaymentIntent durably observed');
	check('bound'===$bind(null,'stripe_checkout','event','evt_late','test',str_repeat('a',64),'checkout.session.completed'),'late Event durably observed');
	$identities=serialize($db->get_results('SELECT * FROM '.$t->provider_identities().' ORDER BY id'));
	check('bound'===$bind()&&'bound'===$bind(null,'stripe_checkout','event','evt_late','test',str_repeat('a',64),'checkout.session.completed'),'duplicate observations idempotent');
	check($identities===serialize($db->get_results('SELECT * FROM '.$t->provider_identities().' ORDER BY id')),'duplicates preserve first_seen, owner and all evidence');
	check('conflict'===$bind(null,'stripe_checkout','event','evt_late','test',str_repeat('b',64),'checkout.session.completed'),'payload hash conflict quarantines');
	check('conflict'===$bind(null,'stripe_checkout','event','evt_late','test',str_repeat('a',64),'checkout.session.completed'),'quarantine cannot be cleared by coherent replay');
	$db->failure='c2c_provider_identities';$db->kind='error';
	check('database_error'===$bind(null,'stripe_checkout','session','cs_read_failure'),'identity SQL read failure grants no binding');$db->failure='';
	check($tx->commit()&&null===$tx->evidence_context()&&'database_error'===$bind(),'commit releases evidence authority');
	check($before===evidence_preserved_snapshot($db),'evidence and rejected writes preserve operational, stock, claim, deletion, reset and foundation rows');

	$expected=evidence_context($db); $reset=$expected->operation();
	$foreign = (array)$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id=1');
	$foreign['id']=99; $foreign['reference']=str_repeat('2',32); $foreign['order_id']=2; $foreign['payment_session_id']=2; $foreign['creation_key_hash']=str_repeat('2',64); $foreign['idempotency_key']='c2c_ps_'.str_repeat('2',32);
	$db->insert($t->provider_claims(),$foreign,array_map(static fn($v)=>is_int($v)?'%d':'%s',$foreign)); native_provider_claim_fixture($db,99);
	// Existing terminal/quarantine/ownership evidence must survive all observations.
	foreach (array('terminal'=>'processed','foreign'=>'pending','quarantine'=>'neutralized_conflict','unresolved'=>'pending','older'=>'pending','environment'=>'pending','type'=>'pending') as $label=>$disposition) {
		$owner='foreign'===$label?99:('unresolved'===$label||'older'===$label?null:1);
		$binding='quarantine'===$label?'quarantined':(null===$owner?'unresolved':'bound');
		$db->insert($t->provider_identities(),array('provider'=>'stripe_checkout','identity_kind'=>'event','identity_value'=>'evt_'.$label,'claim_id'=>$owner,'binding_state'=>$binding,'evidence_state'=>'native','observed_epoch_id'=>'older'===$label?0:1,'first_seen_at'=>$at,'payload_sha256'=>str_repeat('a',64),'event_type'=>'checkout.session.completed','environment'=>'environment'===$label?'live':'test','disposition'=>$disposition,'conflict_code'=>'quarantine'===$label?'existing_conflict':null),array('%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s'));
		$original=$registry->identity('stripe_checkout','event','evt_'.$label);
		$expected=evidence_context($db);
		check($tx->begin_historical_evidence($expected),'identity fixture admission '.$label);
		$result=$bind(null,'stripe_checkout','event','evt_'.$label,'test',str_repeat('a',64),'type'===$label?'checkout.session.expired':'checkout.session.completed');
		check((in_array($label,['terminal','unresolved'],true)?'bound':'conflict')===$result&&$tx->commit(),$label.' identity keeps immutable registry semantics');
		$after=$registry->identity('stripe_checkout','event','evt_'.$label);
		check(('unresolved'===$label ? '1'===(string)$after->claim_id : $original->claim_id===$after->claim_id)&&$original->first_seen_at===$after->first_seen_at&&$original->disposition===$after->disposition&&$original->payload_sha256===$after->payload_sha256&&$original->environment===$after->environment&&$original->event_type===$after->event_type,$label.' observation preserves attributable ownership/first_seen/terminal/environment/payload');
		if('quarantine'===$label){check('existing_conflict'===$after->conflict_code,'existing conflict code retained');}
	}
	$expected=evidence_context($db); $reset=$expected->operation();
	check($tx->begin_historical_evidence($expected)&&'bound'===$bind(null,'stripe_checkout','session','cs_rollback')&&$tx->rollback(),'evidence binding can roll back');
	check(null===$registry->identity('stripe_checkout','session','cs_rollback')&&null===$tx->evidence_context(),'rollback leaves no observation or authority');
	// Every captured field is immutable; a different claim/context cannot borrow it.
	$raw=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id=1');
	foreach(['id'=>'18446744073709551615','epoch_id'=>'01','reference'=>str_repeat('A',32),'environment'=>null,'disposition'=>'quarantined','tombstoned_at'=>$at,'created_at'=>'2026-02-30 00:00:00','request_snapshot'=>'{}','request_version'=>1] as $field=>$value){
		$bad=clone $raw;$bad->$field=$value;$rejected=false;try{ProviderClaimRecord::from_row($bad);}catch(Throwable $error){$rejected=true;}check($rejected,'claim parser rejects '.$field);
	}
	$bad=clone $raw;unset($bad->tombstoned_at);$rejected=false;try{ProviderClaimRecord::from_row($bad);}catch(Throwable $error){$rejected=true;}check($rejected,'missing nullable claim field rejected');
	$copy=clone $raw;$immutable=ProviderClaimRecord::from_row($copy);$copy->provider='other';check($immutable->equals($expected->claim()),'claim snapshot cannot mutate through input object');
	foreach(['id'=>2,'provider'=>'other','reference'=>str_repeat('f',32),'order_id'=>2,'payment_session_id'=>2,'creation_key_hash'=>str_repeat('f',64),'installation_id'=>str_repeat('f',32),'epoch_id'=>2,'epoch_identity'=>str_repeat('f',32)] as $field=>$value){
		$wrong=clone $raw;$wrong->$field=$value;
		$request=json_decode($wrong->request_snapshot,true);$request['reference']=$wrong->reference;$request['idempotency_key']='c2c_ps_'.$wrong->reference;$request['configuration']['installation_id']=$wrong->installation_id;$request['configuration']['epoch_identity']=$wrong->epoch_identity;$wrong->idempotency_key=$request['idempotency_key'];$wrong->request_snapshot=json_encode($request);
		$context=new CommerceEvidenceContext($reset,ProviderClaimRecord::from_row($wrong));
		check(!$tx->begin_historical_evidence($context)&&!$tx->is_active(),'wrong captured '.$field.' fails admission');
		check($tx->begin_historical_evidence($expected)&&'database_error'===$bind($context),'wrong captured '.$field.' cannot borrow existing evidence owner');$tx->rollback();
	}
	$resetraw=$db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id=1');
	foreach(['operation_identity'=>str_repeat('f',32),'revision'=>1,'source_epoch_id'=>2,'source_epoch_identity'=>str_repeat('f',32),'installation_id'=>str_repeat('f',32)] as $field=>$value){$wrong=clone $resetraw;$wrong->$field=$value;check(!$tx->begin_historical_evidence(new CommerceEvidenceContext(CommerceResetRecord::from_row($wrong),$expected->claim())),'stale/wrong reset '.$field.' fails');}
	foreach(['provider'=>'other','installation_id'=>str_repeat('f',32),'epoch_id'=>2,'epoch_identity'=>str_repeat('f',32),'reference'=>str_repeat('f',32),'created_at'=>'2026-01-02 00:00:00','environment'=>null,'evidence_state'=>'quarantined','disposition'=>'unknown','request_snapshot'=>'{','order_id'=>0] as $field=>$value){
		$db->update($t->provider_claims(),[$field=>$value],['id'=>1],['%s'],['%d']);
		check(!$tx->begin_historical_evidence($expected)&&!$tx->is_active(),'malformed/changed durable '.$field.' rejected');
		$db->update($t->provider_claims(),[$field=>$raw->$field],['id'=>1],['%s'],['%d']);
	}
	// Matching native caller data still cannot legitimize a foreign source or late creation.
	foreach(['installation_id'=>str_repeat('f',32),'epoch_id'=>2,'epoch_identity'=>str_repeat('f',32),'created_at'=>'2026-01-02 00:00:00'] as $field=>$value){
		$wrong=clone $raw;$wrong->$field=$value;
		$request=json_decode($wrong->request_snapshot,true);$request['configuration']['installation_id']=$wrong->installation_id;$request['configuration']['epoch_identity']=$wrong->epoch_identity;$wrong->request_snapshot=json_encode($request);
		$db->update($t->provider_claims(),get_object_vars($wrong),['id'=>1],array_fill(0,count(get_object_vars($wrong)),'%s'),['%d']);
		$bad=evidence_context($db);check(!$tx->begin_historical_evidence($bad),'matching native expectation cannot legitimize durable '.$field);
		$db->update($t->provider_claims(),get_object_vars($raw),['id'=>1],array_fill(0,count(get_object_vars($raw)),'%s'),['%d']);
	}
	foreach(['deleted','retired'] as $lifecycle){
		sql($db,"UPDATE {$t->provider_claims()} SET disposition='$lifecycle',tombstoned_at='2026-01-01' WHERE id=1");$historical=evidence_context($db);$snapshot=evidence_preserved_snapshot($db);
		check($tx->begin_historical_evidence($historical)&&'bound'===$bind($historical,'stripe_checkout','session','cs_'.$lifecycle)&&$tx->commit(),$lifecycle.' claim accepts attributable evidence');
		check($snapshot===evidence_preserved_snapshot($db),$lifecycle.' lifecycle/tombstone never reopened');
	}
	$legacy=clone $raw;$legacy->request_version=1;$legacy->request_snapshot=null;$legacy->evidence_state='legacy_incomplete';
	$rejected=false;try{ProviderClaimRecord::from_row($legacy);}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'legacy representation cannot construct modern historical authority');
	$db->update($t->provider_claims(),get_object_vars($raw),['id'=>1],array_fill(0,count(get_object_vars($raw)),'%s'),['%d']);
	$expected=evidence_context($db); $reset=$expected->operation();
	foreach(['ready','migrating_v13','unknown'] as $state){sql($db,"UPDATE {$t->commerce_control()} SET state='$state'");check(!$tx->begin_historical_evidence($expected),$state.' rejects evidence admission');}sql($db,"UPDATE {$t->commerce_control()} SET state='resetting'");
	foreach(['c2c_commerce_control','WHERE id =','WHERE identity=','WHERE active_slot','c2c_provider_claims'] as $failure){foreach(['error','null','throw'] as $kind){$db->failure=$failure;$db->kind=$kind;check(!$tx->begin_historical_evidence($expected)&&!$tx->is_active(),$kind.' failure at '.$failure.' grants no authority');}$db->failure='';}
	sql($db,"UPDATE {$t->provider_claims()} SET id=2 WHERE id=1");check(!$tx->begin_historical_evidence($expected),'missing exact claim rejected');sql($db,"UPDATE {$t->provider_claims()} SET id=1 WHERE id=2");
	foreach(['provider_claims','provider_identities'] as $method){sql($db,'ALTER TABLE '.$t->$method().' ENGINE=MyISAM');check(!$tx->begin_historical_evidence($expected),'nontransactional '.$method.' rejected');sql($db,'ALTER TABLE '.$t->$method().' ENGINE=InnoDB');}
	check($tx->begin_historical_evidence($expected),'poison fixture admitted');$db->poison_transaction();check(null===$tx->evidence_context()&&'database_error'===$bind(),'poison revokes evidence writes');$tx->rollback();$db->close();
	foreach(['START TRANSACTION','COMMIT','ROLLBACK'] as $boundary){$db=new EvidenceFailureDb($prefix);$tx=new TransactionManager($db,$t);if('START TRANSACTION'!==$boundary){check($tx->begin_historical_evidence($expected),'uncertainty fixture admitted');}$db->boundary=$boundary;$ok=match($boundary){'START TRANSACTION'=>$tx->begin_historical_evidence($expected),'COMMIT'=>$tx->commit(),'ROLLBACK'=>$tx->rollback()};check(!$ok&&$tx->is_outcome_uncertain()&&null===$tx->evidence_context()&&!$tx->begin_historical_evidence($expected)&&!$tx->begin_commerce_shared()&&!$tx->begin_reset_maintenance($reset),$boundary.' uncertainty revokes all authority');$db->close();}
} finally {try{$db->close();}catch(Throwable $ignored){}maintenance_cleanup(new wpdb($prefix));}
echo "All historical evidence admission/isolation checks passed.\n";
