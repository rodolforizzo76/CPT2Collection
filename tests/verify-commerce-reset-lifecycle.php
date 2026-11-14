<?php
/** Production reset orchestration against populated MariaDB fixtures. */
declare( strict_types=1 );
require __DIR__ . '/support/historical-evidence.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetService,CommerceResetRepository,CommerceResetRecord,CommerceResetLifecycle,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry,TransactionManager,TableNames,UtcClock,CartRepository,LockedCatalogReader};
function service(wpdb $db): CommerceResetService { $t=new TableNames($db); return new CommerceResetService(new CommerceResetRepository($db,$t),new TransactionManager($db,$t),new UtcClock()); }
function preserved(wpdb $db): string { $rows=unserialize(maintenance_snapshot($db));unset($rows['commerce_control'],$rows['commerce_resets']);return serialize($rows); }
class ResetFailureDb extends wpdb {
	public string $fail=''; public string $boundary=''; public string $read_failure=''; public bool $lose_commit=false;
	public function get_results(string $query):?array { if($this->read_failure!==''&&str_contains($query,$this->read_failure)){$this->last_error='injected read failure';return null;}return parent::get_results($query); }
	public function query(string $query):int|false {
		if($query===$this->boundary){$this->last_error='uncertain boundary';return false;}
		if($this->lose_commit&&'COMMIT'===$query){parent::query($query);$this->last_error='lost commit acknowledgement';return false;}
		if($this->fail!==''&&str_contains($query,$this->fail)){$this->last_error='injected database failure';return false;}
		return parent::query($query);
	}
}
$prefix='c2c_p43_'.bin2hex(random_bytes(5)).'_';$db=new ResetFailureDb($prefix);$t=new TableNames($db);
try {
	evidence_fixture($db);sql($db,"DELETE FROM {$t->commerce_resets()}");sql($db,"UPDATE {$t->commerce_control()} SET state='ready'");
	$before=preserved($db);$svc=service($db);$source=(string)$db->get_var("SELECT identity FROM {$t->commerce_epochs()} WHERE id=1");$identity=str_repeat('7',32);
	check('not_found'===$svc->resume($identity)->code(),'locked absence before start');
	$guard_tx=new TransactionManager($db,$t);$guard_repo=new CommerceResetRepository($db,$t);
	foreach(['bare','normal'] as $mode){
		check('bare'===$mode?$guard_tx->begin():$guard_tx->begin_commerce_exclusive(),$mode.': guard fixture transaction');
		check(false===$guard_repo->resolve($identity)&&false===$guard_repo->start($identity,$source,9,gmdate('Y-m-d H:i:s')),$mode.': cannot borrow control-only reset authority');$guard_tx->rollback();
	}

	foreach([['X',$source],[$identity,str_repeat('B',32)]] as [$op,$epoch]){check('invalid_request'===$svc->start($op,$epoch)->code(),'canonical identities required');}
	check('invalid_request'===$svc->start($identity,$source,0)->code(),'requester must be positive or null');
	check('conflict'===$svc->start($identity,str_repeat('8',32))->code(),'stale expected epoch rejected');
	$db->fail='INSERT INTO';check('database_error'===$svc->start($identity,$source,9)->code(),'insert failure confirms rollback');$db->fail='';
	$db->fail="SET state='resetting'";check('database_error'===$svc->start($identity,$source,9)->code(),'fence failure rolls intent back');$db->fail='';
	check('not_found'===$svc->resume($identity)->code(),'confirmed rollback has no intent');
	$r=$svc->start($identity,$source,9);check('started'===$r->code(),'atomic start succeeds');$record=$r->operation();
	check($record->source_epoch_id()===1&&$record->source_epoch_identity()===$source&&$record->operation_identity()===$identity&&$record->requested_by()===9,'exact immutable source and request');
	check($record->installation_id()===$db->get_var("SELECT installation_id FROM {$t->commerce_control()}"),'installation preserved');
	check($record->status()==='running'&&$record->phase()==='validating'&&$record->revision()===0&&$record->cursor()===0&&$record->checkpoint()===null&&$record->error_code()===null&&$record->completed_at()===null&&$record->active_slot()===1&&$record->started_at()===$record->updated_at(),'canonical initial durable lifecycle');
	check(CommerceResetLifecycle::identity_valid($record->target_epoch_identity())&&$record->target_epoch_identity()!==$source&&null===$record->target_epoch_id(),'distinct canonical reserved target, no numeric allocation');
	check('resetting'===$db->get_var("SELECT state FROM {$t->commerce_control()}"),'committed fence');
	$retry=$svc->start($identity,$source,9);check('existing'===$retry->code()&&$record->equals($retry->operation()),'same request preserves every field and reservation');
	check('conflict'===$svc->start($identity,$source,10)->code()&&'conflict'===$svc->start($identity,str_repeat('8',32),9)->code(),'same identity conflicting requester/source rejects');
	check('conflict'===$svc->start(str_repeat('8',32),$source)->code(),'different active operation conflicts');
	check('running'===$svc->resume($identity,$source)->code()&&'conflict'===$svc->resume($identity,str_repeat('8',32))->code(),'locked resume and stale source distinguished');
	foreach(['deleting','completed','validating'] as $phase){check('invalid_request'===$svc->advance($record,$phase)->code(),'skip/repeat/completion transition rejected');}
	$next=$svc->checkpoint($record,'scan',41);check('progressed'===$next->code(),'checkpoint progresses');$next=$next->operation();
	check($next->revision()===1&&$next->cursor()===41&&$next->checkpoint()==='scan','cursor checkpoint revision atomically advance once');
	check('stale'===$svc->checkpoint($record,'scan',42)->code(),'stale snapshot rejects');$record=$next;
	$claim=ProviderClaimRecord::from_row($db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id=1"));
	foreach(CommerceResetLifecycle::PHASES as $index=>$phase){
		if($index){$previous=$record;
   if($index===1){
	check('invalid_request'===$svc->advance($record,$phase)->code(),'A1 semantic proof is required to leave validating');
	// Future-phase authority matrix only. This old synthetic fixture is not an A1 certificate.
	sql($db,"UPDATE {$t->commerce_resets()} SET phase='retiring_claims',checkpoint=NULL,`cursor`=0,revision=revision+1 WHERE id={$record->id()}");$record=$svc->resume($identity)->operation();
   }elseif(in_array($previous->phase(),['retiring_claims','terminalizing_events','deleting','finalizing'],true)){
	check('invalid_request'===$svc->advance($record,$phase)->code(),'B semantic proof is required to leave '.$previous->phase());
	// Explicit future-phase admission fixture, not a claim of semantic B completion.
	sql($db,$db->prepare("UPDATE {$t->commerce_resets()} SET phase=%s, checkpoint=NULL, `cursor`=0, revision=revision+1 WHERE id=%d",$phase,$record->id()));
	$record=$svc->resume($record->operation_identity())->operation();
   }else{$r=$svc->advance($record,$phase);check('progressed'===$r->code(),'legal metadata transition to '.$phase);$record=$r->operation();}
   check($record->revision()===$previous->revision()+1&&null===$record->checkpoint()&&0===$record->cursor(),'transition clears checkpoint and cursor once');check('invalid_request'===$svc->advance($record,$previous->phase())->code(),'backward phase rejected');}
		$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
		check(!$tx->begin_commerce_shared()&&!$tx->begin_commerce_exclusive(),$phase.': both normal modes fenced');
		check($tx->begin_reset_maintenance($record),$phase.': running maintenance admitted');
		check(null===TransactionManager::reset_control_on($db)&&null===$tx->evidence_context()&&null===$tx->commerce_context(),$phase.': maintenance authority isolated');
		check(false===(new CartRepository($db,$t))->touch(1,'x','x','x')&&false===(new LockedCatalogReader($db,$tx))->decrement_stock(10,7,1),$phase.': operational and stock writes denied');$tx->rollback();
		$evidence=new CommerceEvidenceContext($record,$claim);check($tx->begin_historical_evidence($evidence),$phase.': evidence admitted');
		check(null===$tx->reset_context()&&null===$tx->commerce_context()&&false===$repo->progress($record,$phase,null,1,null,gmdate('Y-m-d H:i:s')),$phase.': evidence cannot progress reset');$tx->rollback();
		// Test all blocked phase combinations without adding a production unblock API.
		$sqlrow=$db->get_row("SELECT * FROM {$t->commerce_resets()} WHERE id={$record->id()}");
		$blocked=$svc->block($record,'integrity_conflict')->operation();check($blocked!==null&&$blocked->status()==='blocked'&&$blocked->phase()===$record->phase()&&$blocked->checkpoint()===$record->checkpoint()&&$blocked->cursor()===$record->cursor()&&$blocked->revision()===$record->revision()+1,$phase.': blocking preserves position');
		check(!$tx->begin_reset_maintenance($blocked)&&!$tx->begin_commerce_shared(),$phase.': blocked maintenance and commerce denied');
		check($tx->begin_historical_evidence(new CommerceEvidenceContext($blocked,$claim)),$phase.': blocked evidence survives');check('bound'===(new ProviderRegistry($db,$t))->bind_historical(new CommerceEvidenceContext($blocked,$claim),'stripe_checkout','session','cs_blocked_'.$phase,gmdate('Y-m-d H:i:s'),'test'),$phase.': blocked one-shot evidence can bind');$tx->rollback();
		check('conflict'===$svc->checkpoint($blocked,'scan',99)->code(),$phase.': blocked snapshot cannot silently unblock');
		check('blocked'===$svc->resume($identity)->code()&&'existing'===$svc->start($identity,$source,9)->code(),$phase.': resume/retry never unblock');
		check('stale'===$svc->checkpoint($record,'scan',99)->code(),$phase.': stale progression cannot cross block');
		$data=get_object_vars($sqlrow);unset($data['id']);$db->update($t->commerce_resets(),$data,['id'=>$record->id()],array_fill(0,count($data),'%s'),['%d']);
	}
	check('invalid_request'===$svc->advance($record,'completed')->code(),'finalizing cannot complete without epoch switch');
	$immutable=['operation_identity','installation_id','source_epoch_id','source_epoch_identity','target_epoch_identity','target_epoch_id','requested_by','started_at'];
	$first=$retry->operation();foreach($immutable as $method){check($first->$method()===$record->$method(),'immutable '.$method.' preserved');}
	check($before===preserved($db),'all operational/provider/deletion/epoch/stock rows preserved across start, progression, block');
	// Running error, blocked missing error, arbitrary phase/status, completed and boundary contradictions fail closed.
	foreach([['status'=>'validating'],['status'=>'blocked'],['error_code'=>'bad_evidence'],['phase'=>'unknown'],['status'=>'completed','phase'=>'completed'],['target_epoch_id'=>2],['active_slot'=>null],['installation_id'=>str_repeat('9',32)]] as $bad){
		$row=get_object_vars($db->get_row("SELECT * FROM {$t->commerce_resets()} WHERE id={$record->id()}"));
		$db->update($t->commerce_resets(),$bad,['id'=>$record->id()],array_fill(0,count($bad),'%s'),['%d']);
		check('inconsistent_state'===$svc->resume($identity)->code(),'contradictory lifecycle fails locked inspection');
		unset($row['id']);$db->update($t->commerce_resets(),$row,['id'=>$record->id()],array_fill(0,count($row),'%s'),['%d']);
	}
	$db->lose_commit=true;$unknown=$svc->block($record,'lost_block');check($unknown->requires_verification()&&'transaction_outcome_unknown'===$svc->resume($identity)->code(),'progress commit loss poisons owner and requires fresh inspection');$db->close();
	$db=new ResetFailureDb($prefix);$svc=service($db);$record=$svc->resume($identity)->operation();check($record->status()==='blocked'&&$record->error_code()==='lost_block','fresh connection recovers durable block without erasing phase proof');
	// Start response loss after real COMMIT, then recover exact reserved identity.
	sql($db,"DELETE FROM {$t->commerce_resets()}");sql($db,"UPDATE {$t->commerce_control()} SET state='ready'");$db->lose_commit=true;
	check($svc->start($identity,$source,9)->requires_verification(),'start commit acknowledgement lost after durable success');$db->close();
	$db=new ResetFailureDb($prefix);$svc=service($db);$recovered=$svc->resume($identity)->operation();check(null!==$recovered&&$recovered->equals($svc->start($identity,$source,9)->operation()),'fresh retry recovers exact original intent without new target');
	check('1'===(string)$db->get_var("SELECT COUNT(*) FROM {$t->commerce_resets()}"),'one intent after response loss');
	check($before===preserved($db),'response loss recovery preserves operational/provider/stock/source epoch');
	foreach(['START TRANSACTION','COMMIT','ROLLBACK'] as $boundary){
		sql($db,"DELETE FROM {$t->commerce_resets()}");sql($db,"UPDATE {$t->commerce_control()} SET state='ready'");
		$db->boundary=$boundary;if('ROLLBACK'===$boundary){$db->fail='INSERT INTO';}
		check($svc->start($identity,$source,9)->requires_verification(),$boundary.': unknown start cannot claim rollback');$db->close();
		$db=new ResetFailureDb($prefix);$svc=service($db);check('not_found'===$svc->resume($identity)->code(),$boundary.': fresh locked inspection after connection closes establishes absence');
	}
	$recovered=$svc->start($identity,$source,9)->operation();
	$db->read_failure='WHERE operation_identity';check('database_error'===$svc->resume($identity)->code(),'lookup database error is not absence');$db->read_failure='';
	$db->read_failure='WHERE id=';check('database_error'===$svc->checkpoint($recovered,'scan',1)->code(),'checkpoint read failure is not stale snapshot');$db->read_failure='';
	check($recovered->equals($svc->resume($identity)->operation()),'read failures roll back without lifecycle mutation');
	$tx=new TransactionManager($db,$t);$repo=new CommerceResetRepository($db,$t);
	check($tx->begin_reset_control()&&null===$tx->commerce_context()&&null===$tx->reset_context()&&null===$tx->evidence_context(),'control-only orchestration grants no operational/maintenance/evidence authority');
	check(false===(new CartRepository($db,$t))->touch(1,'x','x','x')&&false===(new LockedCatalogReader($db,$tx))->decrement_stock(10,7,1),'control-only start cannot mutate cart/stock');
	check(false===$repo->progress($recovered,'validating','scan',1,null,gmdate('Y-m-d H:i:s')),'control-only inspection cannot progress');$tx->rollback();
	check($tx->begin_reset_maintenance($recovered),'batch owner admitted');
	$tentative=$repo->progress($recovered,'validating','scan',55,null,gmdate('Y-m-d H:i:s'));check($tentative instanceof CommerceResetRecord,'batch checkpoint participates in caller transaction');
	check(false===$repo->progress($recovered,'validating','scan',56,null,gmdate('Y-m-d H:i:s')),'same snapshot cannot mutate twice within a transaction');$tx->rollback();
	check($recovered->equals($svc->resume($identity)->operation()),'batch rollback preserves exact prior snapshot');
	check('invalid_request'===$svc->block($recovered,'')->code()&&'invalid_request'===$svc->checkpoint($recovered,'bad token',1)->code()&&'invalid_request'===$svc->checkpoint($recovered,null,-1)->code(),'invalid machine codes and negative cursor rejected');
	// A fixture-only FUTURE epoch switch tests representation, not production completion.
	$at=gmdate('Y-m-d H:i:s');$target=$recovered->target_epoch_identity();
	sql($db,"INSERT INTO {$t->commerce_epochs()} VALUES(2,'$target','$at',NULL)");
	check('inconsistent_state'===$svc->resume($identity)->code(),'allocated target with active intent fails closed');
	sql($db,"UPDATE {$t->commerce_epochs()} SET retired_at='$at' WHERE id=1");
	sql($db,"UPDATE {$t->commerce_control()} SET state='ready',current_epoch_id=2");
	sql($db,"UPDATE {$t->commerce_resets()} SET status='completed',phase='completed',checkpoint='z1_n_0_done',`cursor`=0,validation_generation=evidence_generation,target_epoch_id=2,active_slot=NULL,completed_at='$at',updated_at='$at',revision=revision+1");
	$completed=$svc->resume($identity);check('completed'===$completed->code()&&$completed->is_success(),'completed state requires coherent durable source retirement and target switch evidence');
	check(!$tx->begin_reset_maintenance($completed->operation())&&!$tx->begin_historical_evidence(new CommerceEvidenceContext($completed->operation(),$claim)),'completed history grants neither reset authority');
	check('existing'===$svc->start($identity,$source,9)->code(),'completed exact-request replay is inspection only');
	check('conflict'===$svc->start(str_repeat('9',32),$source)->code(),'retired expected source cannot start new operation');
	sql($db,"UPDATE {$t->commerce_epochs()} SET retired_at=NULL WHERE id=1");check('inconsistent_state'===$svc->resume($identity)->code(),'completed row without retirement proof fails closed');
} finally {try{$db->close();}catch(Throwable $ignored){}maintenance_cleanup(new wpdb($prefix));}
echo "All Phase 4.3 durable lifecycle checks passed.\n";
