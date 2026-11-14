<?php
/** Real A0 binds invalidate behind-cursor and near-final passes; blocking is durable. */
declare(strict_types=1);
require __DIR__.'/support/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,ProviderRegistry};
foreach(['behind','near_final','contradiction'] as $mode){
 $db=new wpdb('c2c_a1_gen_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db);$g=validation_order($db,1);$event=validation_receipt($db,$f,'evt_gen');$at=gmdate('Y-m-d H:i:s');$tx=new TransactionManager($db,$t);
  if($mode==='contradiction'){
   // Explicit modern unresolved observation shape: historical admission can bind it.
   // This lower-level seed is not claimed to be produced by the normal bind API.
   $data=['provider'=>'stripe_checkout','identity_kind'=>'event','identity_value'=>'evt_gen','claim_id'=>null,'binding_state'=>'unresolved','evidence_state'=>'native','observed_epoch_id'=>1,'first_seen_at'=>$at,'payload_sha256'=>null,'event_type'=>null,'environment'=>null,'disposition'=>'pending','conflict_code'=>null];check(false!==$db->insert($t->provider_identities(),$data,array_fill(0,count($data),'%s')),'explicit unresolved modern observation fixture');
  }else{check($tx->begin_commerce_shared(),'normal nullable observation admitted');check((new ProviderRegistry($db,$t))->bind('stripe_checkout','session','cs_behind',$f['claim'],$at)==='bound'&&$tx->commit(),'native nullable observation created by normal bind');}
  $r=validation_start($db);$r=validation_pass($db,$r,1,$mode==='near_final'?'v_complete':'v_provider_identities');
  if($mode!=='near_final'){$r=validation_service($db)->validate($r,1)->operation();check($r->checkpoint()==='v_provider_identities'&&$r->cursor()>0,'validator passed the identity before evidence changes');}
  $stale=$r;$before=validation_preserved($db);
  $r=$mode==='contradiction'?validation_bind($db,$r,$g['claim'],'event','evt_gen',$event->payload_sha256(),$event->event_type()):validation_bind($db,$r,$f['claim'],'session','cs_behind');
  check($r->evidence_generation()===$stale->evidence_generation()+1&&$r->revision()===$stale->revision()&&$r->checkpoint()===$stale->checkpoint()&&$r->cursor()===$stale->cursor()&&$r->validation_generation()===$stale->validation_generation(),'A0 bind changes generation without resetting revision/position');
  check(validation_service($db)->validate($stale,1)->code()==='stale','stale caller cannot continue or certify an invalidated pass');
  $restarted=validation_service($db)->validate($r,1)->operation();check($restarted->checkpoint()==='v_carts'&&$restarted->cursor()===0&&$restarted->revision()===$r->revision()+1&&$restarted->validation_generation()===$r->evidence_generation()&&$restarted->status()==='running','whole-pass restart is exact, durable, first stage and not a block');
  if($mode==='near_final'){
   $old=$restarted;$again=validation_bind($db,$restarted,$f['claim'],'payment','pi_repeat_restart');check(validation_service($db)->validate($old,1)->code()==='stale','a second legitimate mutation invalidates the restarted snapshot');$restarted=validation_service($db)->validate($again,1)->operation();check($restarted->checkpoint()==='v_carts'&&$restarted->cursor()===0&&$restarted->validation_generation()===2&&$restarted->status()==='running','repeated evidence mutation durably restarts again without blocking');
  }
  $evidence=validation_preserved($db);$final=validation_pass($db,$restarted,1);check($evidence===validation_preserved($db),'restart and renewed pass preserve all evidence/operational/stock rows');
  if($mode==='contradiction'){
   check($final->status()==='blocked'&&$final->error_code()==='validation_event_claim'&&$final->phase()==='validating','behind-cursor late binding contradiction is detected by the fresh whole pass');
   $blocked=$final;$changed=validation_bind($db,$blocked,$f['claim'],'payment','pi_after_block');check($changed->status()==='blocked'&&$changed->error_code()===$blocked->error_code()&&$changed->revision()===$blocked->revision()&&$changed->validation_generation()===$blocked->validation_generation()&&$changed->checkpoint()===$blocked->checkpoint()&&$changed->cursor()===$blocked->cursor()&&$changed->evidence_generation()===$blocked->evidence_generation()+1,'blocked historical evidence survives without automatic unblock or progress');check(validation_service($db)->validate($changed,1)->code()==='conflict','blocked validator cannot restart itself');
  }else{check($final->phase()==='retiring_claims'&&$final->validation_generation()===$final->evidence_generation(),'stable renewed whole pass eventually certifies the live generation');}
 }finally{baseline_cleanup($db);}
}
echo "All A1 evidence generation race checks passed.\n";
