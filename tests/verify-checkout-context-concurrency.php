<?php
/** Phase 4.7 independent actors, actual Cart/control waits and exact context revalidation. */
declare(strict_types=1);
require __DIR__.'/support/checkout-context.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
function c47_wait($socket,wpdb $observer,string $table):void {
 $id=(int)trim((string)fgets($socket));$deadline=microtime(true)+10;
 do{$row=$observer->get_row("SELECT INFO,TIME_MS FROM information_schema.PROCESSLIST WHERE ID=$id");$waiting=$row!==null&&str_contains($row->INFO??'',$table)&&(float)$row->TIME_MS>=200;if(!$waiting)usleep(10000);}while(!$waiting&&microtime(true)<$deadline);
 $read=[$socket];$write=[];$except=[];check($waiting&&stream_select($read,$write,$except,0,0)===0,'47 independent waiter visibly blocked >=200ms at '.$table);
}
$count=0;
foreach(['create','same_create','renew','same_renew','edit_create','edit_renew','reset_create','reset_renew','delete_reset'] as $mode)foreach([false,true] as $reverse){
 $prefix='c2c_47_race_'.bin2hex(random_bytes(4)).'_';$db=new D4Db($prefix);$pid=null;
 try{
  if(str_contains($mode,'renew')||$mode==='renew'){[$f]=d4_expired_fixture($db);$initial_orders=1;}
  else {validation_schema($db);$f=$mode==='delete_reset'?validation_order($db,1,false,false):c47_new($db);$initial_orders=$mode==='delete_reset'?1:0;}
  $cart=c47_cart($db,$f);$token=c47_token($db,$f);$key1=bin2hex(random_bytes(16));$key2=str_starts_with($mode,'same_')?$key1:bin2hex(random_bytes(16));
  $other=str_starts_with($mode,'edit')?'edit':(str_starts_with($mode,'reset')?'reset':'checkout');$first=$reverse?$other:'checkout';$second=$reverse?'checkout':$other;
  if($mode==='delete_reset'){[$first,$second]=$reverse?['reset','delete']:['delete','reset'];}
  $db->close();$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
  $job=static function(D4Db $actor,string $kind,string $key)use($f,$cart,$token):array {
   $actor->capture=true;$actor->captured=[];
   if($kind==='edit')$out=['code'=>d4_edit($actor,$f,3,false)?'edited':'edit_refused','success'=>true];
   elseif($kind==='reset'){discovery_finish($actor,validation_start($actor));$out=['code'=>'reset_completed','success'=>true];}
   elseif($kind==='delete'){$r=c47_delete($actor,$f['order']);$out=['code'=>$r->code(),'success'=>$r->is_success()];}
   else {$r=d4_checkout($actor)->prepare($cart,$key,$token['token']);$out=['code'=>$r->get_code(),'success'=>$r->is_success()];}
   $out['dml']=count(array_filter($actor->captured,static fn($q)=>preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/',$q)));return $out;
  };
  if($pid===0){fclose($pair[0]);stream_set_timeout($pair[1],30);fgets($pair[1]);try{$child=new D4Db($prefix);fwrite($pair[1],$child->dbh->thread_id."\n");fflush($pair[1]);$r=$job($child,$second,($reverse&&$first==='checkout'&&$second==='checkout')?$key1:$key2);$child->close();fwrite($pair[1],json_encode($r)."\n");exit(0);}catch(Throwable $error){fwrite($pair[1],'FAIL:'.$error->getMessage()."\n");exit(2);}}
  fclose($pair[1]);stream_set_timeout($pair[0],30);$db=new D4Db($prefix);$observer=new D4Db($prefix);$t=new TableNames($db);
  $db->at_end=function()use($pair,$observer,$t,$mode){fwrite($pair[0],"go\n");fflush($pair[0]);c47_wait($pair[0],$observer,str_contains($mode,'reset')?$t->commerce_control():$t->carts());};
  $a=$job($db,$first,($reverse&&$first==='checkout'&&$second==='checkout')?$key2:$key1);check($db->at_end===null,'47 first actor reached real transaction end barrier');$line=trim((string)fgets($pair[0]));pcntl_waitpid($pid,$status);$pid=null;fclose($pair[0]);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'47 independent actor exits cleanly '.$line);$b=json_decode($line,true,512,JSON_THROW_ON_ERROR);
  if($mode==='delete_reset'){
   if(!$reverse)check(c47_delete($db,$f['order'])->code()==='already_deleted','deletion-first retained proof survives independent reset');else check(!$b['success']&&$b['dml']===0,'reset-first cannot grant deletion authority');
  }elseif(str_starts_with($mode,'reset')){
   check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===0,'completed reset erases checkout rows without resurrection');
   $db->capture=true;$db->captured=[];$late=d4_checkout($db)->prepare($cart,$key2,$token['token']);check(!$late->is_success(),'old tab after reset completion is denied');c47_no_dml($db);
   if($reverse)check(!$b['success']&&$b['dml']===0,'reset-winning checkout attempts zero DML');
  }elseif(str_starts_with($mode,'edit')){
   if($reverse)check(!$b['success']&&$b['dml']===0&&(int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===$initial_orders,'cart-edit winner invalidates old context before any checkout writes');
   else check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===$initial_orders+1,'checkout winner commits only its authorized original snapshot');
  }else{
   check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->orders())===$initial_orders+1,'two tabs commit exactly one new checkout generation');
   if($mode==='renew'&&!$b['success'])check($b['dml']===0,'stale renewal loser performs no DML');
  }
  check((int)$db->get_var('SELECT COUNT(*) FROM '.$t->payment_sessions()." WHERE status='creating'")===0,'race invents no Payment Session');check((int)$db->get_var("SELECT meta_value FROM {$db->postmeta} WHERE post_id=77 AND meta_key='_cpt2collection_stock_quantity'")===20,'checkout/reset/deletion race never mutates physical stock');
  echo 'C47_RACE '.json_encode(['mode'=>$mode,'first_tab'=>$reverse?'B':'A','first'=>$first,'first_result'=>$a,'second_result'=>$b])."\n";$observer->close();++$count;
 }finally{if($pid!==null){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}d3_cleanup($db);}
}
echo "47 independent-process matrix passed ($count scenarios).\n";
