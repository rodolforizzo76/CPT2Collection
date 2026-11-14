<?php
/** Phase 4.7 positive historical deletion DATA, malformed/ambiguous proof and fresh retries. */
declare(strict_types=1);
require __DIR__.'/support/checkout-context.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,OrderDeletionRestController,CommerceContext,CommerceControlRepository,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,TransactionManager,UtcClock};
function is_user_logged_in():bool{return true;}
function current_user_can(string $cap):bool{return $GLOBALS['c47_admin']??false;}
if(!class_exists('WP_Error')){class WP_Error {public function __construct(...$args){}}}
class C47DeleteRequest extends WP_REST_Request {public function __construct(private string $id,private CommerceContext $context){}public function get_url_params():array{return ['order_id'=>$this->id];}
public function get_query_params():array{return [];}
public function get_body_params():array{return [];}
public function get_file_params():array{return [];}
public function is_json_content_type():bool{return true;}
public function get_header(string $key):string{return '';}
public function get_json_params():array{return ['confirmation'=>'DELETE ORDER C2C-'.$this->id,'expected_context'=>['installation_id'=>$this->context->installation_id(),'epoch_id'=>(string)$this->context->epoch_id(),'epoch_identity'=>$this->context->epoch_identity()]];}
public function get_body():string{return json_encode($this->get_json_params());}}
foreach(['valid','two_attempts','later_reset','foreign','ambiguous','bad_date','bad_epoch','bad_reset','residual','live_collision','unknown'] as $case){
 $db=new D4Db('c2c_47_delete_'.bin2hex(random_bytes(4)).'_');
 try{
  validation_schema($db);$f=validation_order($db);if($case==='two_attempts')d1_second_attempt($db,$f);
  check(c47_delete($db,$f['order'])->code()==='deleted','47 first genuine Phase 3 deletion');check(c47_delete($db,$f['order'])->code()==='already_deleted','47 original-epoch repeat retains prior contract');
  discovery_finish($db,validation_start($db));if($case==='later_reset')discovery_finish($db,validation_start($db));$t=new TableNames($db);
  if($case==='foreign')sql($db,'UPDATE '.$t->order_deletions()." SET installation_id=REPEAT('f',32)");
  if($case==='ambiguous'){$row=get_object_vars($db->get_row('SELECT * FROM '.$t->order_deletions()));unset($row['id']);$row['installation_id']=str_repeat('f',32);check($db->insert($t->order_deletions(),$row,array_fill(0,count($row),'%s'))!==false,'47 global duplicate deletion fixture');}
  if($case==='bad_date')sql($db,'UPDATE '.$t->order_deletions()." SET deleted_at='2099-01-01 00:00:00'");
  if($case==='bad_epoch')sql($db,'UPDATE '.$t->order_deletions()." SET epoch_identity=REPEAT('e',32)");
  if($case==='bad_reset')sql($db,'UPDATE '.$t->commerce_resets()." SET checkpoint='scan'");
  if($case==='residual')sql($db,'INSERT INTO '.$t->order_items()." (order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at,source_cart_item_id,source_cart_item_version) VALUES({$f['order']},77,'Residual',100,1,100,100,'EUR',UTC_TIMESTAMP(),1,1)");
  if($case==='live_collision'){$current=validation_order($db,1,false,false);sql($db,'UPDATE '.$t->orders().' SET id='.$f['order'].' WHERE id='.$current['order']);}
  $id=$case==='unknown'?999999:$f['order'];$before=baseline_snapshot($db);$db->capture=true;$db->captured=[];$out=c47_delete($db,$id);
  $expected=match($case){'valid','two_attempts','later_reset'=>'already_deleted','unknown'=>'absence_unprovable','residual'=>'inconsistent_state',default=>'evidence_conflict'};
  check($out->code()===$expected&&$out->is_success()===($expected==='already_deleted'),'exact historical disposition '.$case.': '.$out->code());c47_no_dml($db);check($before===baseline_snapshot($db),'historical request preserves all business/stock/evidence');
  $controller=new OrderDeletionRestController(fn($n,$expected)=>(new OrderDeletionService(new OrderDeletionRepository($db,$t),new OrderDeletionRegistry($db,$t),new TransactionManager($db,$t),new UtcClock()))->delete($n,$expected));$GLOBALS['c47_admin']=false;check($controller->authorize() instanceof WP_Error,'unauthorized REST caller rejected');$GLOBALS['c47_admin']=true;check($controller->authorize()===true,'administrator authorization remains required');$http=$controller->delete(new C47DeleteRequest((string)$id,(new CommerceControlRepository($db,$t))->read()));check($http->get_status()===($expected==='already_deleted'?200:409),'REST preserves positive deletion versus non-success lack/conflict of proof');
 }finally{d3_cleanup($db);}
}
foreach([false,true] as $physical)foreach([false,true] as $reset){
 $prefix='c2c_47_unknown_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);
 try{validation_schema($db);$f=validation_order($db,1,false,false);$db->fault='COMMIT';$db->after=$physical;$db->faulted=false;check(c47_delete($db,$f['order'])->code()==='transaction_outcome_unknown','47 deletion COMMIT loss remains unknown');$db->close();$db=new D4Db($prefix);if($reset)discovery_finish($db,validation_start($db));$out=c47_delete($db,$f['order']);$expected=$reset?($physical?'already_deleted':'absence_unprovable'):($physical?'already_deleted':'deleted');check($out->code()===$expected,'fresh independently admitted retry after physical deletion/reset: '.$expected);}finally{d3_cleanup($db);}
}
echo "47 historical deletion checks passed.\n";
foreach([false,true] as $physical){$prefix='c2c_47_history_end_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);try{
 validation_schema($db);$f=validation_order($db,1,false,false);check(c47_delete($db,$f['order'])->code()==='deleted','historical rollback-loss fixture genuine deletion');discovery_finish($db,validation_start($db));$before=baseline_snapshot($db);$db->bookkeeping_failure='ROLLBACK';$db->bookkeeping_after=$physical;$db->capture=true;$db->captured=[];$out=c47_delete($db,$f['order']);check($out->code()==='transaction_outcome_unknown'&&!$out->is_success()&&$db->closed&&$db->poison_sql===0,'unknown historical inspection end discards authority and cannot claim success');c47_no_dml($db);$db=new D4Db($prefix);check(baseline_snapshot($db)===$before&&c47_delete($db,$f['order'])->code()==='already_deleted','fresh historical retry reproves exact D without replay or evidence mutation');
}finally{d3_cleanup($db);}}
echo "47 read-only historical uncertainty checks passed.\n";
