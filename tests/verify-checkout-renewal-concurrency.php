<?php
/** K14 actual Cart -> Order renewal versus Order -> stock conversion, both winners. */
declare(strict_types=1);
require __DIR__.'/support/checkout-renewal-edge.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
foreach(['sale','renewal'] as $first){
 $prefix='c2c_d4_renew_race_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);$pid=null;
 try{
  [$f,$expired,$expired_op]=d4_expired_fixture($db);[$paid,$op]=d3_event($db,$f,'checkout.session.completed','evt_renew_race');$db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
  if($pid===0){fclose($pair[0]);fgets($pair[1]);$child=new D4Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);try{
   $out=$first==='sale'?d4_renew($child,$f):d3_run($child,'conversion',$f,$paid,$op);$code=$first==='sale'?$out->get_code():$out->code();fwrite($pair[1],$code."\n");$child->close();exit(0);
  }catch(Throwable $x){fwrite($pair[1],'FAIL:'.$x->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],20);$db=new D4Db($prefix);$observer=new D4Db($prefix);$t=new TableNames($db);
  $db->at_end=function()use($pair,$observer,$t){
   fwrite($pair[0],"go\n");fflush($pair[0]);$id=(int)trim(fgets($pair[0]));$deadline=microtime(true)+10;
   do{$waiting=(int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$id AND INFO LIKE '%FROM {$t->orders()} %'")>0;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
   $r=[$pair[0]];$w=[];$e=[];check($waiting&&stream_select($r,$w,$e,0,200000)===0,'renewal/conversion waiter observes actual Order contention >=200ms');
  };
  $out=$first==='sale'?d3_run($db,'conversion',$f,$paid,$op):d4_renew($db,$f);check($first==='sale'?$out->is_success():(int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===2,'first financial/snapshot transaction commits; later reservation acquisition may refuse stale state: '.($first==='sale'?$out->code():$out->get_code()));
  $code=trim(fgets($pair[0]));pcntl_waitpid($pid,$status);$pid=null;check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'independent K14 actor finishes without lock inversion: '.$code);fclose($pair[0]);
  check($first==='sale'?in_array($code,['checkout_already_paid','database_error'],true):in_array($code,['sale_converted','database_error','continuation_verification_required'],true),'renewal preserves the winner and never adopts old Session owner');
  $status=$db->get_var('SELECT status FROM '.$t->orders().' WHERE id='.$f['order']);
  if($status==='paid')check(d4_service($db)->reconcile($f['order'],$op)->is_completed(),'old sale independently reconciles after renewal/conversion lock contention');
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===($first==='sale'?1:2),'only renewal-first can create a new Order');
  $observer->close();
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$s);}d3_cleanup($db);}
}
echo "K14 independent-process renewal/conversion orderings passed (2 scenarios).\n";
