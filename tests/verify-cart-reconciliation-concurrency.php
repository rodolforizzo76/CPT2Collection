<?php
/** D4 deterministic independent-process K12 -> K11 gaps and actual cart/control waits. */
declare(strict_types=1);
require __DIR__.'/support/checkout-renewal-edge.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
class D4DeliveryDb extends D4Db {
 public bool $sale_wrote=false;public bool $financial_read=false;public bool $probe_ended=false;public $gap=null;
 public function get_results(string $q):?array {
  if(TransactionManager::admitted_on($this)&&str_contains($q,'FROM '.$this->prefix.'c2c_orders FORCE INDEX(PRIMARY)'))$this->financial_read=true;
  return parent::get_results($q);
 }
 public function query(string $q):int|false {
  if($this->gap!==null&&$this->probe_ended&&str_starts_with($q,'START TRANSACTION WITH CONSISTENT SNAPSHOT')){
   check(!TransactionManager::admitted_on($this),'K12 authority released before K11 classification begins');$hook=$this->gap;$this->gap=null;$hook();
  }
  $probe=(($q==='ROLLBACK'&&$this->financial_read)||($q==='COMMIT'&&$this->sale_wrote))&&TransactionManager::admitted_on($this);
  if(str_starts_with($q,'UPDATE ')&&str_contains($q,$this->prefix.'c2c_orders')&&str_contains($q,"`status`='paid'"))$this->sale_wrote=true;
  $r=parent::query($q);if($probe&&$r!==false)$this->probe_ended=true;return $r;
 }
}
function d4_wait($socket,wpdb $observer,string $table):void {
 $id=(int)trim(fgets($socket));$deadline=microtime(true)+10;
 do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE '%FROM $table %'")>0;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
 $r=[$socket];$w=[];$e=[];check($waiting&&stream_select($r,$w,$e,0,200000)===0,'independent actor waits >=200ms at actual '.$table);
}
function d4_join(int $pid,$socket):string {$out=trim(fgets($socket));pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent D4 actor exits cleanly: '.$out);fclose($socket);return $out;}
function d4_payload(array $f,$op):array {$p=webhook_payload($f,'evt_d3');$p['created']=strtotime($op->receipt->provider_created_at().' UTC');check(hash('sha256',json_encode($p))===$op->receipt->payload_sha256(),'full delivery retains exact original authenticated bytes');return $p;}
$count=0;
foreach(['processed','new_sale'] as $delivery)foreach(['reset','delete','supersede','stored_supersession'] as $mode){
 $prefix='c2c_d4_gap_'.bin2hex(random_bytes(4)).'_';$db=new D4DeliveryDb($prefix);$pid=null;
 try{
  [$f,$e,$op]=$delivery==='processed'?d4_fixture($db):d3_fixture($db);$payload=d4_payload($f,$op);$stock=d3_stock($db);$cart=d4_cart($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D4Db($prefix);try{
   if($mode==='reset'){validation_start($child);$code='started';}
   elseif($mode==='delete'){d1_delete($child,$f);$code='deleted';}
   elseif($mode==='stored_supersession'){$tx=new TransactionManager($child,new TableNames($child));check($tx->begin_commerce_shared(),'inconsistent-state test owns current fence');check((new \RodolfoRizzo\Cpt2Collection\OrderRepository($child,new TableNames($child)))->find_for_update($f['order'])!==false,'stored-state test locks original Order');d4_later($child,$f);check($tx->commit(),'preexisting inconsistent later attempt commits for refusal test');$code='stored';}
   else {$calls=0;$provider=new class($calls) implements \RodolfoRizzo\Cpt2Collection\PaymentProviderInterface {public function __construct(private int &$calls){}public function name():string{return 'stripe_checkout';}public function create_session(\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest $r):\RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult{++$this->calls;throw new RuntimeException('provider must not be called');}};[$service]=payment_service($child,$provider);$r=$service->create($f['order'],bin2hex(random_bytes(16)));check(!$r->is_success()&&$calls===0,'ordinary paid Order rejects replacement before provider HTTP');$code='refused';}
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $x){fwrite($pair[1],'FAIL:'.$x->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D4DeliveryDb($prefix);
  $gap_cart=null;$db->gap=function()use($pair,&$pid,$db,&$gap_cart){$gap_cart=d4_cart($db);fwrite($pair[0],"go\n");fflush($pair[0]);$code=d4_join($pid,$pair[0]);$pid=null;check(in_array($code,['started','deleted','refused','stored'],true),'independent transition commits after K12 rollback and before K11 admission');};
  $connections=[];$db->capture=true;$db->captured=[];$http=webhook_deliver(webhook_controller($db,$connections),$payload);
  check($db->probe_ended&&$db->gap===null,'actual signed full-delivery path crosses deterministic K12-to-K11 barrier');
  $t=new TableNames($db);$cart_writes=array_filter($db->captured,fn($q)=>preg_match('/^(?:UPDATE|DELETE|INSERT) /',$q)&&(preg_match('/'.preg_quote($db->prefix,'/').'c2c_(?:cart_items|carts)/',$q)||str_contains($q,'SET cart_reconciliation_status =')));
  check($mode==='supersede'?$http->get_status()===200&&count($cart_writes)===4:count($cart_writes)===0,'only still-current unsuperseded original operation may reconcile after K12');check(d3_stock($db)===($delivery==='new_sale'?array_map(static fn($n)=>$n-1,$stock):$stock),'financial-to-K11 gap never repeats financial conversion');
  if($mode==='reset')check(d4_cart($db)===$gap_cart,'reset fence in gap leaves entire cart and marker untouched');
  ++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}d3_cleanup($db);}
}
foreach(['reset','delete','duplicate','cart_edit','renewal'] as $mode)foreach(['reconciliation','other'] as $first){
 $prefix='c2c_d4_race_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);$pid=null;
 try{
  [$f,$e,$op]=d4_fixture($db);$stock=d3_stock($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D4Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   if($first==='other'||$mode==='duplicate')$code=d4_service($child)->reconcile($f['order'],$op)->code();
   elseif($mode==='reset'){$code=validation_service($child)->start(bin2hex(random_bytes(16)),$f['epoch'])->code();}
   elseif($mode==='delete'){d1_delete($child,$f);$code='deleted';}
   elseif($mode==='renewal'){$code=d4_renew($child,$f)->get_code();}
   else {$code=d4_edit($child,$f,4,false)?'edited':'edit_refused';}
   fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $x){fwrite($pair[1],'FAIL:'.$x->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D4Db($prefix);$observer=new D4Db($prefix);$t=new TableNames($db);
  $db->at_end=function()use($pair,$observer,$t,$mode){fwrite($pair[0],"go\n");fflush($pair[0]);d4_wait($pair[0],$observer,in_array($mode,['reset','delete'],true)?$t->commerce_control():$t->carts());};
  if($first==='reconciliation'||$mode==='duplicate')check(d4_service($db)->reconcile($f['order'],$op)->is_completed(),'first exact cart writer commits');
  elseif($mode==='reset')validation_start($db);
  elseif($mode==='delete')d1_delete($db,$f);
  elseif($mode==='renewal')check(!d4_renew($db,$f)->is_success(),'paid Order refuses renewal while holding Cart then Order');
  else d4_edit($db,$f,4);
  $code=d4_join($pid,$pair[0]);$pid=null;
  check(in_array($code,['checkout_already_paid','cart_reconciled','started','deleted','edited','edit_refused','commerce_reset_fenced','order_deleted','database_error','continuation_verification_required'],true),'competing writer verifies current tuple or refuses stale snapshot: '.$code);
  check(d3_stock($db)===$stock,'cart contention preserves committed financial stock');
  if($mode==='duplicate')check((int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1')===1,'concurrent K11 deliveries subtract at most once');
  if($mode==='cart_edit')check((int)$db->get_var('SELECT quantity FROM '.$t->cart_items().' WHERE post_id=77')===($code==='edit_refused'?0:($first==='reconciliation'||$code!=='cart_reconciled'?4:3)),'cart/closure winner or confirmed refusal determines one safe subtraction');
  $observer->close();++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}d3_cleanup($db);}
}
echo "D4 independent-process cart and K12-gap races passed ($count scenarios).\n";
// Two actual signed processed deliveries: parent reaches K11; child's retained admission
// waits on the same control row before K12 and then validates the completed cart marker.
$prefix='c2c_d4_delivery_race_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);$pid=null;
try{
 [$f,$e,$op]=d4_fixture($db);$payload=d4_payload($f,$op);$stock=d3_stock($db);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
 if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D4Db($prefix);try{$connections=[];$r=webhook_deliver(webhook_controller($child,$connections,function($n,$index)use($pair){if($index===1){fwrite($pair[1],$n->dbh->thread_id."\n");fflush($pair[1]);}}),$payload);fwrite($pair[1],(string)$r->get_status()."\n");$child->close();exit(0);}catch(Throwable $x){fwrite($pair[1],'FAIL:'.$x->getMessage()."\n");exit(2);}}
 fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D4Db($prefix);$observer=new D4Db($prefix);$t=new TableNames($db);
 $db->at_query=function($q,$db)use($pair,$observer,$t){if(str_contains($q,'FROM '.$t->cart_items().' WHERE id = ')&&str_contains($q,'FOR UPDATE')){$db->at_query=null;$db->at_end=function()use($pair,$observer,$t){fwrite($pair[0],"go\n");fflush($pair[0]);d4_wait($pair[0],$observer,$t->commerce_control());};}};
 $connections=[];check(webhook_deliver(webhook_controller($db,$connections),$payload)->get_status()===200,'first full processed delivery reconciles under fresh K11 admission');$code=d4_join($pid,$pair[0]);$pid=null;check($code==='200','second full processed delivery independently validates terminal cart marker');
 check((int)$db->get_var('SELECT content_revision FROM '.$t->carts().' LIMIT 1')===1&&d3_stock($db)===$stock,'full duplicate deliveries subtract cart once and never replay stock');$observer->close();++$count;
}finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}d3_cleanup($db);}
echo "D4 complete independent-process matrix passed ($count scenarios).\n";
