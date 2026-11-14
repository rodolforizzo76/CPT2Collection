<?php
/** Cart creation/touch admission and post-commit cookie publication. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection {
 function headers_sent(){return false;}
 function setcookie($name,$value,$options){++$GLOBALS['cookie_calls'];return ($GLOBALS['cookie_probe'])($value);}
}
namespace {
require __DIR__.'/support/commerce-db-test.php';
define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);
function sanitize_text_field($v){return $v;}function wp_unslash($v){return $v;}
foreach(['cart-token','cart-cookie','cart-record','cart-item','cart-repository','utc-clock','cart-activity','cart-context'] as $class)require dirname(__DIR__).'/includes/class-'.$class.'.php';
use RodolfoRizzo\Cpt2Collection\{CartToken,CartCookie,CartRecord,CartRepository,CartContext,CartActivity,UtcClock,TableNames};
class CartLifecycleDb extends wpdb {
 public int $starts=0;public int $updates=0;public bool $lose_commit=false;
 public function query(string $q):int|false{if($q==='START TRANSACTION')++$this->starts;$r=parent::query($q);return $q==='COMMIT'&&$this->lose_commit?false:$r;}
 public function update(string $t,array $d,array $w,array $f,array $wf):int|false{++$this->updates;return parent::update($t,$d,$w,$f,$wf);}
}
$prefix='c2c_cart_gate_'.bin2hex(random_bytes(5)).'_';$db=new CartLifecycleDb($prefix);$tables=new TableNames($db);$created=create_commerce_foundation($db);$observer=new wpdb($prefix);
$GLOBALS['cookie_calls']=0;
try {
 $source=file_get_contents(dirname(__DIR__).'/includes/class-database.php');preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
 foreach($matches[1] as $ddl){if(!str_contains($ddl,'$tables->carts()')&&!str_contains($ddl,'$tables->cart_items()'))continue;$ddl=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$tables->{$m[1]}(),$ddl);preg_match('/\A([^\s]+)/',$ddl,$name);sql($db,'CREATE TABLE '.$ddl.' ENGINE=InnoDB');$created[]=$name[1];}
 sql($db,'ALTER TABLE '.$tables->carts().' ADD epoch_id BIGINT UNSIGNED NOT NULL DEFAULT 1 FIRST');
 attest_modern_runtime_fixture($db);
 $tokens=new CartToken();$cookie=new CartCookie($tokens);$clock=new UtcClock();$repo=new CartRepository($db,$tables);$activity=new CartActivity($repo,$clock);$context=new CartContext($repo,$tokens,$cookie,$clock,$activity);
 $GLOBALS['cookie_probe']=function($token)use($observer,$tables,$repo){check(!$repo->transactions()->is_active(),'cookie publication occurs after confirmed transaction release');check((int)$observer->get_var($observer->prepare('SELECT COUNT(*) FROM '.$tables->carts().' WHERE token_hash=%s',hash('sha256',$token)))===1,'independent connection sees committed cart before cookie');return true;};
 $cart=$context->get_or_create_cart();check($cart instanceof CartRecord && $GLOBALS['cookie_calls']===1 && $db->starts===1,'cart creation has one admitted transaction');
 $starts=$db->starts;check($context->get_existing_cart() instanceof CartRecord && $db->starts===$starts,'existing cart lookup is nonlocking');
 check($context->get_or_create_cart() instanceof CartRecord && $db->starts===$starts && $db->updates===0,'recent activity avoids transaction and write');
	 $old=gmdate('Y-m-d H:i:s',time()-1000);sql($db,$db->prepare('UPDATE '.$tables->carts().' SET last_activity_at=%s WHERE id=%d',$old,$cart->id()));$stale=$repo->find_active_by_token_hash($cart->token_hash(),$clock->now_mysql());
 sql($observer,$observer->prepare('UPDATE '.$tables->carts().' SET last_activity_at=%s WHERE id=%d',$clock->now_mysql(),$cart->id()));
 check($activity->maybe_touch($stale) instanceof CartRecord && $db->starts===$starts+1 && $db->updates===0,'due stale read reloads locked current activity and avoids redundant touch');
 sql($db,$db->prepare('UPDATE '.$tables->carts().' SET last_activity_at=%s WHERE id=%d',$old,$cart->id()));
 check($activity->maybe_touch($stale) instanceof CartRecord && $db->updates===1,'genuinely due touch writes under new admission');
 unset($_COOKIE[CartCookie::COOKIE_NAME]);$broken=new CartLifecycleDb($prefix);$broken->lose_commit=true;$r=new CartRepository($broken,new TableNames($broken));$a=new CartActivity($r,$clock);$c=new CartContext($r,$tokens,$cookie,$clock,$a);
 check(false===$c->get_or_create_cart() && $GLOBALS['cookie_calls']===1 && $broken->starts===1 && !$broken->transaction_connection_usable(),'uncertain creation never publishes cookie or retries');$broken->close();
}finally{foreach(array_reverse($created) as $name)$db->query('DROP TABLE IF EXISTS '.$name);$observer->close();$db->close();}
}
