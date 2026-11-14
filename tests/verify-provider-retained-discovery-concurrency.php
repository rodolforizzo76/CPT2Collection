<?php
/** Independent production writers commit while the owned MVCC discovery snapshot is paused. */
declare(strict_types=1);
require __DIR__.'/support/provider-retained-discovery.php';
use RodolfoRizzo\Cpt2Collection\{ProviderRetainedDiscovery,TransactionManager,TableNames,OrderDeletionService,OrderDeletionRepository,OrderDeletionRegistry,UtcClock,ProviderRegistry};
if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required');
foreach(['reset_start','final_switch','later_reset','permanent_delete','identity_insert','identity_quarantine'] as $mode){
 $prefix='c2c_a0_race_'.bin2hex(random_bytes(5)).'_';$db=new DiscoveryDb($prefix);$pid=null;
 try{
  $r=null;
  if(in_array($mode,['final_switch','later_reset'],true)){[$r,$fs]=final_setup($db);$f=$fs[0];if($mode==='later_reset')$r=validation_service($db)->finalize($r)->operation();}
  else{validation_schema($db);$f=validation_order($db);}
  $other=$mode==='identity_quarantine'?validation_order($db):null;
  if($mode==='identity_quarantine')discovery_bind($db,$f,'session','cs_discovery');
  $input=discovery_input($db,$f);$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if($pid<0)throw new RuntimeException('fork failed');
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],15);try{fgets($pair[1]);$child=new DiscoveryDb($prefix);$t=new TableNames($child);
   if($mode==='reset_start'||$mode==='later_reset')check(validation_service($child)->start(bin2hex(random_bytes(16)),$mode==='later_reset'?$r->target_epoch_identity():$f['epoch'])->code()==='started','independent production start commits');
   elseif($mode==='final_switch')check(validation_service($child)->finalize($r)->code()==='completed','independent final switch commits');
   elseif($mode==='permanent_delete')check((new OrderDeletionService(new OrderDeletionRepository($child,$t),new OrderDeletionRegistry($child,$t),new TransactionManager($child,$t),new UtcClock()))->delete($f['order'])->code()==='deleted','independent genuine deletion commits');
   else{$tx=new TransactionManager($child,$t);check($tx->begin_commerce_shared(),'independent identity authority');$code=(new ProviderRegistry($child,$t))->bind('stripe_checkout','session','cs_discovery',$mode==='identity_quarantine'?$other['claim']:$f['claim'],gmdate('Y-m-d H:i:s'),null,null,'test');check($code===($mode==='identity_quarantine'?'conflict':'bound')&&$tx->commit(),'independent identity transition commits');}
   fwrite($pair[1],"committed\n");$child->close();exit(0);
  }catch(Throwable $e){fwrite($pair[1],'FAIL:'.$e->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],15);
  // Pause after START WITH CONSISTENT SNAPSHOT, before the first data read.
  $reader=new class($prefix,$pair[0]) extends DiscoveryDb {
   private bool $paused=false;
   public function __construct(string $p,private $channel){parent::__construct($p);}
   public function query(string $q):int|false{$out=parent::query($q);if(!$this->paused&&str_starts_with($q,'START TRANSACTION WITH CONSISTENT SNAPSHOT')){$this->paused=true;fwrite($this->channel,"go\n");fflush($this->channel);check(trim(fgets($this->channel))==='committed','writer commits while A0 snapshot paused without row-lock wait');}return $out;}
  };
  $db=$reader;$t=new TableNames($db);$db->capture=true;$db->captured=[];$old=(new ProviderRetainedDiscovery($db,$t))->discover($input);$db->capture=false;
  check($old->attributed(),'paused classifier returns coherent pre-transition owner');
  check($old->fence===match($mode){'final_switch'=>'active_reset_source','later_reset'=>'completed_retired',default=>'current_ready'},'owned read point preserves pre-transition fence');
  if($mode==='identity_insert')check($old->identities['session']->classification==='missing','old snapshot does not mix committed new identity');
  if($mode==='identity_quarantine')check($old->identities['session']->classification==='compatible','old snapshot does not mix later quarantine');
  if($mode==='permanent_delete')check($old->owner->lifecycle==='open'&&$old->deletion===null,'old snapshot cannot mix deleted claim and missing D');
  foreach($db->captured as $q)check(!preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i',$q)&&!str_contains($q,'FOR UPDATE')&&!str_contains($q,'LOCK IN SHARE MODE'),'A0 emits no write/row lock in concurrency');
  $fresh=(new ProviderRetainedDiscovery($db,$t))->discover($input);
  check(match($mode){'reset_start'=>$fresh->fence==='active_reset_source','final_switch'=>$fresh->fence==='completed_retired','later_reset'=>$fresh->fence==='older_retired_during_active_reset','permanent_delete'=>$fresh->owner->lifecycle==='permanently_deleted','identity_insert'=>$fresh->duplicate,'identity_quarantine'=>$fresh->status==='conflict'},'fresh snapshot observes fully committed transition');
  pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent transition exits cleanly');fclose($pair[0]);
 }finally{if($pid!==null&&$pid>0){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}baseline_cleanup($db);}
}
echo "All A0 independent MVCC races passed.\n";
