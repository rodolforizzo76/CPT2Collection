<?php
/** Historical architecture counterexample retained, now checked against the production seal.
 * Operational absence is test-only; no production deletion service or completion.
 */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceResetRepository,CommerceEvidenceContext,ProviderClaimRecord,ProviderRegistry,CommerceResetValidation};
class SealModelLossDb extends wpdb {
 public string $fault='';
 public function query(string $q):int|false {
  if($q==='COMMIT'&&$this->fault==='before'){$this->last_error='model lost before seal commit';return false;}
  if($q==='COMMIT'&&$this->fault==='after'){parent::query($q);$this->last_error='model lost seal acknowledgment';return false;}
  return parent::query($q);
 }
}
function seal_model_bind(wpdb $db,object $r,int $claim,string $kind,string $value,?string $hash=null,?string $type=null):array {
 $t=new TableNames($db);$c=ProviderClaimRecord::from_row($db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id=$claim"));
 $e=new CommerceEvidenceContext($r,$c);$tx=new TransactionManager($db,$t);
 check($tx->begin_historical_evidence($e),'historical admission uses real exact seal snapshot');
 $code=(new ProviderRegistry($db,$t))->bind_historical($e,'stripe_checkout',$kind,$value,gmdate('Y-m-d H:i:s'),'test',$hash,$type);
 check(in_array($code,['bound','conflict'],true)&&$tx->commit(),'real post-seal identity result committed');
 return [validation_service($db)->resume($r->operation_identity())->operation(),$code];
}
function seal_model_delete(wpdb $db,object $r,int $stages):object {
 $t=new TableNames($db);
 foreach(array_slice(['payment_events','payment_sessions','reservations','order_items','orders','cart_items','carts'],0,$stages) as $table){$last=0;
  do{$tx=new TransactionManager($db,$t);if(!$tx->begin_reset_maintenance($r))throw new RuntimeException('model deletion admission');
   $row=$db->get_row($db->prepare('SELECT id FROM '.$t->$table().' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT 1 FOR UPDATE',$last));
   if($row){$last=(int)$row->id;if($db->query('DELETE FROM '.$t->$table().' WHERE id='.$last)!==1)throw new RuntimeException('model delete');}
   if(!$tx->commit())throw new RuntimeException('model absence commit');
  }while($row);
 }
 return $r;
}
foreach(['before','after','duplicate','enrichment','conflict','partial','empty','stale','older_receipt_gap','older_materialized'] as $case){
 $db=new wpdb('c2c_seal_model_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{validation_schema($db);
  $older=null;
  if(in_array($case,['older_receipt_gap','older_materialized'],true)){ $old=validation_order($db);$older=validation_receipt($db,$old,'evt_model_older');baseline_epoch_switch($db); } // Existing retired-modern test convention: future epoch switch, not reset implementation.
  $f=validation_order($db);$other=validation_order($db);validation_receipt($db,$f,'evt_model_original');
  $r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'b_i');
  while($r->cursor()<(int)$db->get_var('SELECT COALESCE(MAX(id),0) FROM '.$t->provider_identities()))$r=validation_service($db)->retire($r,1)->operation();
  if($case==='older_receipt_gap')check($db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_model_older'")===null,'historical source-only B counterexample remains reproducible before coverage');
  $r=retirement_pass($db,$r,1,'s1_done');
  $pre=$r;
  if($case==='before'){
   [$r]=seal_model_bind($db,$r,$f['claim'],'event','evt_model_before',hash('sha256','before'),'checkout.session.completed');
   check(validation_service($db)->retire($pre,1)->code()==='stale','evidence before seal defeats old exact worker');
   $next=validation_service($db)->retire($r,1)->operation();check($next->checkpoint()==='s1_s','pre-seal change requires complete A1 restart');$r=$next;
  }
  $r=retirement_pass($db,$r,1);$sealed=$r;
  check($r->phase()==='deleting'&&$r->validation_generation()===$r->evidence_generation(),'actual fresh B boundary is model seal');
  check($db->get_var('SELECT retired_at FROM '.$t->commerce_epochs().' WHERE id='.$r->source_epoch_id())===null,'seal does not prematurely retire source epoch');
  check(! (new TransactionManager($db,$t))->begin_commerce_shared(),'source normal authority closed');
  $before=retirement_preserved($db);$claims=serialize($db->get_results('SELECT * FROM '.$t->provider_claims().' ORDER BY id'));
  if($case==='partial'||$case==='empty')$r=seal_model_delete($db,$r,$case==='partial'?3:7);
  $operational=retirement_preserved($db);
  [$r]=seal_model_bind($db,$r,$f['claim'],'event','evt_model_late',hash('sha256','late'),'checkout.session.completed');
  check($r->evidence_generation()===$sealed->evidence_generation()+1&&$r->validation_generation()===$sealed->validation_generation(),'post-seal evidence stales evidence certification, not immutable source certificate');
  if($case==='duplicate'){[$next]=seal_model_bind($db,$r,$f['claim'],'event','evt_model_late',hash('sha256','late'),'checkout.session.completed');check($next->equals($r),'duplicate is durable no-op without generation increment');$r=$next;}
  if($case==='enrichment'){
   [$r]=seal_model_bind($db,$r,$f['claim'],'session','cs_model_new');
   check($db->get_var("SELECT claim_id FROM {$t->provider_identities()} WHERE identity_value='cs_model_new'")==$f['claim'],'new Session identity needs no operational parent');
  }
  if($case==='conflict'){
   [$r,$code]=seal_model_bind($db,$r,$other['claim'],'event','evt_model_late',hash('sha256','different'),'checkout.session.completed');
   $i=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_model_late'");
   check($code==='conflict'&&$i->binding_state==='quarantined'&&(int)$i->claim_id===$f['claim']&&$i->payload_sha256===hash('sha256','late'),'conflict preserved independently of operational rows');
  }
  check($operational===retirement_preserved($db)&&$claims===serialize($db->get_results('SELECT * FROM '.$t->provider_claims().' ORDER BY id')),'every historical write preserves operational history/stock and claim snapshots');
  check(!(new TransactionManager($db,$t))->begin_reset_maintenance($sealed),'post-seal generation change rejects stale maintenance snapshot');
  if($case==='empty'){
   $prefix=$db->prefix;$db->close();$db=new wpdb($prefix);$t=new TableNames($db);$r=validation_service($db)->resume($r->operation_identity())->operation();
   $reader=new CommerceResetValidation($db,$t,$r);(new ReflectionProperty(CommerceResetValidation::class,'first_epoch'))->setValue($reader,1);
   // Actual durable identity predicate is independent of parents when receipts absent.
   $last=0;do{$rows=$db->get_results("SELECT * FROM {$t->provider_identities()} WHERE id>$last ORDER BY id LIMIT 1");foreach($rows as $i){(new ReflectionMethod(CommerceResetValidation::class,'provider_identities'))->invoke($reader,$i);$last=(int)$i->id;}}while($rows);
   check(true,'fresh retained identity predicates validate all-empty model without five relational witnesses');
   foreach(['payment_events','payment_sessions','reservations','order_items','orders','cart_items','carts'] as $table)check($db->get_var('SELECT id FROM '.$t->$table().' ORDER BY id LIMIT 1')===null,'model empty '.$table);
  }
  if($case==='older_receipt_gap'){
   $i=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_model_older'");check($i!==null&&(int)$i->claim_id===$old['claim'],'production coverage closes historical older receipt gap');
   [$r,$code]=seal_model_bind($db,$r,$f['claim'],'event','evt_model_older',$older->payload_sha256(),'checkout.session.completed');check($code==='conflict','late source observation preserves older immutable owner');
  }
  if($case==='older_materialized'){
   $r=seal_model_delete($db,$r,7);
   [$r,$code]=seal_model_bind($db,$r,$f['claim'],'event','evt_model_older',$older->payload_sha256(),'checkout.session.completed');
   $i=$db->get_row("SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_model_older'");
   check($code==='conflict'&&$i->binding_state==='quarantined'&&(int)$i->claim_id===$old['claim'],'pre-seal older barrier detects late contradiction after all operational rows disappear');
  }
  check($r->target_epoch_id()===null&&$r->status()==='running','model never allocates/completes/reopens');
 }finally{baseline_cleanup($db);}
}
foreach(['before','after'] as $fault){$prefix='c2c_seal_loss_'.bin2hex(random_bytes(5)).'_';$db=new SealModelLossDb($prefix);$t=new TableNames($db);
 try{validation_schema($db);validation_order($db);$r=retirement_pass($db,validation_pass($db,validation_start($db)),1,'s1_done');
  $db->fault=$fault;$out=validation_service($db)->retire($r,1);check($out->code()==='transaction_outcome_unknown'&&!$db->transaction_connection_usable(),'lost seal commit exposes no guessed certificate');
  $db->close();$db=new wpdb($prefix);$fresh=validation_service($db)->resume($r->operation_identity())->operation();
  check($fault==='before'?$fresh->equals($r):$fresh->phase()==='deleting'&&$fresh->revision()===$r->revision()+1,'fresh seal recovery sees old B or committed deleting, never half-seal');
 }finally{try{$db->close();}catch(Throwable $e){}baseline_cleanup(new wpdb($prefix));}
}
echo "Sealed-source historical counterexample and production seal checks passed.\n";
