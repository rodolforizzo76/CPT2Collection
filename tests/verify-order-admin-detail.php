<?php
/** Phase 4: real SQL detail projection, safe rendering and read-only admin routing. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { echo "SKIP: mysqli and C2C_TEST_DB_NAME required.\n"; exit; }
define( 'ABSPATH', __DIR__ );
$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = getenv( 'C2C_TEST_DB_NAME' );
mysqli_report( MYSQLI_REPORT_OFF );
require __DIR__ . '/support/mariadb-wpdb.php';
function __( string $text, string $domain ): string { return $GLOBALS['translated_labels'][ $text ] ?? $text; }
function _x( string $text, string $context, string $domain ): string { return $text; }
function _n( string $one, string $many, int $count, string $domain ): string { return 1 === $count ? $one : $many; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text, string $domain ): string { return esc_html( $text ); }
function esc_attr( string $text ): string { return esc_html( $text ); }
function wp_unslash( mixed $value ): mixed { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function esc_url( string $text ): string { return esc_html( $text ); }
function current_user_can( string $cap ): bool { return $GLOBALS['allowed'] && 'manage_options' === $cap; }
function number_format_i18n( float $number, int $decimals ): string { return number_format( $number, $decimals ); }
function admin_url( string $path ): string { return 'https://shop.example/wp-admin/' . $path; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function get_current_user_id(): int { return 7; }
function rest_url( string $path ): string { return 'https://shop.example/?rest_route=/' . $path; }
function wp_create_nonce( string $action ): string { return 'native-nonce-fixture'; }
function wp_json_encode( mixed $value ): string { return json_encode($value); }
function get_option( string $key, mixed $default = false ): mixed { return match ( $key ) { 'cpt2collection_currency' => 'EUR', 'cpt2collection_schema_version' => $GLOBALS['detail_schema'], default => $default }; }
// Neither current catalog state nor provider transport belongs in this view.
function get_the_title( mixed ...$args ): never { ++$GLOBALS['catalog_calls']; throw new RuntimeException('Current catalog read'); }
function get_post_meta( mixed ...$args ): never { ++$GLOBALS['catalog_calls']; throw new RuntimeException('Current catalog read'); }
function wp_remote_request( mixed ...$args ): never { ++$GLOBALS['provider_calls']; throw new RuntimeException('Provider call'); }
function wp_remote_post( mixed ...$args ): never { ++$GLOBALS['provider_calls']; throw new RuntimeException('Provider call'); }
foreach ( ['table-names','money','order-statuses','payment-session-statuses','payment-event-statuses','order-admin-read-repository'] as $file ) { require_once dirname(__DIR__) . '/includes/class-' . $file . '.php'; }
require_once dirname(__DIR__) . '/includes/class-database.php';
require dirname(__DIR__) . '/includes/admin/class-order-detail-page.php';
require dirname(__DIR__) . '/includes/admin/class-orders-page.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,OrderAdminReadRepository,OrderStatuses,PaymentSessionStatuses};
use RodolfoRizzo\Cpt2Collection\Admin\{OrderDetailPage,OrdersPage};
class DetailReadDb extends wpdb {
	public array $reads = [];
	public int $fail_at = 0;
	public bool $throw_failure = false;
	public bool $suppressed = false;
	public int $unsafe_attempts = 0;
	public ?Closure $after_sessions = null;
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function query( string $sql ): int|false { ++$this->unsafe_attempts; throw new RuntimeException('Non-read SQL attempted'); }
	public function get_results( string $sql ): ?array {
		$this->reads[] = $sql;
		if (!preg_match('/\ASELECT\s/i', $sql) || preg_match('/SELECT\s+\*|FOR UPDATE|LOCK IN SHARE|START TRANSACTION/i', $sql)) { ++$this->unsafe_attempts; throw new RuntimeException('Unsafe read'); }
		if (!$this->suppressed) { throw new RuntimeException('Error display not suppressed'); }
		if (count($this->reads) === $this->fail_at) {
			if ($this->throw_failure) { throw new RuntimeException('PRIVATE_SQL_CREDENTIAL'); }
			$this->last_error = 'PRIVATE_SQL_CREDENTIAL'; return null;
		}
		$rows = parent::get_results($sql);
		if ( null !== $this->after_sessions && str_contains($sql, 'SELECT s.status') ) { $callback=$this->after_sessions; $this->after_sessions=null; $callback(); }
		return $rows;
	}
}
$checks=0;
function check( bool $ok, string $label ): void { global $checks; if (!$ok) { throw new RuntimeException($label); } ++$checks; echo "PASS: $label\n"; }
function sql( wpdb $db, string $query ): void { if (false === $db->query($query)) { throw new RuntimeException($db->last_error); } }
function render( OrdersPage $page, mixed $id ): string { $_GET=['order_id'=>$id]; ob_start(); $page->render(); return ob_get_clean(); }
function snapshot( wpdb $db, array $tables ): string { $rows=[]; foreach ($tables as $table) { $rows[$table]=$db->get_results("SELECT * FROM $table ORDER BY id"); } return serialize($rows); }
function contains_private( string $html ): bool { return (bool) preg_match('/PRIVATE_|cs_test_secret|evt_secret|pi_secret|checkout\.stripe|creation_key|checkout_attempt|token_hash|source_cart_item|normalized_payload|payload_sha256|signature/', $html); }
$prefix='c2c_detail_'.bin2hex(random_bytes(4)).'_';
$writer=new wpdb($prefix); $reader=new DetailReadDb($prefix); $tables=new TableNames($writer);
$repo=new OrderAdminReadRepository($reader,$tables); $page=new OrdersPage($repo); $detail=new OrderDetailPage($repo);
$created=[]; $GLOBALS['allowed']=true; $GLOBALS['detail_schema']='0.20.0'; $GLOBALS['catalog_calls']=0; $GLOBALS['provider_calls']=0;
try {
	foreach ([null,'',[],['1'],false,true,1.0,0,-1,'0','01','+1','-1',' 1','1 ','1\n','1.0','1e0','1 OR 1=1','１',str_repeat('9',100),(string)PHP_INT_MAX.'0'] as $bad) {
		$before=count($reader->reads); $html=render($page,$bad);
		check(str_contains($html,'Invalid order reference.') && count($reader->reads)===$before && !str_contains($html,'Order not found.'), 'malformed selector performs no read: '.json_encode($bad));
	}
	check(OrderDetailPage::parse_order_id('1')===1 && OrderDetailPage::parse_order_id(PHP_INT_MAX)===PHP_INT_MAX, 'canonical positive decimal selector boundaries');
	try {$repo->find_detail(0); throw new RuntimeException('Expected invalid ID rejection');} catch (InvalidArgumentException $e) {check(!$reader->reads,'repository rejects nonpositive IDs before SQL');}
	$GLOBALS['allowed']=false;
	check(render($page,1)==='' && !$reader->reads,'unauthorized Orders routing performs no read');
	ob_start(); $detail->render(1); $html=ob_get_clean();
	check($html==='' && !$reader->reads,'direct detail rendering independently requires manage_options');
	$GLOBALS['allowed']=true;
	$source=file_get_contents(dirname(__DIR__).'/includes/class-database.php');
	preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
	check(count($matches[1])===9,'use all nine exact production commerce table definitions');
		foreach($matches[1] as $definition) {
			$definition=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$tables->{$m[1]}(),$definition);
			preg_match('/\A([^\s]+)/',$definition,$name); sql($writer,'CREATE TABLE '.$definition.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); $created[]=$name[1];
		}
		seed_commerce_foundation($writer); $created=array_merge($created,migrate_commerce_phase_two_fixture($writer));
	$now='2026-09-24 09:10:11'; $paid='2026-09-24 09:20:21'; $review='2026-09-24 09:30:31';
	sql($writer,"INSERT INTO {$tables->carts()} (id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES (77,REPEAT('a',64),'$now','$now','$now','2030-01-01 00:00:00')");
	sql($writer,"INSERT INTO {$tables->cart_items()} (id,cart_id,post_id,quantity,content_version,created_at,updated_at) VALUES (88,77,999,7,9,'$now','$now')");
	foreach ([1,2] as $id) {sql($writer,"INSERT INTO {$tables->orders()} (id,cart_id,checkout_attempt_hash,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at,cart_reconciliation_status,cart_reconciliation_code,cart_reconciliation_updated_at) VALUES ($id,77,LPAD('$id',64,'b'),'paid','USD',900,750,'$now','$paid','$paid','completed','cart_reconciled','$review')");}
	$item_name='<script>alert("snapshot")</script> & "Old title"';
	sql($writer,$writer->prepare("INSERT INTO {$tables->order_items()} (id,order_id,post_id,source_cart_item_id,source_cart_item_version,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES (11,1,999,88,9,%s,300,3,900,750,'USD',%s)",$item_name,$now));
	sql($writer,"INSERT INTO {$tables->order_items()} (id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES (22,2,999,'Other order item',99999,1,99999,99999,'EUR','$now')");
	sql($writer,"INSERT INTO {$tables->reservations()} (order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES (1,11,999,3,'converted','$now','$paid','$review')");
	foreach ([1=>'failed',2=>'completed',3=>'active'] as $id=>$status) {
		$order_id=3===$id?2:1;
		sql($writer,"INSERT INTO {$tables->payment_sessions()} (id,reference,order_id,provider,provider_session_id,provider_payment_id,creation_key_hash,status,currency,amount_minor,redirect_url,created_at,updated_at,expires_at) VALUES ($id,LPAD('$id',32,'c'),$order_id,'stripe_checkout','cs_test_secret_$id','pi_secret_$id',LPAD('$id',64,'d'),'$status','USD',750,'https://checkout.stripe.com/PRIVATE_ACCESS','$now','$paid','$review')");
	}
	sql($writer,"UPDATE {$tables->payment_sessions()} SET failed_at='$paid' WHERE id=1");
	sql($writer,"UPDATE {$tables->payment_sessions()} SET completed_at='$paid',payment_confirmed_at='$paid',confirmed_currency='USD',confirmed_amount_minor=750 WHERE id=2");
		sql($writer,"INSERT INTO {$tables->payment_events()} (epoch_id,ownership_state,provider,provider_event_id,event_type,livemode,payment_session_id,processing_status,result_code,received_at,payload_sha256,normalized_payload,error_detail) VALUES (1,'bound','stripe_checkout','evt_secret','checkout.session.completed',0,3,'attention','PRIVATE_EVENT_CODE','$now',REPEAT('e',64),'PRIVATE_PAYLOAD','PRIVATE_ERROR')");
		$absent=$repo->find_detail(999); check($absent===null,'repository absence is null, not a read failure');
	check(str_contains(render($page,999),'Order not found.'),'nonexistent order has distinct not-found page');
	$reader->reads=[]; $result=$repo->find_detail(1);
	check(is_array($result) && (int)$result['order']->id===1 && count($result['items'])===1 && count($result['sessions'])===2,'exact order selected with only associated items and sessions');
	check(array_keys(get_object_vars($result['order']))===['id','status','currency','subtotal_minor','total_minor','created_at','paid_at','customer_name','customer_email','cart_reconciliation_status','cart_reconciliation_code','cart_reconciliation_updated_at'],'explicit order projection allowlist');
	check(array_keys(get_object_vars($result['items'][0]))===['item_name','quantity','unit_price_minor','line_total_minor','currency'],'immutable item projection excludes product/source row identities');
	check(array_keys(get_object_vars($result['sessions'][0]))===['status','currency','amount_minor','created_at','updated_at','expires_at','expired_at','completed_at','cancelled_at','failed_at','payment_confirmed_at','reconciliation_required_at','requires_review'],'session projection excludes all credentials and event bodies');
	check(array_column($result['sessions'],'status')===['failed','completed'],'session attempts use deterministic created/id ordering');
	check(!$result['sessions'][0]->requires_review && !$result['sessions'][1]->requires_review,'foreign event attention does not bleed into this order');
	check(str_contains(render($page,1),'Order summary') && substr_count(render($page,1),'Not available')===2, 'existing Order remains readable before the customer migration');
	check(\RodolfoRizzo\Cpt2Collection\OrderCustomerMigration::run($writer), 'customer migration adds detail projection columns');
	$GLOBALS['detail_schema']='0.21.0';
	$name='Élodie <b>李𠮷</b>';
	sql($writer,$writer->prepare("UPDATE {$tables->orders()} SET customer_name=%s, customer_email=%s WHERE id=1",$name,'detail@example.test'));
	$customer=render($page,1);
	check(str_contains($customer,esc_html($name)) && str_contains($customer,'>detail@example.test</td>') && !str_contains($customer,'mailto:'), 'protected detail escapes Unicode name and renders email as plain text');
	check(!str_contains(render($page,2),'detail@example.test') && substr_count(render($page,2),'Not available')===2, 'customer values belong only to the selected Order');
	$html=render($page,'1');
	check(str_contains($html,'<h1>Order C2C-1</h1>') && str_contains($html,'<td>Paid</td>'),'order reference and known status render');
	check(str_contains($html,$now) && str_contains($html,$paid) && str_contains($html,$review) && str_contains($html,'Dates and times are in UTC'),'persisted timestamps identify UTC');
	check(str_contains($html,'<th scope="row">Subtotal</th><td>US$9.00</td>') && str_contains($html,'<th scope="row">Total</th><td>US$7.50</td>') && !str_contains($html,'€'),'historical subtotal and discounted total use USD despite configured EUR');
	check(str_contains($html, '<td>USD</td>') && substr_count($html, 'US$7.50') === 5 && !str_contains($html, 'US$7.50 USD'), 'currency code remains separate and totals, attempts and deletion summary use one marker');
	check(str_contains($html,esc_html($item_name)) && !str_contains($html,'<script>'),'immutable item name is escaped as text');
	check(str_contains($html,'<td>3</td><td>US$3.00</td><td>US$7.50</td>'),'historical quantity/unit price/line total are not recomputed');
	check(!contains_private($html) && !str_contains($html,'Other order item'),'sensitive fixture values and foreign order information absent');
	check((bool) preg_match('~<details class="c2c-order-card c2c-order-technical"><summary><h2>Technical Payment Details</h2></summary>.*id="c2c-order-payment".*id="c2c-order-attempts".*id="c2c-order-cart".*</details>~s', $html), 'one initially collapsed disclosure contains all technical sections in order');
	check(str_contains($html, '<th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Total</th>'), 'compact historical item headings');
	$one_attempt = render($page, 2);
	check(str_contains($one_attempt, 'Payment attempt 1') && !str_contains($one_attempt, 'Payment attempt 2'), 'single payment attempt is independently identified');
	check(str_contains($html,'Payment attempt 1') && str_contains($html,'Payment attempt 2') && !str_contains($html,'Payment attempt 3'),'all and only this order attempts shown');
	check(str_contains($html,'Locally failed (UTC)') && str_contains($html,'Locally completed (UTC)') && str_contains($html,'Payment evidence recorded (UTC)'),'meaningful session lifecycle timestamps shown');
	check(str_contains($html,'admin.php?page=cpt2collection-orders">Back to Orders</a>'),'detail has a real Orders back link');
	$_GET=['order_id'=>'1','order_status'=>'paid','created_from'=>'2026-01-01','created_to'=>'2026-12-31','payment_review'=>'required','paged'=>'2','redirect'=>'https://evil.example'];
	ob_start(); $page->render(); $context_html=ob_get_clean();
	check(str_contains($context_html,'admin.php?page=cpt2collection-orders&amp;order_status=paid&amp;created_from=2026-01-01&amp;created_to=2026-12-31&amp;payment_review=required&amp;paged=2">Back to Orders</a>') && !str_contains($context_html,'evil.example'),'detail back link preserves only validated list context');
	$_GET=['order_id'=>'1','order_reference'=>'<script>bad</script>','paged'=>'2'];
	ob_start(); $page->render(); $context_html=ob_get_clean();
	check(str_contains($context_html,'admin.php?page=cpt2collection-orders">Back to Orders</a>') && !str_contains($context_html,'<script>'),'invalid optional context is omitted without affecting exact detail lookup');
	$GLOBALS['translated_labels'] = array( 'Order reference' => '<Ordine>', 'Order status' => '<Ordine>', 'Paid' => 'Pagato' );
	$localized_html = render( $page, 1 );
	unset( $GLOBALS['translated_labels'] );
	check( str_contains( $localized_html, '<th scope="row">&lt;Ordine&gt;</th><td>C2C-1</td>' )
		&& str_contains( $localized_html, '<th scope="row">&lt;Ordine&gt;</th><td>Pagato</td>' ),
		'two fields with the same translated label are escaped and both remain visible' );
	$_GET=[]; ob_start(); $page->render(); $list_html=ob_get_clean();
	check((bool)preg_match('/href="([^"]*order_id=1)">C2C-1<\/a>/', $list_html, $link),'list reference links to existing detail route');
	parse_str(parse_url(html_entity_decode($link[1]),PHP_URL_QUERY),$query);
	check($query===['page'=>OrdersPage::MENU_SLUG,'order_id'=>'1'] && str_contains(render($page,$query['order_id']),'<h1>Order C2C-1</h1>'),'following list URL selects exact order without extra query credentials');
	foreach (OrderStatuses::get_statuses() as $status=>$label) {
		sql($writer,$writer->prepare("UPDATE {$tables->orders()} SET status=%s WHERE id=1",$status)); $html=render($page,1);
		check(str_contains($html,'<th scope="row">Order status</th><td>'.$label.'</td>'),'order status mapping: '.$status);
		$expected=$status==='paid'?'Payment confirmed in order records':(in_array($status,['pending','awaiting_payment'],true)?'Awaiting confirmation':'No payment confirmation recorded');
		check(str_contains($html,'<th scope="row">Order payment confirmation</th><td>'.$expected.'</td>'),'local confirmation mapping: '.$status);
	}
	foreach (PaymentSessionStatuses::get_statuses() as $status=>$label) {sql($writer,$writer->prepare("UPDATE {$tables->payment_sessions()} SET status=%s WHERE id=1",$status)); check(str_contains(render($page,1),'<th scope="row">Recorded session status</th><td>'.$label.'</td>'),'session status mapping: '.$status);}
	sql($writer,"UPDATE {$tables->orders()} SET status='<script>PRIVATE_STATUS</script>' WHERE id=1");
	sql($writer,"UPDATE {$tables->payment_sessions()} SET status='<img>PRIVATE_SESSION</img>' WHERE id=1");
	$html=render($page,1);
	check(str_contains($html,'<td>Unknown</td>') && str_contains($html,'Payment confirmation cannot be determined from the order records.') && !contains_private($html),'unknown statuses degrade neutrally without arbitrary stored strings');
	sql($writer,"UPDATE {$tables->payment_sessions()} SET status='completed',reconciliation_code='PRIVATE_REVIEW_CODE',reconciliation_required_at='$review' WHERE id=1");
	sql($writer,"UPDATE {$tables->orders()} SET status='awaiting_payment' WHERE id=1");
	$html=render($page,1);
	check(str_contains($html,'Needs review — no payment confirmation is recorded for this order.') && !str_contains($html,'<td>Payment confirmed in order records</td>'),'completed attempt and review flag cannot confirm unpaid order');
	check(str_contains($html,'Payment review flagged (UTC)') && !contains_private($html),'review timestamp shown without private diagnostic');
	sql($writer,"UPDATE {$tables->orders()} SET status='paid' WHERE id=1");
	check(str_contains(render($page,1),'<td>Payment confirmed in order records</td>') && str_contains(render($page,1),'<td>Requires review</td>'),'paid order stays confirmed with payment attention');
	sql($writer,"UPDATE {$tables->payment_sessions()} SET reconciliation_code=NULL,reconciliation_required_at=NULL WHERE id=1");
	sql($writer,"UPDATE {$tables->payment_events()} SET payment_session_id=1 WHERE provider_event_id='evt_secret'");
	check(str_contains(render($page,1),'<td>Requires review</td>'),'associated event attention contributes concise review flag');
	foreach ([['completed','cart_reconciled','The cart was updated to account for this purchase'],['pending',null,'The cart update is not yet confirmed.'],[null,null,'The cart update is not yet confirmed.'],['attention','PRIVATE_CART_DIAGNOSTIC','The cart update needs review.'],['completed','PRIVATE_UNKNOWN_RESULT','The cart update is marked complete'],['PRIVATE_STATUS','PRIVATE_CART_DIAGNOSTIC','The recorded cart update status is unclear']] as [$status,$code,$expected]) {
		sql($writer,"UPDATE {$tables->orders()} SET cart_reconciliation_status=".($status===null?'NULL':$writer->prepare('%s',$status)).",cart_reconciliation_code=".($code===null?'NULL':$writer->prepare('%s',$code)).' WHERE id=1');
		$html=render($page,1);
		check(str_contains($html,$expected) && str_contains($html,'<td>Payment confirmed in order records</td>') && !contains_private($html),'cart mapping independent of paid order: '.($status??'NULL').' / '.($code??'NULL'));
		check(str_contains(explode('<details', $html, 2)[0], 'Payment confirmed. Cart reconciliation needs attention.') === ('attention' === $status), 'only recorded attention for a paid Order appears outside the disclosure');
	}
	sql($writer,"UPDATE {$tables->orders()} SET status='pending',cart_reconciliation_status='attention' WHERE id=1");
	check(!str_contains(render($page,1),'Payment confirmed. Cart reconciliation needs attention.'),'unpaid Order cannot get a paid reconciliation warning');
	sql($writer,"UPDATE {$tables->orders()} SET status='paid' WHERE id=1");
	check(str_contains($html,'Completion does not mean the entire cart is empty.'),'cart completion never claims entire cart empty');
	foreach ([1,2,3] as $failure) {
		$reader->reads=[]; $reader->fail_at=$failure;
		check($repo->find_detail(1)===false,'repository read failure '.$failure.' is false, distinct from absent');
		foreach ([false,true] as $throw) {
			$reader->reads=[]; $reader->throw_failure=$throw; $html=render($page,1);
			check(str_contains($html,'temporarily unavailable') && !str_contains($html,'Order not found.') && !str_contains($html,'Order items') && !contains_private($html),'safe whole-page error for read '.$failure.($throw?' exception':' failure'));
			check(!$reader->suppressed,'error display restored after read failure '.$failure.($throw?' exception':' failure'));
		}
			$reader->fail_at=0; $reader->throw_failure=false;
	}
	$reader->suppressed=true; $repo->find_detail(1); check($reader->suppressed,'preexisting suppression restored on successful read'); $reader->suppressed=false;
	sql($writer,"UPDATE {$tables->orders()} SET currency='ZZZ',status='paid' WHERE id=1");
	sql($writer,"UPDATE {$tables->order_items()} SET currency='ZZZ' WHERE order_id=1");
	$html=render($page,1); check(str_contains($html,'<td>Unavailable</td>') && !str_contains($html,'€') && !str_contains($html,'ZZZ'),'unknown historical currency does not fall back to current shop currency');
	sql($writer,"UPDATE {$tables->orders()} SET currency='USD' WHERE id=1"); sql($writer,"UPDATE {$tables->order_items()} SET currency='USD' WHERE order_id=1");
	foreach ( ['EUR' => ['€12,00', '€6,00', '€13,00'], 'JPY' => ['1.200 JPY', '600 JPY', '1.300 JPY']] as $currency => [$total, $unit, $subtotal] ) {
		$GLOBALS['wp_locale'] = (object) ['number_format' => ['decimal_point' => ',', 'thousands_sep' => '.']];
		sql($writer,"UPDATE {$tables->orders()} SET currency='$currency',subtotal_minor=1300,total_minor=1200 WHERE id=1");
		sql($writer,"UPDATE {$tables->order_items()} SET currency='$currency',unit_price_minor=600,line_total_minor=1200 WHERE order_id=1");
		sql($writer,"UPDATE {$tables->payment_sessions()} SET currency='$currency',amount_minor=1200 WHERE order_id=1");
		$html=render($page,1);
		check(str_contains($html,"<td>$currency</td>") && str_contains($html,"<td>$unit</td>") && substr_count($html,$total)===5 && str_contains($html,"Subtotal</th><td>$subtotal</td>") && substr_count($html,"Attempt amount</th><td>$total</td>")===2, 'localized amounts in summary, items, attempts and deletion for '.$currency);
	}
	unset($GLOBALS['wp_locale']);
	sql($writer,"UPDATE {$tables->orders()} SET currency='USD' WHERE id=1");
	sql($writer,"UPDATE {$tables->order_items()} SET currency='USD' WHERE order_id=1");
	sql($writer,"UPDATE {$tables->payment_sessions()} SET currency='USD' WHERE order_id=1");
	// A live writer transaction must not block, advance, or contaminate this ordinary read.
	sql($writer,'START TRANSACTION');
	try {
		sql($writer,"UPDATE {$tables->orders()} SET status='failed' WHERE id=1");
		sql($writer,"UPDATE {$tables->order_items()} SET item_name='PRIVATE_UNCOMMITTED' WHERE order_id=1");
		$html=render($page,1); check(str_contains($html,'<td>Paid</td>') && str_contains($html,esc_html($item_name)) && !contains_private($html),'read during writer transaction sees committed historical records');
	} finally {sql($writer,'ROLLBACK');}
	// Empty evidence differs from any failed evidence read.
	sql($writer,"INSERT INTO {$tables->orders()} (id,status,currency,created_at,updated_at) VALUES (3,'pending','EUR','$now','$now')");
	$html=render($page,3);
	check(str_contains($html,'No saved item details are available') && str_contains($html,'No payment sessions recorded.') && str_contains($html,'Awaiting confirmation'),'valid order without item/session evidence has explicit empty sections');
	$long_name = str_repeat('李𠮷', 70) . '<b>name</b>';
	$long_email = str_repeat('a', 63) . '@' . str_repeat('a', 60) . '.example';
	sql($writer,$writer->prepare("UPDATE {$tables->orders()} SET customer_name=NULL,customer_email=%s WHERE id=2",$long_email));
	$html=render($page,2);
	check(str_contains($html,'Customer name</th><td>Not available</td>') && str_contains($html,esc_html($long_email)), 'missing name and full long email render independently');
	sql($writer,$writer->prepare("UPDATE {$tables->orders()} SET customer_name=%s,customer_email=NULL WHERE id=2",$long_name));
	for ($item=23; $item<=26; ++$item) {
		sql($writer,$writer->prepare("INSERT INTO {$tables->order_items()} (id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES ($item,2,$item,%s,200,1,200,200,'EUR',%s)",$long_name,$now));
	}
	$html=render($page,2);
	check(str_contains($html,'Customer email</th><td>Not available</td>') && substr_count($html,esc_html($long_name))===5 && !str_contains($html,'<b>name</b>'), 'missing email, full customer name and all five item rows render with HTML escaping');
	$reader->reads=[]; $before=snapshot($writer,$created); $cookies=$_COOKIE;
	foreach ([1,1,2,3,999,1] as $id) { $html=render($page,$id); check(!contains_private($html),'repeat read protects private values for order '.$id); }
	check(snapshot($writer,$created)===$before && $_COOKIE===$cookies,'repeated detail reads leave every commerce table and cookies unchanged');
	check($reader->unsafe_attempts===0 && !preg_match('/SELECT\s+\*|FOR UPDATE|START TRANSACTION|\b(?:INSERT|UPDATE|DELETE)\b/i',implode("\n",$reader->reads)),'all executed detail reads explicit nonlocking SELECTs only');
		check(count($reader->reads)===38,'detail reads include epoch validation before and after each existing-order projection');
	check(!preg_match('/JOIN|FROM .*c2c_detail_.*(?:_posts|_postmeta|c2c_carts|c2c_cart_items|c2c_reservations)\b/i',implode("\n",$reader->reads)) && $GLOBALS['catalog_calls']===0,'no current catalog/cart/reservation hydration');
	check($GLOBALS['provider_calls']===0,'no provider transport calls');
	check(str_contains($html, 'id="c2c-delete-reveal"') && str_contains($html, 'id="c2c-delete-form" hidden') && str_contains($html, 'id="c2c-delete-submit" disabled'), 'deletion starts hidden with separate disabled final submit');
	check(str_contains($html, 'DELETE ORDER C2C-1') && str_contains($html, 'wp_rest') === false && str_contains($html, 'native-nonce-fixture'), 'exact reference confirmation and generated nonce present');
	preg_match('/data-config="([^"]+)"/', $html, $configuration);
	$configuration=json_decode(html_entity_decode($configuration[1],ENT_QUOTES),true);
	check($configuration['expected_context']['installation_id']===str_repeat('a',32) && $configuration['expected_context']['epoch_id']==='1' && $configuration['expected_context']['epoch_identity']===str_repeat('b',32), 'detail supplies exact captured immutable commerce context');
	check(str_contains($configuration['url'], '?rest_route=/cpt2collection/v1/orders/1'), 'endpoint comes from rest_url including query-form configuration');
	check((bool) preg_match('~<form id="c2c-delete-form" hidden>.*no Trash or Restore.*may still complete.*Displayed status does not establish deletion eligibility.*id="c2c-delete-submit"~s', $html), 'full warnings remain in the review before the irreversible action');
	ob_start();$detail->render('1',['deletion_inspection'=>'1']);$inspection=ob_get_clean();
	check(!str_contains($inspection, '<form') && str_contains($inspection, 'outcome remains unverified'), 'explicit recovery view is read-only and never treats absence as proof');
	check(!$configuration['read_only'], 'normal valid detail supplies confirmation controls');
	$production=file_get_contents(dirname(__DIR__).'/includes/admin/class-order-detail-page.php');
	check(!preg_match('/add_action|register_rest_route|wp_remote_|Service::/',$production),'detail registers no action/REST endpoints and calls no provider or commerce service');
	check(str_contains(file_get_contents(dirname(__DIR__).'/cpt2collection.php'),"includes/admin/class-order-detail-page.php"),'detail renderer included by production bootstrap');
	$reader->after_sessions=static function()use($writer,$tables):void{sql($writer,"UPDATE {$tables->commerce_control()} SET installation_id=REPEAT('f',32) WHERE id=1");};
	check($repo->find_detail(1)===false, 'identity change during projection refuses confirmation context even with unchanged numeric epoch');
	sql($writer,"UPDATE {$tables->commerce_control()} SET installation_id=REPEAT('a',32) WHERE id=1");
	check($repo->find_detail(1)['context']->installation_id()===str_repeat('a',32), 'fresh projection captures restored exact immutable identity');
	$list=$repo->list_page(1);
	check(is_array($list) && !property_exists($list['orders'][0],'customer_name') && !property_exists($list['orders'][0],'customer_email'), 'Order list projection excludes both contact fields');
	check(!str_contains(json_encode($configuration),'detail@example.test') && !str_contains(json_encode($configuration),'Élodie'), 'deletion client configuration contains no customer contact data');
	sql($writer,"UPDATE {$tables->orders()} SET epoch_id=999 WHERE id=1");
	check($repo->find_detail(1)===null && !str_contains(render($page,1),'detail@example.test'), 'detail query excludes contact from a different epoch');


	echo "All $checks admin order detail checks passed.\n";
} finally {
	foreach (array_reverse($created) as $table) { $writer->query("DROP TABLE IF EXISTS $table"); }
	$reader->close(); $writer->close();
}
