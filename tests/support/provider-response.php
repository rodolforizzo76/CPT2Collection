<?php
/** Production synchronous stages with private evidence connections and SQL instrumentation. */
declare(strict_types=1);
require_once __DIR__.'/provider-retained-discovery.php';
require_once dirname(__DIR__,2).'/includes/class-provider-response-evidence.php';
use RodolfoRizzo\Cpt2Collection\{ProviderResponseEvidence,PaymentSessionService,TableNames,TransactionManager,OrderRepository,ReservationRepository,PaymentSessionRepository,ReservationSetValidator,OrderDeletionRegistry,UtcClock,CommerceContext,PaymentSessionRecord,PaymentProviderSessionRequest,ProviderClaimRecord};
class ResponseDb extends DiscoveryDb {
 public ?Closure $on_close=null;public ?Closure $after_read=null;public ?Closure $after_write=null;public bool $closed=false;public int $poison_queries=0;
 public function query(string $q):int|false {if(!$this->transaction_connection_usable()){++$this->poison_queries;throw new RuntimeException('poisoned query');}$out=parent::query($q);if($this->after_write!==null)($this->after_write)($q,$out);return $out;}
 public function get_results(string $q):?array {if(!$this->transaction_connection_usable()){++$this->poison_queries;throw new RuntimeException('poisoned read');}$out=parent::get_results($q);if($this->after_read!==null)($this->after_read)($q,$out);return $out;}
 public function close():void {if($this->closed)return;$this->closed=true;parent::close();if($this->on_close!==null)($this->on_close)();}
}
class ResponseFaultDb extends ResponseDb {
 public string $mode='';public bool $executed=false;public int $identity_writes=0;
 public function query(string $q):int|false {
  if($this->mode!==''&&(($q==='COMMIT'&&($this->identity_writes>0||$this->mode==='commit_noop')&&str_starts_with($this->mode,'commit'))||($q==='ROLLBACK'&&$this->identity_writes>0&&!str_starts_with($this->mode,'commit')))){
   if($this->executed)parent::query($q);else $this->captured[]=$q;
   $this->last_error='lost acknowledgement';return false;
  }
  if($this->mode==='second_write'&&str_starts_with($q,'INSERT INTO')&&str_contains($q,"'cs_recovery'")){$this->captured[]=$q;$this->last_error='second write failure';return false;}
  $out=parent::query($q);if($out!==false&&str_starts_with($q,'INSERT INTO')&&str_contains($q,'provider_identities'))++$this->identity_writes;return $out;
 }
 public function get_results(string $q):?array {
  if($this->mode==='after_inspection'&&$this->identity_writes===2&&str_starts_with($q,'SELECT * FROM '.$this->prefix.'c2c_provider_identities FORCE INDEX(identity)')&&!str_ends_with($q,'FOR UPDATE')){$this->captured[]=$q;$this->last_error='post-write read failure';return null;}
  return parent::get_results($q);
 }
}
function response_service(wpdb $db,\RodolfoRizzo\Cpt2Collection\PaymentProviderInterface $provider,array &$connections,?Closure $configure=null,?Closure $connect=null):PaymentSessionService {
 $t=new TableNames($db);$factory=function()use($db,&$connections,$configure,$connect){$c=$connect!==null?$connect($db->prefix):new ResponseDb($db->prefix);$c->capture=true;$connections[]=$c;if($configure!==null)$configure($c,count($connections));return $c;};
 return new PaymentSessionService(new OrderRepository($db,$t),new ReservationRepository($db,$t),new PaymentSessionRepository($db,$t),new TransactionManager($db,$t),new ReservationSetValidator(),$provider,new UtcClock(),new OrderDeletionRegistry($db,$t),new ProviderResponseEvidence($factory));
}
/** Native DATA is captured before invoking the real production network stage. */
function response_captured(wpdb $db,array $f):array {
 $t=new TableNames($db);$row=$db->get_row('SELECT * FROM '.$t->provider_claims().' WHERE id='.$f['claim']);$claim=ProviderClaimRecord::from_row($row);
 return [$f['order'],PaymentSessionRecord::from_row($db->get_row('SELECT * FROM '.$t->payment_sessions().' WHERE id='.$f['session'])),new CommerceContext($claim->installation_id(),$claim->epoch_id(),$claim->epoch_identity()),PaymentProviderSessionRequest::from_snapshot($row->request_snapshot),$claim];
}
function response_resume(PaymentSessionService $svc,array $capture):\RodolfoRizzo\Cpt2Collection\PaymentSessionResult {return (new ReflectionMethod($svc,'finish_provider_request'))->invokeArgs($svc,$capture);}
function response_business(wpdb $db):string {$a=unserialize(baseline_snapshot($db));unset($a['provider_identities'],$a['commerce_resets']);return serialize($a).deletion_stock($db);}
function response_no_operational(array $connections):void {
 foreach($connections as $c){check($c->closed&&$c->poison_queries===0,'private evidence connection discarded; no poisoned SQL');foreach($c->captured as $q){
  $t=new TableNames($c);
  foreach(\RodolfoRizzo\Cpt2Collection\CommerceResetDeletion::STAGES as $m)check(!str_contains($q,$t->$m()),'evidence never reads/writes '.$m);
  if(preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)){
   $identity=str_contains($q,$t->provider_identities())&&(str_starts_with($q,'INSERT INTO')||str_starts_with($q,'UPDATE '));
   $generation=preg_match('/\AUPDATE '.preg_quote($t->commerce_resets(),'/').' SET evidence_generation=[0-9]+ WHERE id=[0-9]+ AND active_slot=1 AND evidence_generation=[0-9]+\z/',$q)===1;
   check($identity||$generation,'B evidence attempts only identity DML or exact active-generation CAS');
  }
 }}
}

function response_send($s,string $v):void{fwrite($s,$v."\n");fflush($s);}
function response_signal($s):string{stream_set_timeout($s,30);$v=fgets($s);if($v===false)throw new RuntimeException('response IPC timeout');return trim($v);}
/** No database connection may be open when forking these actors. */
function response_worker(string $prefix,Closure $job):array {
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
 if($pid===0){fclose($pair[0]);try{$c=new ResponseDb($prefix);response_send($pair[1],(string)$c->dbh->thread_id);$result=$job($c,$pair[1]);$c->close();response_send($pair[1],'OK:'.$result);exit(0);}catch(Throwable $e){response_send($pair[1],'FAIL:'.$e->getMessage());exit(2);}}
 fclose($pair[1]);return [$pid,$pair[0],(int)response_signal($pair[0])];
}
function response_join(array $w):string{$v=response_signal($w[1]);pcntl_waitpid($w[0],$status);fclose($w[1]);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0&&str_starts_with($v,'OK:'),'independent response actor '.$v);return substr($v,3);}
function response_wait(wpdb $observer,int $id):void {
 $end=microtime(true)+10;$t=new TableNames($observer);
 do{
  $row=$observer->get_row("SELECT INFO,TIME_MS FROM information_schema.PROCESSLIST WHERE ID=$id");
  if($row!==null&&str_contains($row->INFO??'',$t->commerce_control())&&(str_contains($row->INFO,'FOR UPDATE')||str_contains($row->INFO,'LOCK IN SHARE MODE'))&&(float)$row->TIME_MS>=200){check(true,'actual MariaDB waiter held at bounded control locking SELECT for >=200ms');return;}
  usleep(30000);
 }while(microtime(true)<$end);
 throw new RuntimeException('No observed control waiter: '.json_encode($row));
}
