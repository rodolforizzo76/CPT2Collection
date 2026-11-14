<?php
/** Phase 2: real production services/editor, disposable SQL tables, failures and two-process races. */
declare(strict_types=1);
if (!extension_loaded('mysqli') || !function_exists('pcntl_fork') || false===getenv('C2C_TEST_DB_NAME')) { echo "SKIP: mysqli, pcntl and C2C_TEST_DB_NAME required.\n"; exit; }
define('ABSPATH',__DIR__); define('MINUTE_IN_SECONDS',60);
$db_host=getenv('C2C_TEST_DB_HOST')?:'127.0.0.1'; $db_port=(int)(getenv('C2C_TEST_DB_PORT')?:3306);
$db_user=getenv('C2C_TEST_DB_USER')?:'root'; $db_pass=getenv('C2C_TEST_DB_PASSWORD')?:''; $db_name=getenv('C2C_TEST_DB_NAME');
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__.'/support/mariadb-wpdb.php';
define('CPT2COLLECTION_URL','https://shop.test/plugin/');
define('CPT2COLLECTION_PATH',dirname(__DIR__).'/');
define('CPT2COLLECTION_VERSION','test');
$class_files = [];
foreach (glob(CPT2COLLECTION_PATH.'includes/{,admin/}class-*.php', GLOB_BRACE) as $file) {
	$class_files[str_replace('-', '', basename($file, '.php'))] = $file;
}
spl_autoload_register(static function (string $class) use ($class_files): void {
	if (!str_starts_with($class, 'RodolfoRizzo\\Cpt2Collection\\')) {
		return;
	}
	$key = 'class'.strtolower(substr($class, strrpos($class, '\\') + 1));
	if (isset($class_files[$key])) {
		require_once $class_files[$key];
	}
});
use RodolfoRizzo\Cpt2Collection\{AdminStockService,AdminStockRepository,StockQuantity,TransactionManager,LockedCatalogReader,ReservationRepository,PostTypes,UtcClock,TableNames,MetaKeys,OrderRepository,ReservationAcquirer,PaymentSessionRepository,PaymentEventRepository,StockConversionProcessor,ConversionReservationSetValidator,SaleConversionService,CommittedOperation,ProviderClaimRecord,PaymentProviderSessionRequest,PaymentProviderLineItem,StripeEventNormalizer};
use RodolfoRizzo\Cpt2Collection\Admin\MetaBox;
class WP_Post_Type { public string $name='collection_item'; public bool $_builtin=false; public bool $public=true; public bool $show_ui=true; }
class WP_Post { public function __construct(public int $ID=1,public string $post_type='collection_item',public string $post_status='publish'){} }
function __(string $s,string $domain):string { if ($domain!=='cpt2collection') throw new RuntimeException('Wrong translation domain'); return $s; }
function esc_html(string $s):string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function esc_attr(mixed $s):string { return esc_html((string)$s); }
function esc_html__(string $s,string $d):string { return esc_html(__($s,$d)); }
function esc_attr__(string $s,string $d):string { return esc_attr(__($s,$d)); }
function sanitize_text_field(string $s):string { return trim(strip_tags($s)); }
function sanitize_key(string $s):string { return preg_replace('/[^a-z0-9_-]/','',strtolower($s)); }
function wp_unslash(mixed $s):mixed { return is_array($s)?array_map('wp_unslash',$s):(is_string($s)?stripslashes($s):$s); }
function current_user_can(string $cap,int $id):bool { return $GLOBALS['allowed'] && $cap==='edit_post' && $id>0; }
function current_time(string $type,bool $gmt):int { return strtotime('2026-09-25 12:00:00 UTC'); }
function get_option(string $name,mixed $default=false):mixed { return $name==='cpt2collection_post_type'?'collection_item':$default; }
function get_post_types(array $args,string $output):array { return ['collection_item'=>new WP_Post_Type()]; }
function apply_filters(string $hook,mixed $value):mixed { return $value; }
function wp_is_post_autosave(int $id):bool { return $GLOBALS['autosave']; }
function wp_is_post_revision(int $id):bool { return $GLOBALS['revision']; }
function wp_verify_nonce(string $n,string $action):bool { return $n==='valid' && in_array($action,['cpt2collection_save_meta','inlineeditnonce'],true); }
class QuickEditDie extends RuntimeException {}
function wp_doing_ajax():bool { return $GLOBALS['ajax']??false; }
function wp_die(string $message,string $title='',array $args=[]):never { if (($args['response']??0)!==200) throw new RuntimeException('Wrong Quick Edit HTTP status'); throw new QuickEditDie($message); }
function quick_form(mixed $expected='10',mixed $target='12'):void { $GLOBALS['ajax']=true; $_POST=['action'=>'inline-save','post_ID'=>'1','post_type'=>'collection_item','_inline_edit'=>'valid','cpt2collection_quick_stock'=>$target,'cpt2collection_quick_expected'=>$expected]; }
function quick_save(AdminStockService $service):string {
	$quick = new \RodolfoRizzo\Cpt2Collection\Admin\StockQuickEdit(new PostTypes(),$service);
	$quick->register();
	try { $GLOBALS['hooks']['save_post'][0](1,new WP_Post()); return ''; }
	catch (QuickEditDie $error) { return $error->getMessage(); }
}
function wp_nonce_field(string $action,string $name):void { echo '<input name="'.$name.'" value="valid">'; }
function selected(mixed $a,mixed $b):void { if ($a===$b) echo 'selected'; }
function checked(mixed $a):void { if ($a) echo 'checked'; }
function add_action(string $hook,callable $cb,int $priority=10,int $args=1):void { $GLOBALS['hooks'][$hook]=[$cb,$args]; }
function add_filter(string $hook,callable $cb,int $priority=10,int $args=1):void { add_action($hook,$cb,$priority,$args); }
function remove_query_arg(string $key,string $url):string { $parts=parse_url($url); parse_str($parts['query']??'',$args); unset($args[$key]); return strtok($url,'?').($args?'?'.http_build_query($args):''); }
function add_query_arg(string $key,mixed $value,string $url):string { $url=remove_query_arg($key,$url); return $url.(str_contains($url,'?')?'&':'?').http_build_query([$key=>$value]); }
function get_current_screen():object { return (object)['base'=>$GLOBALS['screen'],'post_type'=>'collection_item']; }
function wp_enqueue_script(mixed ...$args):void { $GLOBALS['enqueued']=$args; }
function wp_set_script_translations(string $handle,string $domain,string $path):void { if ($domain!=='cpt2collection') throw new RuntimeException('Wrong script domain'); }
function get_current_user_id():int { return 1; }
function get_user_meta(mixed ...$args):mixed { return []; }
function update_user_meta(mixed ...$args):void { ++$GLOBALS['notice_writes']; }
function wp_remote_post(mixed ...$args):never { throw new RuntimeException('Provider call'); }
function wp_remote_request(mixed ...$args):never { throw new RuntimeException('Provider call'); }
function wp_cache_delete(int $id,string $group):bool {
	$GLOBALS['cache'][]=[$id,$group,end($GLOBALS['db']->log)];
	if ($GLOBALS['cache_throw']) throw new RuntimeException('Cache failure');
	return true;
}
function get_post_meta(int $id,string $key,bool $single):mixed {
	if ($key===MetaKeys::STOCK_QUANTITY) throw new RuntimeException('Cached stock read');
	global $db;
	return $db->get_var($db->prepare("SELECT meta_value FROM {$db->postmeta} WHERE post_id=%d AND meta_key=%s",$id,$key))??'';
}
function update_post_meta(int $id,string $key,mixed $value):void {
	if ($key===MetaKeys::STOCK_QUANTITY) throw new RuntimeException('Unsafe WP stock overwrite');
	global $db; ++$GLOBALS['other_writes'];
	// Match WordPress update_metadata(): unchanged values are no-ops, existing rows update in place.
	$current = $db->get_var($db->prepare("SELECT meta_value FROM {$db->postmeta} WHERE post_id=%d AND meta_key=%s",$id,$key));
	if ($current === (string)$value) return;
	if ($current === null) {
		$db->insert($db->postmeta,['post_id'=>$id,'meta_key'=>$key,'meta_value'=>(string)$value],['%d','%s','%s']);
	} else {
		$db->update($db->postmeta,['meta_value'=>(string)$value],['post_id'=>$id,'meta_key'=>$key],['%s'],['%d','%s']);
	}
}
function delete_post_meta(int $id,string $key):void { global $db; ++$GLOBALS['other_writes']; $db->query($db->prepare("DELETE FROM {$db->postmeta} WHERE post_id=%d AND meta_key=%s",$id,$key)); }
class StockDb extends wpdb {
	public array $log=[]; public array $sql_errors=[]; public string $fail=''; public bool $suppressed=false; public $before_lock=null; public $after_lock=null;
	public function suppress_errors(bool $v):bool { $old=$this->suppressed; $this->suppressed=$v; return $old; }
	private function failure(int $errno=999):false { $this->last_errno=$errno; $this->last_error='PRIVATE injected failure'; return false; }
	public function query(string $sql):int|false {
		$this->log[]=$sql;
		if (($this->fail==='begin' && $sql==='START TRANSACTION') || ($this->fail==='rollback' && $sql==='ROLLBACK')) return $this->failure();
		if (str_starts_with($this->fail,'commit') && $sql==='COMMIT') {
			if ($this->fail==='commit_accepted') parent::query($sql);
			if ($this->fail==='commit_throw') throw new RuntimeException('PRIVATE commit exception');
			return $this->failure();
		}
		if (in_array($this->fail,['write','rollback'],true) && str_starts_with($sql,'UPDATE '.$this->postmeta.' SET meta_value')) return $this->failure();
		if ($this->fail==='insert' && str_starts_with($sql,'INSERT INTO '.$this->postmeta)) return $this->failure();
		return parent::query($sql);
	}
	public function get_results(string $sql):?array {
		$this->log[]=$sql;
		$lock=str_starts_with($sql,'SELECT ID FROM '.$this->posts.' WHERE');
		if (($lock && in_array($this->fail,['1205','1213'],true)) || ($this->fail==='read' && str_starts_with($sql,'SELECT p.post_type, p.post_status')) || ($this->fail==='reservations' && str_contains($sql,'SELECT epoch_id, id, post_id, quantity'))) { $this->failure($lock?(int)$this->fail:999); return null; }
		if ($lock && $this->before_lock) ($this->before_lock)();
		$result=parent::get_results($sql);
		if ($result===null) $this->sql_errors[]=$this->last_errno;
		if ($lock && $this->after_lock) ($this->after_lock)();
		return $result;
	}
}
$prefix='c2c_admin_stock_'.bin2hex(random_bytes(4)).'_'; $db=new StockDb($prefix); $created=[]; $checks=0;
$GLOBALS['allowed']=true; $GLOBALS['autosave']=false; $GLOBALS['revision']=false; $GLOBALS['cache']=[]; $GLOBALS['cache_throw']=false; $GLOBALS['other_writes']=0; $GLOBALS['notice_writes']=0; $GLOBALS['screen']='post';
function check(bool $ok,string $label):void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
function sql(string $q):void { global $db; if ($db->query($q)===false) throw new RuntimeException($db->last_error); }
function components():array {
	global $db; $t=new TableNames($db); $tx=new TransactionManager($db,$t); $r=new ReservationRepository($db,$t); $o=new OrderRepository($db,$t); $cat=new LockedCatalogReader($db,$tx);
	$service=new AdminStockService($db,new AdminStockRepository($db,$tx),$cat,$r,$tx,new PostTypes(),new UtcClock());
	$sale=new SaleConversionService($o,$r,new PaymentSessionRepository($db,$t),new PaymentEventRepository($db,$t),$tx,new StockConversionProcessor($r,$cat,$tx,new ConversionReservationSetValidator()),new ConversionReservationSetValidator(),new PostTypes(),new UtcClock());
	$acquire=new ReservationAcquirer($o,$r,$tx,$cat,new PostTypes(),new UtcClock());
	return [$service,new MetaBox(new PostTypes(),$service),$tx,$sale,$acquire];
}
// Offline normalized Event and exact original claim for the real paid continuation.
function paid_receipt(): \RodolfoRizzo\Cpt2Collection\PaymentEventReceipt {
	$reference = str_repeat('a', 32);
	$event = [
		'id' => 'evt_stock', 'type' => 'checkout.session.completed',
		'livemode' => false, 'created' => current_time('timestamp', true),
		'data' => ['object' => [
			'id' => 'cs_stock', 'object' => 'checkout.session', 'mode' => 'payment',
			'payment_intent' => 'pi_stock', 'currency' => 'eur', 'amount_total' => 100,
			'payment_status' => 'paid', 'status' => 'complete', 'client_reference_id' => $reference,
			'metadata' => [
				'c2c_integration' => 'cpt2collection', 'c2c_installation_id' => $reference,
				'c2c_epoch_identity' => str_repeat('b', 32), 'c2c_payment_session_reference' => $reference,
			],
		]],
	];
	return (new StripeEventNormalizer())->normalize($event, hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)))->receipt();
}
function convert_sale(SaleConversionService $sale): \RodolfoRizzo\Cpt2Collection\SaleConversionResult {
	global $db;
	$t = new TableNames($db);
	$claim = ProviderClaimRecord::from_row($db->get_row("SELECT * FROM {$t->provider_claims()} WHERE id=1"));
	return $sale->convert(1, 1, 1, CommittedOperation::authenticated_receipt($claim, paid_receipt(), (new UtcClock())->now_mysql()));
}
function admin_race(string $adapter, AdminStockService $service, MetaBox $box, string $target): \RodolfoRizzo\Cpt2Collection\AdminStockResult {
	if ($adapter==='quick') return quick_race($service,$target);
	if ($adapter==='service') return $service->set_exact(1,'10',$target);
	form('10',$target);
	$box->save(1,new WP_Post());
	$code=error_code($box);
	return $code==='' ? \RodolfoRizzo\Cpt2Collection\AdminStockResult::success((int)$target)
		: \RodolfoRizzo\Cpt2Collection\AdminStockResult::failure($code);
}
function fixture(?string $stock='10',string $post_status='publish'):void {
	global $db,$created,$prefix; $db->close(); $db=new StockDb($prefix); $GLOBALS['db']=$db; $db->fail=''; foreach (array_reverse($created) as $t) sql("DELETE FROM $t");
	seed_commerce_foundation($db);
	sql($db->prepare('UPDATE '.(new TableNames($db))->commerce_control().' SET baseline_contract=%s',modern_fixture_attestation()));
	$t=new TableNames($db); $now='2026-09-25 12:00:00';
	sql("INSERT INTO {$db->posts} VALUES(1,'collection_item','$post_status')");
	foreach ([MetaKeys::PRICE_MINOR=>'100',MetaKeys::STATUS=>'available',MetaKeys::STOCK_QUANTITY=>$stock] as $key=>$value) { if ($value!==null) sql($db->prepare("INSERT INTO {$db->postmeta} (post_id,meta_key,meta_value) VALUES(1,%s,%s)",$key,$value)); }
	sql("INSERT INTO {$t->orders()} (id,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,'awaiting_payment','EUR',100,100,'$now','$now')");
	sql("INSERT INTO {$t->order_items()} (id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,1,'Snapshot',100,1,100,100,'EUR','$now')");
	sql("INSERT INTO {$t->reservations()} (id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES(1,1,1,1,1,'active','$now','$now','2026-09-25 13:00:00')");
	sql("INSERT INTO {$t->payment_sessions()} (id,reference,order_id,provider,provider_session_id,creation_key_hash,status,active_slot,currency,amount_minor,created_at,updated_at,expires_at) VALUES(1,REPEAT('a',32),1,'stripe_checkout','cs_stock',REPEAT('b',64),'active',1,'EUR',100,'$now','$now','2026-09-25 13:00:00')");
	$receipt = paid_receipt();
	sql($db->prepare("INSERT INTO {$t->payment_events()} (epoch_id,ownership_state,id,provider,provider_event_id,event_type,livemode,payment_session_id,provider_session_id,local_reference,provider_payment_id,currency,amount_minor,payment_status,session_status,processing_status,received_at,payload_sha256,provider_created_at,normalized_payload) VALUES(1,'bound',1,'stripe_checkout','evt_stock','checkout.session.completed',0,1,'cs_stock',REPEAT('a',32),'pi_stock','EUR',100,'paid','complete','received','$now',%s,%s,%s)", $receipt->payload_sha256(), $receipt->provider_created_at(), $receipt->normalized_payload()));
	$reference = str_repeat('a', 32);
	$request = new PaymentProviderSessionRequest($reference, 'c2c_ps_'.$reference, 'EUR', 100,
		[new PaymentProviderLineItem('Snapshot', 100, 1)], '2026-09-25 13:00:00',
		['environment'=>'test', 'installation_id'=>$reference, 'epoch_identity'=>str_repeat('b', 32)]);
	sql($db->prepare("INSERT INTO {$t->provider_claims()} (id,installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,request_snapshot,environment,evidence_state,disposition,created_at) VALUES(1,REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout',REPEAT('a',32),1,1,REPEAT('b',64),CONCAT('c2c_ps_',REPEAT('a',32)),2,%s,'test','native','open','$now')", $request->snapshot()));
	foreach ([['session','cs_stock'],['payment','pi_stock']] as [$kind,$value]) {
		sql($db->prepare("INSERT INTO {$t->provider_identities()} (provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,environment,disposition) VALUES ('stripe_checkout',%s,%s,1,'bound','native',1,%s,'test','pending')",$kind,$value,$now));
	}
	sql($db->prepare("INSERT INTO {$t->provider_identities()} (provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,payload_sha256,event_type,environment,disposition) VALUES ('stripe_checkout','event','evt_stock',1,'bound','native',1,%s,%s,'checkout.session.completed','test','pending')",$now,$receipt->payload_sha256()));
	$db->log=[]; $GLOBALS['cache']=[]; $GLOBALS['other_writes']=0; $GLOBALS['notice_writes']=0; $_POST=[]; $_GET=[];
}
function stock():?string { global $db; return $db->get_var($db->prepare("SELECT meta_value FROM {$db->postmeta} WHERE post_id=1 AND meta_key=%s ORDER BY meta_id",MetaKeys::STOCK_QUANTITY)); }
function form(mixed $expected='10',mixed $target='10'):void { $_POST=['cpt2collection_meta_nonce'=>'valid','cpt2collection_stock_expected'=>$expected,'cpt2collection_stock_quantity'=>$target,'cpt2collection_price'=>'1.00','cpt2collection_status'=>'available','cpt2collection_rating'=>'4']; }
function error_code(MetaBox $box):string { parse_str(parse_url($box->redirect_location('https://shop.test/wp-admin/post.php?post=1&message=1',1),PHP_URL_QUERY),$args); return $args['cpt2collection_stock_error']??''; }
function snapshot():string { global $db,$created; $rows=[]; foreach ($created as $t) $rows[$t]=$db->get_results("SELECT * FROM $t ORDER BY 1"); return serialize($rows); }
try {
	$tables=new TableNames($db); $source=file_get_contents(dirname(__DIR__).'/includes/class-database.php');
	preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
	foreach ($matches[1] as $definition) { $definition=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$tables->{$m[1]}(),$definition); preg_match('/\A([^\s]+)/',$definition,$name); sql('CREATE TABLE '.$definition.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); $created[]=$name[1]; }
	sql("CREATE TABLE {$db->posts} (ID BIGINT UNSIGNED PRIMARY KEY,post_type VARCHAR(32),post_status VARCHAR(32)) ENGINE=InnoDB"); $created[]=$db->posts;
	sql("CREATE TABLE {$db->postmeta} (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,post_id BIGINT UNSIGNED,meta_key VARCHAR(255),meta_value LONGTEXT,KEY post_id(post_id),KEY meta_key(meta_key(191))) ENGINE=InnoDB"); $created[]=$db->postmeta;
	seed_commerce_foundation($db); $created=array_merge($created,migrate_commerce_phase_two_fixture($db));
	$created[]=$tables->order_deletions(); $created[]=$tables->commerce_resets();
	attest_modern_runtime_fixture($db); current_provider_runtime_fixture($db);
	fixture(); [$s,$box,$tx,$sale]=components(); $box->register();
	check($GLOBALS['hooks']['save_post'][1]===3,'editor registers server insertion/update flag');
	ob_start(); $box->render(new WP_Post()); $html=ob_get_clean();
	$box->enqueue_block_editor(); check($GLOBALS['enqueued'][0]==='cpt2collection-admin-stock' && in_array('wp-api-fetch',$GLOBALS['enqueued'][2],true),'block editor feedback script has explicit WordPress dependencies');
	check(str_contains($html,'name="cpt2collection_stock_expected" value="10"') && str_contains($html,'>Stock</label>') && str_contains($html,'Units physically remaining, including units reserved for checkout.'),'uncached editor renders exact baseline and physical-stock meaning');
	$converted = convert_sale($sale);
	check($converted->is_success() && stock()==='9','authoritative production sale commits 10 to 9: '.$converted->code());
	$db->log=[]; form(); $box->save(1,new WP_Post());
	check(stock()==='9' && !in_array('START TRANSACTION',$db->log,true) && $GLOBALS['other_writes']>0,'A: unchanged stale editor preserves sale stock 9 while saving other fields');
	form('10','12'); $GLOBALS['other_writes']=0; $box->save(1,new WP_Post());
	check(error_code($box)==='stock_conflict' && stock()==='9' && $GLOBALS['other_writes']===0,'B: intentional stale edit conflicts and preserves other commerce fields');
	check(!str_contains($box->redirect_location('https://shop.test/?message=1',1),'message=1'),'stock rejection removes generic WordPress success message');
	$post=new WP_Post(); ob_start(); $box->display_stock_error(); $notice=ob_get_clean();
	check(str_contains($notice,'changed since this form was opened') && $GLOBALS['notice_writes']===0 && !str_contains($notice,'PRIVATE'),'visible conflict without diagnostic disclosure or notice DB writes');
	fixture(); [$s]=components(); check($s->set_exact(1,'10','12')->is_success(),'C: first administrator commits target 12');
	check($s->set_exact(1,'10','14')->code()==='stock_conflict' && stock()==='12','C: second administrator cannot overwrite first');
	check($s->set_exact(1,'10','12')->code()==='stock_conflict' && stock()==='12','repeated original exact-set request conflicts rather than reloading expected');
	fixture(); $t=new TableNames($db); sql("UPDATE {$t->reservations()} SET quantity=4"); [$s]=components();
	check($s->set_exact(1,'10','3')->code()==='below_reservations' && stock()==='10','D: target below active reservations rejected');
	$before=$db->get_results("SELECT * FROM {$t->reservations()}");
	check($s->set_exact(1,'10','4')->is_success() && stock()==='4','D: reservation floor equality accepted');
	check($s->set_exact(1,'4','7')->is_success() && $before==$db->get_results("SELECT * FROM {$t->reservations()}"),'D: target above floor accepted without changing reservations');
	foreach (['active','expired','released','converted'] as $status) {
		fixture(); sql("UPDATE {$t->reservations()} SET quantity=50,status='$status',expires_at='2026-09-25 12:00:00'"); [$s]=components();
		check($s->set_exact(1,'10','0')->is_success() && stock()==='0','nonblocking reservation status/deadline: '.$status);
	}
	foreach ([0,'0',1,'1',PHP_INT_MAX,(string)PHP_INT_MAX] as $value) check(StockQuantity::parse($value)===(int)$value,'G: accepts strict bounded integer '.json_encode($value));
	foreach ([-1,'-1','1.0',1.0,'1e2','bad','',null,[],new stdClass(),true,false,' 1','01','+1',str_repeat('9',30)] as $value) {
		fixture(); [$s,$box]=components(); $db->log=[];
		check($s->set_exact(1,'10',$value)->code()==='invalid_input' && $db->log===[],'G: rejects invalid target '.json_encode($value));
		check($s->set_exact(1,$value,'12')->code()==='invalid_input' && $db->log===[],'G: rejects invalid expected '.json_encode($value));
		form('10',$value); $box->save(1,new WP_Post()); check(error_code($box)==='invalid_input' && $GLOBALS['other_writes']===0,'G: editor rejects invalid explicit input');
	}
	fixture(); [$s,$box]=components(); form(); unset($_POST['cpt2collection_stock_quantity'],$_POST['cpt2collection_stock_expected']); $box->save(1,new WP_Post());
	check(stock()==='10' && !in_array('START TRANSACTION',$db->log,true),'missing stock field leaves stock untouched');
	form(); unset($_POST['cpt2collection_stock_expected']); $box->save(1,new WP_Post()); check(error_code($box)==='invalid_input','explicit stock requires valid baseline');
	foreach (['nonce','nonce_missing','nonce_invalid','missing_product','capability','autosave','revision','wrong_type'] as $guard) {
		fixture(); [$s,$box]=components(); form('10','12'); $p=new WP_Post();
		if ($guard==='nonce') $_POST['cpt2collection_meta_nonce']=[];
		if ($guard==='nonce_missing') unset($_POST['cpt2collection_meta_nonce']);
		if ($guard==='nonce_invalid') $_POST['cpt2collection_meta_nonce']='invalid';
		if ($guard==='missing_product') $p->ID=2;
		if ($guard==='capability') $GLOBALS['allowed']=false;
		if ($guard==='autosave') $GLOBALS['autosave']=true;
		if ($guard==='revision') $GLOBALS['revision']=true;
		if ($guard==='wrong_type') $p->post_type='other';
		$box->save($p->ID,$p); check(stock()==='10' && $GLOBALS['other_writes']===0,'editor save guard: '.$guard);
		$GLOBALS['allowed']=true; $GLOBALS['autosave']=false; $GLOBALS['revision']=false;
	}
	fixture(); [$s]=components(); $GLOBALS['allowed']=false; $db->log=[];
	check($s->set_exact(1,'10','12')->code()==='not_authorized' && $db->log===[],'service independently enforces per-product capability'); $GLOBALS['allowed']=true;
	sql("UPDATE {$db->posts} SET post_type='other'"); check($s->set_exact(1,'10','12')->code()==='product_invalid' && stock()==='10','service checks persisted CPT under product lock');
	fixture(); [$s,$box]=components(); $box->register();
	$editor_save=$GLOBALS['hooks']['save_post'][0];
	$t=new TableNames($db);
	sql("UPDATE {$t->reservations()} SET quantity=4");
	$reservations=$db->get_results("SELECT * FROM {$t->reservations()}");
	form('10','12'); $editor_save(1,new WP_Post(),true);
	check(stock()==='12' && error_code($box)==='', 'editor callback saves valid stock increase');
	form('10','13'); $editor_save(1,new WP_Post(),true);
	check(stock()==='12' && error_code($box)==='stock_conflict', 'editor callback rejects stale administrator baseline');
	form('12','3'); $editor_save(1,new WP_Post(),true);
	check(stock()==='12' && error_code($box)==='below_reservations', 'editor callback reports reservation floor');
	form('12','5'); $editor_save(1,new WP_Post(),true);
	check(stock()==='5' && error_code($box)==='' && $reservations==$db->get_results("SELECT * FROM {$t->reservations()}"), 'editor callback decreases stock above the floor without changing reservations');
	ob_start(); $box->render(new WP_Post());
	check(str_contains(ob_get_clean(),'name="cpt2collection_stock_expected" value="5"'), 'editor reload reflects committed stock');
	foreach (['missing','malformed'] as $foundation) {
		fixture(); [$s,$box]=components();
		if ($foundation==='missing') sql("DELETE FROM {$t->commerce_control()}");
		else sql("UPDATE {$t->commerce_control()} SET baseline_contract='{}'");
		$before=snapshot(); form('10','12'); $box->save(1,new WP_Post());
		check(error_code($box)==='database_error' && snapshot()===$before && $GLOBALS['cache']===[], 'editor refuses stock writes with '.$foundation.' commerce admission');
		quick_form();
		check(quick_save($s)!=='' && snapshot()===$before && $GLOBALS['cache']===[], 'Quick Edit refuses stock writes with '.$foundation.' commerce admission');
		$GLOBALS['ajax']=false;
	}

	foreach ([null,'invalid','01','-1',str_repeat('9',30),'duplicate'] as $value) {
		fixture($value==='duplicate'?'10':$value);
		if ($value==='duplicate') sql($db->prepare("INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES(1,%s,'10')",MetaKeys::STOCK_QUANTITY));
		[$s,$box]=components(); $before=snapshot(); $code=$value===null?'stock_missing':($value==='duplicate'?'stock_duplicate':'stock_invalid');
		check($s->set_exact(1,'10','12')->code()===$code && snapshot()===$before,'H: anomalous persistence rejected without repair: '.$code);
		form('10','10'); $box->save(1,new WP_Post()); check(error_code($box)===$code && snapshot()===$before,'H: unchanged editor cannot normalize anomalous storage');
		ob_start(); $box->render(new WP_Post()); $html=ob_get_clean(); check(str_contains($html,'disabled') && str_contains($html,'needs technical review'),'H: anomalous editor state visible and stock disabled');
	}
	fixture(null,'auto-draft'); [$s,$box]=components(); $box->save(1,new WP_Post(1,'collection_item','auto-draft'),false);
	check(stock()==='1','new auto-draft initializes explicit default before first editor render');
	check($s->initialize_auto_draft(1,false)->is_success() && stock()==='1','initialization never duplicates an existing valid stock row');
	sql("UPDATE {$db->posts} SET post_status='publish'"); form('1','3'); $box->save(1,new WP_Post(),true); check(stock()==='3','first normal product submission can deliberately change initialized stock');
	fixture(null); [$s,$box]=components();
	check($s->initialize_auto_draft(1,false)->code()==='initialization_not_allowed' && stock()===null,'existing published missing stock cannot use initializer');
	fixture(null,'auto-draft'); [$s,$box]=components(); $db->fail='insert';
	check($s->initialize_auto_draft(1,false)->code()==='database_error' && $GLOBALS['cache']===[],'initialization insert failure rolls back without cache success'); $db->fail=''; check(stock()===null,'failed initialization leaves metadata missing');
	fixture(); [$s]=components(); check($s->set_exact(1,'10',(string)PHP_INT_MAX)->is_success() && stock()===(string)PHP_INT_MAX,'maximum supported stock persists without truncation');
	fixture(null,'auto-draft'); [$s,$box]=components(); check($s->initialize_auto_draft(1,true)->code()==='initialization_not_allowed','server update lifecycle cannot initialize missing legacy stock');
	fixture(); [$s,$box]=components(); sql("DELETE FROM {$t->reservations()}"); form('10','0'); $box->save(1,new WP_Post());
	check(stock()==='0' && get_post_meta(1,MetaKeys::STATUS,true)==='not_for_sale','zero stock keeps existing Available validation, never becomes Sold');
	fixture(); [$s,$box]=components(); form('10','3'); sql("UPDATE {$t->reservations()} SET quantity=4"); $box->save(1,new WP_Post());
	check(get_post_meta(1,MetaKeys::STATUS,true)==='available' && $GLOBALS['other_writes']===0,'failed stock does not persist a status based on uncommitted target');
	foreach (['read','reservations','write','1205','1213','begin','commit','commit_accepted','commit_throw','rollback'] as $failure) {
		fixture(); [$s,$box,$tx]=components(); $db->fail=$failure; $db->log=[]; form('10','12'); $box->save(1,new WP_Post());
		$unknown=in_array($failure,['begin','commit','commit_accepted','commit_throw','rollback'],true);
		check(error_code($box)===($unknown?'transaction_outcome_unknown':(in_array($failure,['1205','1213'],true)?'stock_busy':'database_error')),'I: explicit safe failure outcome '.$failure);
		check(count(array_filter($db->log,fn($q)=>$q==='START TRANSACTION'))===1 && $GLOBALS['cache']===[] && $GLOBALS['other_writes']===0 && $GLOBALS['notice_writes']===0,'I: no retry, cache eviction, or subsequent editor writes: '.$failure);
		if ($unknown) { $log=$db->log; check(!$s->set_exact(1,'10','12')->is_success() && $db->log===$log,'I: uncertain service refuses subsequent work'); }
		$db->fail=''; $db->close(); $db=new StockDb($prefix);
		check(stock()===($failure==='commit_accepted'?'12':'10'),'I: persisted outcome observed on fresh connection: '.$failure);
	}
	fixture(); [$s]=components(); $db->log=[]; $r=$s->set_exact(1,'10','12');
	check($r->is_success() && $GLOBALS['cache']===[[1,'post_meta','COMMIT']],'cache eviction only follows confirmed changed commit');
	$log=$db->log; check(!preg_match('/(?:orders|payment_sessions|payment_events|cart_items|carts)\b/',implode("\n",$log)),'admin writer never reads/locks/mutates orders, payments or carts');
	check(strpos(implode("\n",$log),'SELECT ID FROM')<strpos(implode("\n",$log),'SELECT epoch_id, id, post_id, quantity'),'product mutex precedes reservation locks');
	$GLOBALS['cache_throw']=true; check($s->set_exact(1,'12','13')->is_success() && stock()==='13','cache exception cannot negate committed stock success'); $GLOBALS['cache_throw']=false;
	fixture(); [$s,$box,$tx]=components(); $tx->begin(); $log=$db->log;
	check($s->set_exact(1,'10','12')->code()==='transaction_already_active' && $db->log===$log,'service refuses nested transaction'); $tx->rollback();
	sql("ALTER TABLE {$db->postmeta} ENGINE=MyISAM"); [$s]=components(); $db->log=[];
	check($s->set_exact(1,'10','12')->code()==='transactions_not_supported' && !in_array('START TRANSACTION',$db->log,true),'nontransactional storage rejected before mutation'); sql("ALTER TABLE {$db->postmeta} ENGINE=InnoDB");

	require __DIR__.'/support/admin-stock-quick-edit-cases.php';

	// Independent processes: first operation holds the real product row; second reaches its lock before release.
	$has_snapshot_setting=$db->get_results("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")!==[];
	foreach (['service','editor','quick'] as $adapter) {
	foreach ($has_snapshot_setting?['OFF','ON']:['default'] as $snapshot_mode) {
	foreach ([['admin','acquire'],['acquire','admin'],['admin','sale'],['sale','admin']] as [$first,$second]) {
		fixture(); $is_sale=in_array('sale',[$first,$second],true);
		if (!$is_sale) { sql("DELETE FROM {$t->reservations()}"); sql("UPDATE {$t->orders()} SET status='pending',subtotal_minor=400,total_minor=400"); sql("UPDATE {$t->order_items()} SET quantity=4,line_subtotal_minor=400,line_total_minor=400"); }
		$db->close(); $workers=[];
		foreach ([$first,$second] as $index=>$operation) {
			$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP); foreach ($pair as $sock) stream_set_timeout($sock,15);
			$pid=pcntl_fork();
			if ($pid===0) {
				fclose($pair[0]); $db=new StockDb($prefix);
				if ($has_snapshot_setting) $db->query("SET SESSION innodb_snapshot_isolation=$snapshot_mode");
				[$s,$box,$tx,$sale,$acquire]=components();
				$db->before_lock=function()use($pair){fwrite($pair[1],"attempt\n");};
				if ($index===0) $db->after_lock=function()use($pair){fwrite($pair[1],"locked\n");if(fgets($pair[1])!=="go\n")throw new RuntimeException('Gate timeout');};
				try {
					$r=match($operation){'admin'=>admin_race($adapter,$s,$box,$is_sale?'12':'3'),'sale'=>convert_sale($sale),'acquire'=>$acquire->acquire(1)};
					fwrite($pair[1],json_encode(['code'=>$operation==='acquire'?$r->get_code():$r->code(),'active'=>$tx->is_active(),'sql_errors'=>$db->sql_errors,'diagnostics'=>$operation==='acquire'?$acquire->get_last_diagnostics():[]])."\n"); $db->close(); fclose($pair[1]); exit(0);
				} catch(Throwable $e) { fwrite($pair[1],json_encode(['error'=>$e->getMessage()])."\n"); exit(1); }
			}
			fclose($pair[1]); $workers[]=[$pid,$pair[0]];
			check(fgets($pair[0])==="attempt\n",'['.$adapter.'] concurrent '.$operation.' reaches product mutex');
			if ($index===0) check(fgets($pair[0])==="locked\n",'first '.$operation.' holds product mutex');
		}
		// Second cannot finish while first holds the mutex (readable data would mean it bypassed the lock).
		$read=[$workers[1][1]]; $write=null; $except=null; check(stream_select($read,$write,$except,0,150000)===0,'second operation blocks on common product mutex');
		fwrite($workers[0][1],"go\n"); $results=[]; $details=[];
		foreach($workers as [$pid,$socket]) { $r=json_decode((string)fgets($socket),true); pcntl_waitpid($pid,$status); fclose($socket); check(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0 && isset($r['active']) && !$r['active'],'concurrent operation finishes without open transaction'); $results[]=$r['code']; $details[]=$r; }
		$db=new StockDb($prefix);
		$admin_snapshot_abort=$snapshot_mode==='ON' && $second==='admin' && $results[1]==='database_error';
		if ($admin_snapshot_abort) {
			check($details[1]['sql_errors']===[1020], 'snapshot isolation rejects the administrator locking read with ER_CHECKREAD');
			[$s,$box]=components();
			$before=stock();
			$expected_code=$is_sale?'stock_conflict':'below_reservations';
			check(admin_race($adapter,$s,$box,$is_sale?'12':'3')->code()===$expected_code && stock()===$before,
				'fresh admission with the original baseline exposes the expected rejection');
		}
		if (!$is_sale) {
			$snapshot_abort=$snapshot_mode==='ON' && $first==='admin';
			check($results===($first==='admin'?['stock_updated',$snapshot_abort?'database_error':'insufficient_stock']:['reservation_acquired',$admin_snapshot_abort?'database_error':'below_reservations']),'E: acquisition/decrease outcomes respect ordering (snapshot '.$snapshot_mode.'): '.json_encode($results));
			if ($snapshot_abort) check($details[1]['diagnostics']['error_number']===1020,'snapshot isolation explicitly aborts changed catalog read, never over-reserves');
			$reserved=(int)$db->get_var("SELECT COALESCE(SUM(quantity),0) FROM {$t->reservations()} WHERE status='active'"); check((int)stock()>=$reserved,'E: concurrent decrease never writes below active reservation coverage');
		} else {
			$snapshot_abort=$snapshot_mode==='ON' && $first==='admin';
			check($results===($first==='admin'?['stock_updated',$snapshot_abort?'database_error':'sale_converted']:['sale_converted',$admin_snapshot_abort?'database_error':'stock_conflict']),'F: sale/admin outcomes respect ordering (snapshot '.$snapshot_mode.'): '.json_encode($results));
			if ($snapshot_abort) {
				check(stock()==='12' && $db->get_var("SELECT status FROM {$t->orders()} WHERE id=1")==='awaiting_payment','snapshot conflict rolls back whole sale without any partial decrement');
				[,,$tx,$sale]=components(); check(convert_sale($sale)->is_success(),'fresh authoritative sale retry after confirmed rollback succeeds');
			}
			check(stock()===($first==='admin'?'11':'9') && $db->get_var("SELECT status FROM {$t->orders()} WHERE id=1")==='paid','F: committed sale decrement is never lost');
			[,,$tx,$sale]=components(); $before=stock(); check(convert_sale($sale)->is_success() && stock()===$before,'F: sale replay never decrements or restores stock again');
		}
	}
	}
	}
	echo "All $checks administrative stock checks passed.\n";
} finally { $db->fail=''; foreach(array_reverse($created) as $table) $db->query("DROP TABLE IF EXISTS $table"); $db->close(); }
