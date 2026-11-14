<?php
/** Canonical terminal contract regression; epoch switch here is explicitly fixture-only. */
declare(strict_types=1);
require_once __DIR__.'/support/reset-deletion.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetDeletion,CommerceResetRecord,TableNames,TransactionManager};

$db=new DeletionDb('c2c_final_prereq_'.bin2hex(random_bytes(5)).'_');
$t=new TableNames($db);
try {
 [$r]=deletion_setup($db,0);
 $r=deletion_pass($db,$r,100);
 check(CommerceResetDeletion::certified_empty($r)&&$r->checkpoint()==='z1_n_0_done'&&$r->cursor()===0,'production pipeline reaches exact fresh final entry');
 check($r->status()==='running'&&$r->phase()==='deleting'&&$r->active_slot()===1&&$r->target_epoch_id()===null&&$r->completed_at()===null,'entry remains active and unallocated');
 check(validation_service($db)->resume($r->operation_identity())->code()==='running','fresh pre-final certificate is running, never completed');
 $at=$r->updated_at();
 $before=deletion_kept($db);
 // Fixture-only coherent FUTURE switch, following the existing lifecycle test.
 // This is not a production finalizer or proof of atomic finalization.
 $tx=new TransactionManager($db,$t);
 check($tx->begin_reset_control(),'test-only representation fixture owns exclusive foundation fence');
 sql($db,$db->prepare('INSERT INTO '.$t->commerce_epochs().' (id,identity,created_at,retired_at) VALUES(%d,%s,%s,NULL)',2,$r->target_epoch_identity(),$at));
 sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET retired_at=%s WHERE id=%d',$at,$r->source_epoch_id()));
 sql($db,$db->prepare("UPDATE ".$t->commerce_control()." SET current_epoch_id=2,state='ready',updated_at=%s WHERE id=1",$at));
 sql($db,$db->prepare("UPDATE ".$t->commerce_resets()." SET status='completed',phase='completed',target_epoch_id=2,active_slot=NULL,completed_at=%s,updated_at=%s,revision=revision+1 WHERE id=%d",$at,$at,$r->id()));
 check($tx->commit(),'fixture terminal epoch/control/timestamps are coherent');
 $canonical=get_object_vars($db->get_row('SELECT * FROM '.$t->commerce_resets().' WHERE id='.$r->id()));
 $out=validation_service($db)->resume($r->operation_identity(),$r->source_epoch_identity());
 check($out->code()==='completed'&&$out->operation()->checkpoint()==='z1_n_0_done'&&$out->operation()->cursor()===0,'canonical completed certificate recognized');
 foreach([
  ['checkpoint'=>null],['checkpoint'=>'scan','cursor'=>41],['checkpoint'=>'z1_e_0_c','cursor'=>17],
  ['cursor'=>1],['validation_generation'=>null],['validation_generation'=>$r->evidence_generation()+1],
  ['target_epoch_id'=>null],['completed_at'=>null],['active_slot'=>1],['phase'=>'finalizing'],
  ['status'=>'running'],['target_epoch_identity'=>'invalid'],['target_epoch_identity'=>$r->source_epoch_identity()],
  ['source_epoch_identity'=>str_repeat('f',32)],['installation_id'=>str_repeat('e',32)],['requested_by'=>0],['target_epoch_id'=>999],['target_epoch_identity'=>str_repeat('a',32)],['error_code'=>'bad_terminal'],['updated_at'=>'2000-01-01 00:00:00']
 ] as $bad) {
  check($db->update($t->commerce_resets(),$bad,['id'=>$r->id()],array_fill(0,count($bad),'%s'),['%d'])!==false,'malformed terminal fixture installed');
  $snapshot=baseline_snapshot($db);
  $out=validation_service($db)->resume($r->operation_identity(),$r->source_epoch_identity());
  check(!$out->is_success()&&$out->operation()===null,'malformed terminal '.json_encode($bad).' rejected');
  check(baseline_snapshot($db)===$snapshot,'malformed inspection performs no repair or lifecycle mutation');
  sql($db,'DELETE FROM '.$t->commerce_resets().' WHERE id='.$r->id());
  check($db->insert($t->commerce_resets(),$canonical,array_fill(0,count($canonical),'%s'))===1,'canonical fixture restored');
 }
 foreach([
  ['commerce_epochs',"retired_at=NULL WHERE id=1"],
  ['commerce_epochs',"retired_at='2000-01-01 00:00:00' WHERE id=2"],
  ['commerce_epochs',"identity=REPEAT('f',32) WHERE id=2"],
  ['commerce_control',"current_epoch_id=1 WHERE id=1"],
  ['commerce_control',"state='resetting' WHERE id=1"]
 ] as [$method,$update]) {
  sql($db,'START TRANSACTION');sql($db,'UPDATE '.$t->$method().' SET '.$update);sql($db,'COMMIT');
  $snapshot=baseline_snapshot($db);check(!validation_service($db)->resume($r->operation_identity())->is_success(),'partial terminal epoch/control truth rejected');
  check(baseline_snapshot($db)===$snapshot,'partial completion inspection cannot repair or reopen');
  sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET retired_at=%s WHERE id=1',$at));
  sql($db,$db->prepare('UPDATE '.$t->commerce_epochs().' SET retired_at=NULL,identity=%s WHERE id=2',$r->target_epoch_identity()));
  sql($db,"UPDATE ".$t->commerce_control()." SET current_epoch_id=2,state='ready' WHERE id=1");
 }
 check($before!==deletion_kept($db),'test-only lifecycle fixture changed foundation state');
 check($db->query('UPDATE '.$t->commerce_epochs().' SET retired_at=NULL WHERE id='.$r->source_epoch_id())===1,'test-only retirement contradiction installed');
 check(validation_service($db)->resume($r->operation_identity())->code()==='inconsistent_state','reader still rejects missing source retirement proof');
} finally { baseline_cleanup($db); }
echo "Canonical completed reset contract checks passed.\n";
