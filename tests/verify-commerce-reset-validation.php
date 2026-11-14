<?php
/** A1 bounded semantic passes and durable corruption decisions on MariaDB. */
declare(strict_types=1);
require __DIR__.'/support/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetValidation,TransactionManager,TableNames,ProviderRegistry,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock,CartRepository};
foreach([1,2,100] as $size){
 $db=new wpdb('c2c_a1_core_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
 try{
  validation_schema($db);$f=validation_order($db,3);validation_order($db,1,false,false);$event=validation_receipt($db,$f,'evt_before_association');
  $tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'native nullable identity transaction');$registry=new ProviderRegistry($db,$t);check($registry->bind('stripe_checkout','session','cs_nullable',$f['claim'],gmdate('Y-m-d H:i:s'))==='bound'&&$tx->commit(),'normal native identity permits null environment');
  $record=validation_start($db);$before=validation_preserved($db);$svc=validation_service($db);
  check($svc->validate($record,0)->code()==='invalid_request'&&$svc->validate($record,1001)->code()==='invalid_request','invalid batch sizes do not mutate');
  check($svc->advance($record,'retiring_claims')->code()==='invalid_request'&&$svc->checkpoint($record,'v_complete',0)->code()==='invalid_request','generic metadata cannot bypass A1');
  $first=$svc->validate($record,$size)->operation();check($first->checkpoint()==='v_carts'&&$first->cursor()===0&&$first->validation_generation()===0&&$first->revision()===$record->revision()+1,'first batch captures generation and starts first stage');
  check($svc->validate($record,$size)->code()==='stale','old exact snapshot cannot overwrite capture');
  check($svc->checkpoint($first,'manual',0)->code()==='invalid_request','captured validation progress is validator-only');
  $seen=[];$record=$first;
  while($record->phase()==='validating'&&$record->status()==='running'){$cp=$record->checkpoint();if(in_array(substr($cp,2),CommerceResetValidation::STAGES,true)&&($seen[count($seen)-1]??null)!==$cp)$seen[]=$cp;$previous=$record;$result=$svc->validate($record,$size);check(in_array($result->code(),['progressed','blocked'],true),'batch has owned durable outcome');$record=$result->operation();check($record->revision()===$previous->revision()+1&&$record->validation_generation()===0,'each batch consumes exactly one revision');}
  check($record->phase()==='retiring_claims'&&$record->status()==='running'&&$record->checkpoint()===null&&$record->cursor()===0&&$record->validation_generation()===$record->evidence_generation(),'generation-fresh final transition without retirement');
  check($seen===array_map(fn($s)=>'v_'.$s,CommerceResetValidation::STAGES),'canonical semantic stages are deterministic at size '.$size);
  check($before===validation_preserved($db),'all operational/provider/deletion/epoch/control/stock/product rows preserved');
  check($svc->validate($record,$size)->code()==='invalid_request','A1 does not operate in B');
 }finally{baseline_cleanup($db);}
}
// Modern permanent deletion, late receipt, supported quarantine and retired-modern evidence.
$db=new wpdb('c2c_a1_deleted_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);$old=validation_order($db,1);$tx=new TransactionManager($db,$t);$delete=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),$tx,new UtcClock());check($delete->delete($old['order'])->code()==='deleted','actual old modern permanent deletion');baseline_epoch_switch($db);
 $f=validation_order($db,2);check($delete->delete($f['order'])->code()==='deleted','actual source permanent deletion');validation_receipt($db,$f,'evt_late_deleted');
 check($tx->begin_commerce_shared(),'late retired claim evidence admitted');$registry=new ProviderRegistry($db,$t);check($registry->bind('stripe_checkout','session','cs_retired_observed',$old['claim'],gmdate('Y-m-d H:i:s'))==='bound','retired modern immutable owner can be observed in current epoch');check($registry->bind('stripe_checkout','session','cs_retired_observed',$f['claim'],gmdate('Y-m-d H:i:s'),null,null,'test')==='conflict'&&$tx->commit(),'normal conflict preserves original retired owner in quarantine');
 $record=validation_start($db);$before=validation_preserved($db);$record=validation_pass($db,$record);check($record->phase()==='retiring_claims','deleted source, late unresolved receipt, retired owner and quarantine validate');check($before===validation_preserved($db),'deleted/late/quarantine validation preserves all evidence and stock');
}finally{baseline_cleanup($db);}
$cases=[
 'cart_token'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->carts()} SET token_hash='bad' WHERE id={$f['cart']}"),
 'cart_item_shape'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->cart_items()} SET quantity=0 WHERE cart_id={$f['cart']}"),
 'order_snapshot'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->orders()} SET checkout_attempt_hash=NULL WHERE id={$f['order']}"),
 'item_source_identity'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->order_items()} SET source_cart_item_id=NULL,source_cart_item_version=NULL WHERE id={$f['items'][0]}"),
 'duplicate_source_cart_item_id'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->order_items()} SET source_cart_item_id=(SELECT x FROM (SELECT source_cart_item_id x FROM {$t->order_items()} WHERE id={$f['items'][0]}) q) WHERE id={$f['items'][1]}"),
 'parent_missing'=>fn($db,$t,$f)=>sql($db,"DELETE FROM {$t->carts()} WHERE id={$f['cart']}"),
 'item_source'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->order_items()} SET source_cart_item_version=1 WHERE id={$f['items'][0]}"),
 'integer'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->order_items()} SET unit_price_minor=18446744073709551615 WHERE id={$f['items'][0]}"),
 'total_overflow'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->order_items()} SET unit_price_minor=".PHP_INT_MAX.",line_subtotal_minor=".PHP_INT_MAX.",line_total_minor=".PHP_INT_MAX." WHERE id={$f['items'][0]}"),
 'line_amount'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->order_items()} SET line_total_minor=99 WHERE id={$f['items'][0]}"),
 'order_total'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->orders()} SET total_minor=201 WHERE id={$f['order']}"),
 'reservation_owner'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->reservations()} SET post_id=999 WHERE order_id={$f['order']}"),
 'session_order'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->payment_sessions()} SET amount_minor=201 WHERE id={$f['session']}"),
 'claim_shape'=>fn($db,$t,$f)=>sql($db,"UPDATE {$t->provider_claims()} SET request_version=1 WHERE id={$f['claim']}"),
 'request_item'=>function($db,$t,$f){$row=$db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id={$f['claim']}");$p=json_decode($row->request_snapshot,true);$p['items'][0]['name']='Contradiction';sql($db,$db->prepare("UPDATE {$t->provider_claims()} SET request_snapshot=%s WHERE id=%d",json_encode($p),$f['claim']));},
 'event_payload'=>function($db,$t,$f){validation_receipt($db,$f,'evt_bad_payload');sql($db,"UPDATE {$t->payment_events()} SET normalized_payload=NULL");},
 'identity_owner'=>function($db,$t,$f){$tx=new TransactionManager($db,$t);check($tx->begin_commerce_shared(),'identity fixture admitted');check((new ProviderRegistry($db,$t))->bind('stripe_checkout','payment','pi_corrupted',$f['claim'],gmdate('Y-m-d H:i:s'))==='bound'&&$tx->commit(),'native binding fixture');sql($db,"UPDATE {$t->provider_identities()} SET claim_id=999999");},
 'deletion_shape'=>function($db,$t,$f){$d=new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock());check($d->delete($f['order'])->code()==='deleted','actual deletion contradiction fixture');sql($db,"UPDATE {$t->order_deletions()} SET cart_revision=NULL");},
];
foreach($cases as $reason=>$mutate){$db=new wpdb('c2c_a1_bad_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);$f=validation_order($db);$mutate($db,$t,$f);$r=validation_start($db);$before=validation_preserved($db);$r=validation_pass($db,$r);check($r->status()==='blocked'&&$r->error_code()==='validation_'.$reason,$reason.': specific durable integrity block');check($r->phase()==='validating'&&$r->checkpoint()!==null&&$r->validation_generation()===0,$reason.': preserves semantic position and generation');check($before===validation_preserved($db),$reason.': no repair, provider mutation or stock mutation');check(validation_service($db)->resume($r->operation_identity())->operation()->equals($r),$reason.': locked recovery observes exact block');
 }finally{baseline_cleanup($db);}}
echo "All A1 core and modern-domain validation checks passed.\n";
