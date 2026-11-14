<?php
/** Phase 3: real SQL reads and WordPress menu/rendering contract. Disposable production-schema tables only. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { echo "SKIP: mysqli and C2C_TEST_DB_NAME required.\n"; exit; }
define( 'ABSPATH', __DIR__ );
define('CPT2COLLECTION_URL','https://shop.example/plugins/c2c/');
define('CPT2COLLECTION_PATH',dirname(__DIR__).'/');
define('CPT2COLLECTION_VERSION','0.9.1');
$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = getenv( 'C2C_TEST_DB_NAME' );
mysqli_report( MYSQLI_REPORT_OFF );
require __DIR__ . '/support/mariadb-wpdb.php';
function __( string $s, string $d ): string { return $s; }
function _n( string $one, string $many, int $count, string $domain ): string {
	return 1 === $count ? $one : $many;
}
function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( string $text, string $domain = '' ): string { return esc_attr( $text ); }
function esc_html__( string $s, string $d ): string { return esc_html( $s ); }
function esc_attr( string $s ): string { return esc_html( $s ); }
function wp_unslash( mixed $value ): mixed { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function get_current_user_id():int{return 7;}
function wp_json_encode(mixed $value):string{return json_encode($value);}
function wp_create_nonce(string $action):string{return 'fixture-nonce';}
function rest_url(string $path):string{return 'https://shop.example/?rest_route=/'.$path;}
function wp_enqueue_script(...$args):void{$GLOBALS['enqueued'][]=$args;}
function wp_set_script_translations(...$args):void{}
function wp_remote_request( mixed ...$args ): never { ++$GLOBALS['provider_calls']; throw new RuntimeException('Provider call'); }
function wp_remote_post( mixed ...$args ): never { ++$GLOBALS['provider_calls']; throw new RuntimeException('Provider call'); }
function esc_url( string $s ): string { return esc_html( $s ); }
function current_user_can( string $cap ): bool { return 'manage_options' === $cap && $GLOBALS['allowed']; }
function number_format_i18n( float $n, int $d ): string { return number_format( $n, $d ); }
function admin_url( string $s ): string { return 'https://shop.example/wp-admin/' . $s; }
function add_query_arg( array|string $args, string $value_or_url, ?string $url = null ): string {
	if ( is_array( $args ) ) { return $value_or_url . '?' . http_build_query( $args ); }
	return $url . '?' . http_build_query( [ $args => $value_or_url ] );
}
function add_action( string $hook, callable $cb, int $priority = 10 ): void { $GLOBALS['hooks'][$hook][$priority][] = $cb; }
function add_menu_page( $title, $menu, $cap, $slug, $callback, $icon ): void { $GLOBALS['menu'][$slug] = compact( 'title', 'menu', 'cap', 'callback' ); }
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $callback ): void {
	if ( ! current_user_can( $cap ) ) { return; }
	// Model core's automatic parent mirror to catch accidental duplicates.
	if ( ! isset( $GLOBALS['submenu'][$parent] ) && $parent !== $slug ) { $GLOBALS['submenu'][$parent][$parent] = $GLOBALS['menu'][$parent]; }
	$GLOBALS['submenu'][$parent][$slug] = compact( 'title', 'menu', 'cap', 'callback' );
}
function register_setting( $group, $name, $args ): void { $GLOBALS['registered_settings'][$name] = [$group, $args]; }
function add_settings_section( ...$args ): void {}
function add_settings_field( ...$args ): void {}
function settings_fields( $group ): void { echo '<input name="option_page" value="' . esc_html( $group ) . '">'; }
function do_settings_sections( $page ): void { $GLOBALS['sections_page'] = $page; }
function submit_button(): void { echo '<button>Save</button>'; }
function sanitize_key( string $s ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function get_option( $name, $default = false ): mixed { return 'cpt2collection_currency' === $name ? 'EUR' : $default; }
class Redirected extends RuntimeException {}
function wp_safe_redirect( string $url ): void { throw new Redirected( $url ); }
foreach ( ['table-names','money','order-statuses','payment-session-statuses','order-admin-read-repository','post-types','commerce-settings','cart-page','stripe-settings'] as $file ) { require dirname(__DIR__) . '/includes/class-' . $file . '.php'; }
require dirname(__DIR__) . '/includes/admin/class-orders-page.php';
require dirname(__DIR__) . '/includes/admin/class-commerce-reset-page.php';
require dirname(__DIR__) . '/includes/admin/class-settings-page.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,OrderAdminReadRepository,PostTypes};
use RodolfoRizzo\Cpt2Collection\Admin\{OrdersPage,SettingsPage};
class ReadDb extends wpdb {
	public array $reads = [];
	public int $fail_at = 0;
	public bool $suppressed = false;
	public bool $hostile = false;
	public int $unsafe_attempts = 0;
	public function suppress_errors( bool $value ): bool { $old=$this->suppressed; $this->suppressed=$value; return $old; }
	public function query( string $sql ): int|false { ++$this->unsafe_attempts; throw new RuntimeException('Mutation attempted'); }
	public function get_results( string $sql ): ?array {
		$this->reads[] = $sql;
		if ( ! preg_match('/\ASELECT\s/i', $sql) || preg_match('/FOR UPDATE|FOR SHARE|LOCK IN SHARE|START TRANSACTION|SELECT\s+\*/i', $sql) ) { ++$this->unsafe_attempts; throw new RuntimeException('Unsafe read'); }
		if ( count($this->reads) === $this->fail_at ) { $this->last_error = 'PRIVATE SQL SECRET'; return null; }
		if (!$this->suppressed) { throw new RuntimeException('SQL error display was not suppressed'); }
		$rows = parent::get_results($sql);
		if ($this->hostile && str_contains($sql, 'SELECT id, status') && $rows) { $rows[0]->created_at='<script>unsafe</script> & "date"'; }
		return $rows;
	}
}
$checks = 0;
function check( bool $ok, string $label ): void { global $checks; if (!$ok) { throw new RuntimeException($label); } ++$checks; echo "PASS: $label\n"; }
function sql( wpdb $db, string $sql ): void { if (false === $db->query($sql)) { throw new RuntimeException($db->last_error); } }
function render( OrdersPage $page ): string { ob_start(); $page->render(); return ob_get_clean(); }
function snapshot( wpdb $db, array $tables ): string { $rows=[]; foreach ($tables as $table) {$rows[$table]=$db->get_results("SELECT * FROM $table ORDER BY id");} return serialize($rows); }
$prefix = 'c2c_admin_' . bin2hex(random_bytes(4)) . '_';
$writer = new wpdb($prefix); $reader = new ReadDb($prefix); $tables = new TableNames($writer);
$repo = new OrderAdminReadRepository($reader, $tables); $page = new OrdersPage($repo); $settings = new SettingsPage(new PostTypes());
$created=[]; $GLOBALS['allowed']=true; $GLOBALS['provider_calls']=0;
try {
	$reset_calls=0; $reset_page=new \RodolfoRizzo\Cpt2Collection\Admin\CommerceResetPage(static function()use(&$reset_calls){++$reset_calls;return null;});
	$page->register(); $reset_page->register(); $settings->register(); ksort($GLOBALS['hooks']['admin_menu']);
	foreach ($GLOBALS['hooks']['admin_menu'] as $callbacks) { foreach ($callbacks as $cb) {$cb();} }
	check(array_keys($GLOBALS['menu']) === [OrdersPage::MENU_SLUG], 'one dedicated top-level menu');
	check($GLOBALS['menu'][OrdersPage::MENU_SLUG]['callback'] === [$page,'render'], 'top-level destination is Orders');
	check(array_column($GLOBALS['submenu'][OrdersPage::MENU_SLUG], 'menu') === ['Orders','Full Commerce Reset','Settings'], 'exact visible submenu structure without parent duplicate');
	check($GLOBALS['submenu'][OrdersPage::MENU_SLUG]['cpt2collection']['callback'] === [$settings,'render'], 'existing settings renderer and slug relocated');
	check($GLOBALS['menu'][OrdersPage::MENU_SLUG]['cap'] === 'manage_options' && array_column($GLOBALS['submenu'][OrdersPage::MENU_SLUG],'cap') === ['manage_options','manage_options','manage_options'], 'all menu capabilities preserved');
	$GLOBALS['allowed']=false;ob_start();$reset_page->render();check(ob_get_clean()===''&&$reset_calls===0,'unauthorized reset render invokes no context reader');$GLOBALS['allowed']=true;
	$_GET=[];ob_start();$reset_page->render();$reset_html=ob_get_clean();
	check(str_contains($reset_html,'ready commerce context could not be verified')&&str_contains($reset_html,'id="c2c-reset-start" disabled'),'unavailable context never fabricates ready/start authority');
	check(!str_contains($reset_html,'<script')&&str_contains($reset_html,'Operational commerce records will be permanently removed')&&str_contains($reset_html,'physical stock are preserved'),'reset scope is explicit and rendering contains no executable mutation');
	$unavailable_page=new \RodolfoRizzo\Cpt2Collection\Admin\CommerceResetPage(static function():never{throw new RuntimeException('PRIVATE_SQL');});
	ob_start();$unavailable_page->render();$unavailable_html=ob_get_clean();check(!str_contains($unavailable_html,'PRIVATE_SQL')&&str_contains($unavailable_html,'could not be verified'),'context inspection exceptions fail closed without leaking SQL');
	$ready_page=new \RodolfoRizzo\Cpt2Collection\Admin\CommerceResetPage(static fn()=>new \RodolfoRizzo\Cpt2Collection\CommerceContext(str_repeat('a',32),2,str_repeat('b',32)));
	ob_start();$ready_page->render();$reset_html=ob_get_clean();
	check(str_contains($reset_html,'id="c2c-reset-start-form" hidden'),'ready context leaves start under JavaScript control');
	preg_match('/data-config="([^"]+)"/',$reset_html,$reset_match);$reset_config=json_decode(html_entity_decode($reset_match[1],ENT_QUOTES),true);
	check($reset_config['source']===str_repeat('b',32)&&str_contains($reset_config['status_url'],'__operation__')&&str_contains($reset_config['start_url'],'?rest_route=/cpt2collection/v1/commerce/resets'),'all reset URLs use native rest_url including query routing');
	check(str_contains($reset_html,'aria-live="polite"')&&str_contains($reset_html,'for="c2c-reset-recovery"')&&str_contains($reset_html,'readonly'),'reset controls have labels, result announcements and copyable recovery field');
	$_GET=['operation'=>['x']];ob_start();$ready_page->render();$reset_html=ob_get_clean();check(!str_contains($reset_html,'value="Array"'),'array operation selectors cannot become request identities');
	$_GET=['page'=>'other'];$GLOBALS['enqueued']=[];$reset_page->enqueue();check($GLOBALS['enqueued']===[],'reset asset absent on unrelated page');
	$_GET=['page'=>\RodolfoRizzo\Cpt2Collection\Admin\CommerceResetPage::MENU_SLUG];$GLOBALS['allowed']=false;$reset_page->enqueue();check($GLOBALS['enqueued']===[],'reset asset requires capability');
	$GLOBALS['allowed']=true;$reset_page->enqueue();check(count($GLOBALS['enqueued'])===1&&$GLOBALS['enqueued'][0][0]==='cpt2collection-commerce-reset','reset asset is scoped to dedicated page');$_GET=[];
	$settings->register_settings();
	check(count($GLOBALS['registered_settings']) === 10 && count(array_unique(array_column($GLOBALS['registered_settings'],0))) === 1 && reset($GLOBALS['registered_settings'])[0] === 'cpt2collection_settings', 'same ten settings in existing option group');
	check(array_keys($GLOBALS['registered_settings']) === ['cpt2collection_post_type','cpt2collection_currency','cpt2collection_minimum_order_quantity','cpt2collection_cart_page_id','cpt2collection_reservation_duration_minutes','cpt2collection_stripe_environment','cpt2collection_stripe_secret_key','cpt2collection_stripe_webhook_secret','cpt2collection_stripe_success_url','cpt2collection_stripe_cancel_url'], 'exact existing option names');
	check($settings->sanitize_currency('usd') === 'USD' && $settings->sanitize_currency('invalid') === 'EUR', 'settings currency validation preserved');
	ob_start(); $settings->render(); $html=ob_get_clean();
	check(str_contains($html,'action="options.php"') && str_contains($html,'cpt2collection_settings') && $GLOBALS['sections_page'] === 'cpt2collection', 'existing settings save form and sections retained');
	$pagenow='options-general.php'; $_GET=['page'=>'cpt2collection'];
	try {$settings->redirect_legacy_url(); throw new RuntimeException('Expected redirect');} catch(Redirected $e) {check($e->getMessage() === admin_url('admin.php?page=cpt2collection'),'old settings bookmark redirects safely');}
	$GLOBALS['allowed']=false; $before=count($reader->reads);
	check(render($page) === '' && count($reader->reads)===$before, 'unauthorized render performs no read');
	ob_start(); $settings->render(); check(ob_get_clean()==='', 'settings render remains capability guarded');
	$settings->redirect_legacy_url(); $GLOBALS['allowed']=true; $_GET=[];
	$source=file_get_contents(dirname(__DIR__).'/includes/class-database.php');
	preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
		foreach($matches[1] as $definition) {
			$definition=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$tables->{$m[1]}(),$definition);
			preg_match('/\A([^\s]+)/',$definition,$name); sql($writer,'CREATE TABLE '.$definition.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); $created[]=$name[1];
		}
		seed_commerce_foundation($writer); $created=array_merge($created,migrate_commerce_phase_two_fixture($writer));
		$empty=render($page); check(str_contains($empty,'No orders found.'),'empty history is a normal empty state');
	for($id=1;$id<=45;++$id) { $date=$id<=25?'2026-01-01 00:00:00':'2026-02-01 00:00:00'; sql($writer,"INSERT INTO {$tables->orders()} (id,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at,cart_reconciliation_status,cart_reconciliation_code) VALUES ($id,'paid','USD',1234,1234,'$date','$date','$date','completed','cart_reconciled')"); }
	$first=$repo->list_page(1);
	check(array_keys(get_object_vars($first['orders'][0])) === ['id','status','currency','total_minor','created_at','paid_at','cart_reconciliation_status','cart_reconciliation_code','sessions','payment_attention'], 'explicit minimal repository projection');
	$second=$repo->list_page(2); $last=$repo->list_page(PHP_INT_MAX);
	check(array_map(fn($o)=>(int)$o->id,$first['orders'])===range(45,26),'newest created then descending ID tie-breaker');
	check(array_map(fn($o)=>(int)$o->id,$second['orders'])===range(25,6),'second page has next twenty orders');
	check($last['page']===3 && $last['pages']===3 && count($last['orders'])===5 && $last['total']===45,'oversized valid page clamps to last page');
	foreach([null,[],0,-1,'oops','1 OR 1=1',str_repeat('9',100)] as $input) {check($repo->list_page($input)['page']===1,'invalid page fails safely to page one: '.json_encode($input));}
	$now='2026-02-01 00:00:00';
	foreach (['active','failed'] as $i=>$status) {sql($writer,"INSERT INTO {$tables->payment_sessions()} (reference,order_id,provider,creation_key_hash,status,currency,amount_minor,created_at,updated_at) VALUES (LPAD('$i',32,'a'),45,'stripe_checkout',LPAD('$i',64,'b'),'$status','USD',1234,'$now','$now')");}
	$html=render($page);
	check(str_contains($html,'$12.34 USD') && !str_contains($html,'€'),'historical total and currency independent of shop EUR');
	check(str_contains($html,'Payment confirmed in order records; Payment attempts: Active × 1, Failed × 1'),'all stored attempts summarized without assuming latest outcome');
	check(str_contains($html, 'admin.php?page=cpt2collection-orders&amp;order_id=45">C2C-45</a>'), 'display reference links to the real Orders detail route');
	check(str_contains($html,'paged=2') && !str_contains($html,'paged=0'),'bounded forward pagination');
	foreach(['pending'=>'Awaiting confirmation','awaiting_payment'=>'Awaiting confirmation','cancelled'=>'No payment confirmation recorded','failed'=>'No payment confirmation recorded','expired'=>'No payment confirmation recorded','paid'=>'Payment confirmed in order records'] as $status=>$label) {sql($writer,"UPDATE {$tables->orders()} SET status='$status' WHERE id=45"); check(str_contains(render($page),$label),'local payment presentation: '.$status);}
	sql($writer,"UPDATE {$tables->payment_sessions()} SET reconciliation_code='PRIVATE_DIAGNOSTIC' WHERE order_id=45");
	check(str_contains(render($page),'Payment confirmed in order records — Payment review required'),'paid remains confirmed with payment review flag');
	sql($writer,"UPDATE {$tables->orders()} SET status='awaiting_payment' WHERE id=45");
	check(str_contains(render($page),'Requires review; Payment attempts:'),'unpaid payment attention is not confirmation');
	sql($writer,"UPDATE {$tables->orders()} SET status='paid' WHERE id=45");
	foreach([['completed','cart_reconciled','Completed'],['pending',null,'Cart update not yet confirmed'],[null,null,'Cart update not yet confirmed'],['attention','PRIVATE_DIAGNOSTIC','Needs attention'],['bogus','PRIVATE_DIAGNOSTIC','Unknown — review required']] as [$status,$code,$label]) {
		sql($writer,"UPDATE {$tables->orders()} SET cart_reconciliation_status=".($status===null?'NULL':$writer->prepare('%s',$status)).", cart_reconciliation_code=".($code===null?'NULL':$writer->prepare('%s',$code)).' WHERE id=45'); check(str_contains(render($page),$label),'cart state presentation: '.($status??'NULL').' / '.($code??'NULL'));
	}
	foreach([1,2,3] as $failure) {$reader->reads=[]; $reader->fail_at=$failure; $html=render($page); check(str_contains($html,'temporarily unavailable') && !str_contains($html,'No orders found') && !str_contains($html,'PRIVATE SQL'),'read failure '.$failure.' is generic and distinct from empty'); $reader->fail_at=0;}
	check(!$reader->suppressed, 'database error-display setting restored after failures');
	$reader->hostile=true; $html=render($page); $reader->hostile=false;
	check(str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt; &amp; &quot;date&quot;') && !str_contains($html, '<script>'), 'stored display text is HTML escaped');
	// Unknown statuses are also mapped rather than echoed.
	$method=new ReflectionMethod($page,'payment_label'); $hostile=(object)['status'=>'<script>attack</script>','payment_attention'=>false,'sessions'=>['<script>attack</script>'=>1]];
	check(!str_contains($method->invoke($page,$hostile),'<script>'),'unknown stored status is not echoed');
	sql($writer,"UPDATE {$tables->payment_sessions()} SET reconciliation_code=NULL WHERE order_id=45");
	$session_id=(int)$writer->get_var("SELECT MIN(id) FROM {$tables->payment_sessions()} WHERE order_id=45");
	sql($writer,"INSERT INTO {$tables->payment_events()} (epoch_id,ownership_state,provider,provider_event_id,event_type,livemode,payment_session_id,processing_status,received_at,payload_sha256) VALUES (1,'bound','stripe_checkout','evt_admin_test','checkout.session.completed',0,$session_id,'attention','$now',REPEAT('a',64))");
	check(str_contains(render($page),'Payment review required'),'event attention is summarized without exposing event evidence');
	$before=snapshot($writer,$created); $reader->reads=[];
	foreach([1,2,3,999] as $number) {$_GET=['paged'=>$number]; $html=render($page); check(!preg_match('/PRIVATE|creation_key|token_hash|redirect_url|provider_session_id|cart_id|payload/',$html),'no sensitive data in page '.$number);}
	check(snapshot($writer,$created)===$before,'repeated rendering and pagination leave all seven commerce tables unchanged');
		check(count($reader->reads)===28 && !preg_match('/SELECT\s+\*|FOR UPDATE|START TRANSACTION|\b(?:INSERT|UPDATE|DELETE)\b/i',implode("\n",$reader->reads)),'only explicit nonlocking SELECTs, including epoch validation around each populated page');
	check(!str_contains(implode("\n",$reader->reads),'order_items'),'no catalog/item hydration for list');

	// Discovery extends the existing fixture/coverage; production-schema tables remain disposable.
	foreach ([123=>'2026-01-31 23:59:59',124=>'2026-02-01 00:00:00'] as $id=>$date) {
		sql($writer,"INSERT INTO {$tables->orders()} (id,status,currency,created_at,updated_at) VALUES ($id,'paid','EUR','$date','$date')");
	}
	foreach (['C2C-123','123'] as $reference) {
		$reader->reads=[]; $found=$repo->list_page(1,['order_reference'=>$reference]);
		check($found['total']===1 && (int)$found['orders'][0]->id===123,'exact reference: '.$reference);
		$reference_queries=implode("\n",$reader->reads);
		check((bool)preg_match('/\\bid = 123\\b/',$reference_queries) && !str_contains($reference_queries,'LIKE'),'reference uses numeric primary-key equality');
	}
	$plan=$writer->get_results('EXPLAIN '.$reader->reads[1]);
	check(is_array($plan) && $plan[0]->key==='PRIMARY','representative exact-reference query uses PRIMARY');
	$invalid_references=['C2C-','C2C-12x','c2c-123','C2C-0123','0123','0','C2C-0','-123','C2C--123','+123','123.0','123 OR 1=1',' 123','123 ',str_repeat('9',30),[],null,false,123];
	foreach ($invalid_references as $reference) {
		$reader->reads=[]; $found=$repo->list_page(1,['order_reference'=>$reference]);
		check(isset($found['errors']['order_reference']) && $found['orders']===[] && $reader->reads===[],'invalid reference rejects without query: '.json_encode($reference));
	}
	check(!$repo->list_page(1,['order_reference'=>(string)PHP_INT_MAX])['errors'],'maximum bindable positive ID accepted without overflow');
	foreach (\RodolfoRizzo\Cpt2Collection\OrderStatuses::get_statuses() as $status=>$label) {
		sql($writer,$writer->prepare("UPDATE {$tables->orders()} SET status=%s WHERE id=123",$status));
		$found=$repo->list_page(1,['order_reference'=>'123','order_status'=>$status]);
		check($found['total']===1 && $found['orders'][0]->status===$status,'existing status filter: '.$status);
	}
	sql($writer,"UPDATE {$tables->orders()} SET status='paid' WHERE id=123");
	foreach (['completed','PAID',[],null] as $status) {
		$reader->reads=[]; $found=$repo->list_page(1,['order_status'=>$status]);
		check(isset($found['errors']['order_status']) && $reader->reads===[],'invalid status cannot broaden query: '.json_encode($status));
	}
	$found=$repo->list_page(1,['created_from'=>'2026-02-01']);
	check($found['total']===21 && (int)$found['orders'][0]->id===124,'created-from includes exact UTC midnight');
	$found=$repo->list_page(1,['created_to'=>'2026-01-31']);
	check($found['total']===26 && (int)$found['orders'][0]->id===123,'created-to includes final second and excludes next midnight');
	$reader->reads=[]; $found=$repo->list_page(1,['created_from'=>'2026-01-31','created_to'=>'2026-01-31']);
	check($found['total']===1 && (int)$found['orders'][0]->id===123,'same-day combined range includes entire selected day');
	$range_queries=array_values(array_filter($reader->reads,fn($query)=>str_contains($query,'created_at >=')));
	check($range_queries && str_contains($range_queries[0],"created_at >= '2026-01-31 00:00:00'") && str_contains($range_queries[0],"created_at < '2026-02-01 00:00:00'") && !str_contains($range_queries[0],'DATE('),'range uses indexable inclusive start and exclusive end');
	$plan=$writer->get_results('EXPLAIN '.$range_queries[1]);
	check(is_array($plan) && str_contains((string)$plan[0]->possible_keys,'created_at'),'created-date index is available to range query');
	foreach (['2026-02-30','2026-02-29','2026-13-01','2026-00-01','2026-1-01','01/01/2026','0000-01-01','0999-01-01','10000-01-01',"2026-01-01\0",[],null] as $date) {
		foreach (['created_from','created_to'] as $key) {
			$reader->reads=[]; $found=$repo->list_page(1,[$key=>$date]);
			check(isset($found['errors'][$key]) && $reader->reads===[],'invalid date rejected without query: '.$key.' '.json_encode($date));
		}
	}
	check(!$repo->list_page(1,['created_from'=>'2024-02-29','created_to'=>'2024-02-29'])['errors'],'valid leap day accepted');
	foreach ([['created_from'=>'2026-02-01','created_to'=>'2026-01-31'],['created_to'=>'9999-12-31']] as $filters) {
		$reader->reads=[]; check($repo->list_page(1,$filters)['errors']!==[] && $reader->reads===[],'reversed or overflowing date range rejected');
	}
	foreach (['failed','1',[],null] as $review) {
		$reader->reads=[]; $found=$repo->list_page(1,['payment_review'=>$review]);
		check(isset($found['errors']['payment_review']) && $reader->reads===[],'invalid payment review rejected: '.json_encode($review));
	}
	sql($writer,"INSERT INTO {$tables->payment_sessions()} (reference,order_id,provider,creation_key_hash,status,currency,amount_minor,created_at,updated_at) VALUES (REPEAT('c',32),123,'stripe_checkout',REPEAT('d',64),'completed','EUR',0,'$now','$now')");
	$review_session=(int)$writer->insert_id;
	foreach (['reconciliation_required_at'=>"'$now'",'reconciliation_code'=>"'PRIVATE_REVIEW'",'reconciliation_event_id'=>'999'] as $column=>$value) {
		sql($writer,"UPDATE {$tables->payment_sessions()} SET $column=$value WHERE id=$review_session");
		$found=$repo->list_page(1,['order_reference'=>'123','payment_review'=>'required']);
		check($found['total']===1 && $found['orders'][0]->payment_attention && $found['orders'][0]->status==='paid','paid order matches session review marker: '.$column);
		sql($writer,"UPDATE {$tables->payment_sessions()} SET $column=NULL WHERE id=$review_session");
	}
	check($repo->list_page(1,['order_reference'=>'123','payment_review'=>'required'])['total']===0,'no markers means no recorded review requirement');
	foreach (['attention','attention','processed'] as $i=>$status) {
		sql($writer,"INSERT INTO {$tables->payment_events()} (epoch_id,ownership_state,provider,provider_event_id,event_type,livemode,payment_session_id,processing_status,received_at,payload_sha256) VALUES (1,'bound','stripe_checkout','evt_discovery_$i','checkout.session.completed',0,$review_session,'$status','$now',REPEAT('a',64))");
	}
	$combined=['order_reference'=>'C2C-123','order_status'=>'paid','created_from'=>'2026-01-31','created_to'=>'2026-01-31','payment_review'=>'required'];
	$found=$repo->list_page(1,$combined);
	check($found['total']===1 && count($found['orders'])===1 && $found['orders'][0]->payment_attention,'combined filters include paid order with multiple attention events once');
	check($repo->list_page(1,array_replace($combined,['order_status'=>'pending']))['total']===0,'combined predicates are AND, not OR');
	// Put review matches across multiple pages, including orders with no recent creation date.
	for ($id=1;$id<=25;++$id) {
		sql($writer,"INSERT INTO {$tables->payment_sessions()} (reference,order_id,provider,creation_key_hash,status,currency,amount_minor,created_at,updated_at,reconciliation_required_at) VALUES (LPAD('$id',32,'e'),$id,'stripe_checkout',LPAD('$id',64,'f'),'active','USD',1234,'$now','$now','$now')");
	}
	sql($writer,"UPDATE {$tables->payment_sessions()} SET reconciliation_required_at='$now' WHERE order_id=45");
	$before=snapshot($writer,$created); $reader->reads=[];
	$first=$repo->list_page(1,['payment_review'=>'required']); $second=$repo->list_page(2,['payment_review'=>'required']);
	$ids=array_map(fn($o)=>(int)$o->id,array_merge($first['orders'],$second['orders']));
	check($first['total']===27 && $second['total']===27 && count($first['orders'])===20 && count($second['orders'])===7 && count(array_unique($ids))===27,'review filters before pagination; count agrees without session/event duplicates');
	check($ids===array_merge([45,123],range(25,1)),'review pages preserve created-descending and ID-descending order');
	$review_queries=array_values(array_filter($reader->reads,fn($query)=>str_contains($query,'EXISTS')&&str_contains($query,'payment_sessions')));
	$count_where=substr($review_queries[0],strpos($review_queries[0],' WHERE '));
	$page_where=substr($review_queries[1],strpos($review_queries[1],' WHERE '));
	$page_where=substr($page_where,0,strpos($page_where,' ORDER BY created_at'));
	check($count_where===$page_where && str_contains($count_where,'EXISTS') && !str_contains($count_where,' JOIN '),'count and page share identical correlated review predicate');
	$summary_queries=array_values(array_filter($reader->reads,fn($query)=>str_contains($query,'s.order_id IN')));
	check(str_contains($summary_queries[0],'s.order_id IN ('.implode(',',array_slice($ids,0,20)).')') && str_contains($summary_queries[1],'s.order_id IN ('.implode(',',array_slice($ids,20)).')'),'payment summaries remain bounded to each returned page');
	$plan=$writer->get_results('EXPLAIN '.$review_queries[1]);
	check(is_array($plan) && count($plan)>0,'representative correlated review query has a valid MariaDB execution plan');
	$reader->reads=[]; $empty=$repo->list_page(1,['order_reference'=>'999']);
	check($empty!==false && $empty['total']===0 && $empty['orders']===[] && $empty['errors']===[] && count($reader->reads)===3,'valid empty filtered result performs epoch validation and count only');
	foreach ([1,2,3] as $failure) {
		$reader->reads=[]; $reader->fail_at=$failure;
		check($repo->list_page(1,$combined)===false,'filtered read failure remains false: '.$failure);
		$reader->reads=[]; $_GET=$combined; $html=render($page);
		check(str_contains($html,'temporarily unavailable') && !str_contains($html,'No orders found.') && !str_contains($html,'PRIVATE SQL'),'filtered SQL error distinct from empty UI: '.$failure);
		$reader->fail_at=0;
	}
	$navigation=['order_status'=>'paid','created_from'=>'2026-01-01','created_to'=>'2026-02-01','payment_review'=>'required'];
	$_GET=$navigation+['paged'=>'2']; $html=render($page);
	preg_match_all('/href="([^"]+)"/',$html,$links); $previous=null; $detail=null;
	foreach ($links[1] as $link) {
		parse_str(parse_url(html_entity_decode($link),PHP_URL_QUERY),$args);
		if (($args['paged']??null)==='1') {$previous=$args;}
		if (isset($args['order_id'])) {$detail=$args;}
	}
	check($previous===['page'=>OrdersPage::MENU_SLUG,'paged'=>'1']+$navigation,'pagination retains every active filter');
	check($detail!==null && array_diff_assoc($navigation,$detail)===[] && $detail['paged']==='2','detail link retains validated list filters and page');
	preg_match('/<form\b.*?<\/form>/s',$html,$form);
	check(str_contains($form[0],'method="get" action="https://shop.example/wp-admin/admin.php"') && !str_contains($form[0],'name="paged"') && !str_contains($form[0],'order_id'),'filter form resets pagination and does not submit detail routing');
	$_GET=$navigation; check(str_contains(render($page),'Page 1 of 2'),'submitting filters without paged starts page one');
	check(str_contains($html,'admin.php?page=cpt2collection-orders">Clear filters</a>'),'clear filters links to clean Orders URL');
	$_GET=['order_reference'=>'\"><script>alert(1)</script>']; $reader->reads=[]; $html=render($page);
	check(str_contains($html,'&quot;&gt;&lt;script&gt;') && !str_contains($html,'<script>') && !str_contains($html,'No orders found.') && $reader->reads===[],'invalid reflected reference is escaped and never queries');
	$_GET=['order_status'=>'bad']; $reader->reads=[]; $html=render($page);
	check(str_contains($html,'Choose a valid order status.') && !str_contains($html,'C2C-45</a>') && $reader->reads===[],'malformed UI filter does not fall back to unfiltered orders');
	$GLOBALS['allowed']=false; $_GET=$combined; $reader->reads=[];
	check(render($page)==='' && $reader->reads===[],'capability denial also guards discovery controls and queries'); $GLOBALS['allowed']=true;
	check(snapshot($writer,$created)===$before,'filtered discovery and failures leave every commerce table unchanged');
	check($reader->unsafe_attempts===0 && $GLOBALS['provider_calls']===0,'no mutation, transaction, locking or provider calls attempted');
	echo "All $checks admin order list checks passed.\n";
} finally {foreach(array_reverse($created) as $table) {$writer->query("DROP TABLE IF EXISTS $table");} $reader->close(); $writer->close();}
