<?php
/** Production B at size one, restart, generation invalidation and retained evidence. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRetirement,TableNames,ProviderRegistry,TransactionManager,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock,CommerceResetRepository};
foreach([0,1,35,36,100,PHP_INT_MAX] as $n){$encoded=CommerceResetRetirement::encode_cursor($n);check(CommerceResetRetirement::decode_cursor($encoded)===$n,'lossless compact operational cursor '.$n);}
$max='v_sum_'.PHP_INT_MAX.'_'.PHP_INT_MAX;$token=CommerceResetRetirement::revalidation('c',PHP_INT_MAX,$max);check(strlen($token)<=64,'full-range nested A1 sum and independent operational cursor fit durable token');
foreach([1,3,100] as $size){$db=new wpdb('c2c_b_core_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db,2);validation_order($db,1);$event=validation_receipt($db,$f,'evt_gap');
 $r=validation_pass($db,validation_start($db),$size);$before=retirement_preserved($db);$claims=$db->get_results('SELECT * FROM '.$t->provider_claims().' ORDER BY id');$svc=validation_service($db);
 check($svc->advance($r,'terminalizing_events')->code()==='invalid_request'&&$svc->checkpoint($r,'b_c',999)->code()==='invalid_request','metadata cannot bypass B');
 $tx=new TransactionManager($db,$t);check($tx->begin_reset_maintenance($r),'owned B unit before commit');$tentative=(new CommerceResetRepository($db,$t))->retire($r,1,gmdate('Y-m-d H:i:s'));check($tentative!==false,'unit writes retirement plus progress');$denied=false;try{(new CommerceResetRetirement($db,$t,$r))->batch('c',0,1,gmdate('Y-m-d H:i:s'));}catch(RuntimeException $error){$denied=true;}check($denied&&$tx->rollback(),'progress consumes its old live B snapshot before commit');
 $old=$r;$r=$svc->retire($r,1)->operation();check($r->checkpoint()==='b_c'&&$r->cursor()===$f['claim'],'first bounded claim retired atomically with cursor');check($svc->retire($old,1)->code()==='stale','stale retirement worker rejected');
 $r=validation_bind($db,$r,$f['claim'],'event','evt_late',hash('sha256','late'),'checkout.session.completed');$phase=$r->phase();$cursor=$r->cursor();$r=$svc->retire($r,1)->operation();[$stage,$saved,$v]=CommerceResetRetirement::position($r);check($r->phase()===$phase&&$saved===$cursor&&$v==='v_carts','later-phase revalidation preserves operational cursor and phase');
 $r=retirement_pass($db,$r,$size);check($r->status()==='running'&&$r->phase()==='deleting'&&$r->validation_generation()===$r->evidence_generation(),'fresh B reaches deleting at size '.$size);
 foreach($claims as $claim){$new=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$claim->id);check($new->disposition==='retired'&&$new->tombstoned_at!==null,'source native claim durably retired');unset($new->disposition,$new->tombstoned_at,$claim->disposition,$claim->tombstoned_at);check(get_object_vars($new)===get_object_vars($claim),'immutable native claim evidence preserved');}
 check((int)$db->get_var("SELECT COUNT(*) FROM {$t->provider_identities()} WHERE identity_kind='event' AND disposition='neutralized_retired'")===2,'receipt gap and historical-only Event identities terminalized');
 check($before===retirement_preserved($db),'all operational rows, stock, catalog, baseline, deletion and epochs unchanged');
 // An old live context is consumed even before its owner's transaction commits.
 $tx=new TransactionManager($db,$t);check(!$tx->begin_reset_maintenance($old),'independent old snapshot cannot regain maintenance after completion of B');
}finally{baseline_cleanup($db);}}
// B refuses nontransactional durable evidence before acquiring any mutation authority.
$db=new wpdb('c2c_b_engine_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);validation_order($db);$r=validation_pass($db,validation_start($db));sql($db,'ALTER TABLE '.$t->provider_claims().' ENGINE=MyISAM');$before=baseline_snapshot($db);check(validation_service($db)->retire($r)->code()==='database_error','non-InnoDB claim storage cannot authorize B');check($before===baseline_snapshot($db),'storage rejection preserves claim and reset together');
}finally{baseline_cleanup($db);}
echo "All B retirement core checks passed.\n";
