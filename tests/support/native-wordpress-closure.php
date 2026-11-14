<?php
/** CLI companion for the disposable native WordPress closure verifier. Never use on an existing site. */
declare(strict_types=1);
if (getenv('C2C_NATIVE_DISPOSABLE') !== 'phase49' || !str_starts_with((string)getenv('C2C_NATIVE_WP_ROOT'), '/tmp/')) {
	throw new RuntimeException('Task-owned disposable WordPress root required.');
}
$root = getenv('C2C_NATIVE_WP_ROOT');
$mode = $argv[1] ?? '';
if ($mode === 'install') define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:'.getenv('C2C_NATIVE_PORT');
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root.'/wp-load.php';
if ($mode === 'install') {
	require ABSPATH.'wp-admin/includes/upgrade.php';
	wp_install('Disposable Phase 4.9', 'closure_admin', 'nobody@example.invalid', false, '', bin2hex(random_bytes(24)));
	update_option('cpt2collection_post_type', 'closure_product');
	require_once ABSPATH.'wp-admin/includes/plugin.php';
	$result = activate_plugin('cpt2collection/cpt2collection.php');
	if (is_wp_error($result)) throw new RuntimeException($result->get_error_code());
	echo json_encode(['installed'=>true,'plugin'=>CPT2COLLECTION_VERSION]);
	exit;
}
use RodolfoRizzo\Cpt2Collection\{CommerceWpdb,TableNames,TransactionManager,CartRepository,OrderRepository,CartRecord};
if ($mode === 'auth') {
	update_option('permalink_structure', getenv('C2C_NATIVE_PERMALINKS')==='plain'?'':'/%postname%/');
	$wp_rewrite->init();
	flush_rewrite_rules(false);
	$subscriber = wp_create_user('closure_subscriber', bin2hex(random_bytes(24)), 'subscriber@example.invalid');
	if (is_wp_error($subscriber)) throw new RuntimeException($subscriber->get_error_code());
	$out = [];
	foreach (['admin'=>1, 'subscriber'=>$subscriber] as $key=>$uid) {
		wp_set_current_user($uid);
		$token = WP_Session_Tokens::get_instance($uid)->create(time()+3600);
		$cookie = wp_generate_auth_cookie($uid, time()+3600, 'logged_in', $token);
		$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
		$auth_cookie = wp_generate_auth_cookie($uid, time()+3600, 'auth', $token);
		$expired = substr(wp_hash((wp_nonce_tick('wp_rest')-2).'|wp_rest|'.$uid.'|'.$token, 'nonce'), -12, 10);
		$out[$key] = ['cookie'=>LOGGED_IN_COOKIE.'='.$cookie.'; '.AUTH_COOKIE.'='.$auth_cookie, 'nonce'=>wp_create_nonce('wp_rest'), 'expired_nonce'=>$expired];
	}
	$password = WP_Application_Passwords::create_new_application_password(1, ['name'=>'Disposable closure']);
	if (is_wp_error($password)) throw new RuntimeException($password->get_error_code());
	$out['application_password'] = $password[0];
	$out['version'] = $wp_version;
	$out['schema'] = get_option('cpt2collection_schema_version');
	echo json_encode($out);
	exit;
}
$db = CommerceWpdb::connect($wpdb);
$t = new TableNames($db);
if (in_array($mode, ['seed', 'seed-browser'], true)) {
	$tx = new TransactionManager($db, $t);
	$carts = new CartRepository($db, $t);
	$orders = new OrderRepository($db, $t);
	$now = gmdate('Y-m-d H:i:s');
	$ids = [];
	$post = wp_insert_post(['post_type'=>'closure_product','post_status'=>'publish','post_title'=>'Closure product']);
	update_post_meta($post, '_cpt2collection_stock_quantity', 19);
	for ($i=0; $i<205; ++$i) {
		if (!$tx->begin_commerce_shared()) throw new RuntimeException('seed admission');
		$cart = $carts->create(hash('sha256', random_bytes(32)), $now, $now, $now, '2030-01-01 00:00:00');
		if (!$cart instanceof CartRecord) throw new RuntimeException('seed cart');
		if ($i<($mode === 'seed-browser' ? 4 : 2)) {
			$item = $carts->add_item_quantity_locked($cart->id(), $post, 1);
			$order = $orders->create($cart->id(), 'pending', 'EUR', 100, 100, $now, $now, hash('sha256', random_bytes(32)), 0, 0);
			if (!$item || !$order || !$orders->insert_item($order->id(), $post, 'Closure product', 100, 1, 100, 100, 'EUR', $now, $item->id(), $item->content_version())) throw new RuntimeException('seed order');
			$ids[] = $order->id();
		}
		if (!$tx->commit()) throw new RuntimeException('seed commit');
	}
	echo json_encode(['orders'=>array_slice($ids,0,2), 'browser_orders'=>array_slice($ids,2)]);
} elseif ($mode === 'snapshot') {
	$out = [];
	foreach (['commerce_control','commerce_epochs','commerce_resets','carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events','order_deletions','provider_claims','provider_identities'] as $method) {
		$rows = $db->get_results('SELECT * FROM '.$t->$method().' ORDER BY id');
		if ($db->last_error) throw new RuntimeException('snapshot read');
		$out[$method] = ['count'=>count($rows), 'hash'=>hash('sha256', serialize($rows))];
	}
	$out['control'] = $db->get_row('SELECT * FROM '.$t->commerce_control());
	$out['epoch_identity'] = $db->get_var('SELECT identity FROM '.$t->commerce_epochs().' WHERE id='.(int)$out['control']->current_epoch_id);
	$out['stock'] = $wpdb->get_results("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_cpt2collection_stock_quantity' ORDER BY meta_id");
	echo json_encode($out);
} else throw new RuntimeException('Unknown mode');
$db->close();
