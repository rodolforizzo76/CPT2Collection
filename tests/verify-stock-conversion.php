<?php
/** Isolated verification for Milestone 7F-B stock conversion primitives. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
	public string $last_error = ''; public int $last_errno = 0;
	public array $reservations = array(); public array $products = array(); public array $queries = array(); public array $locks = array();
	public ?int $fail_update_post = null; private ?array $backup = null;
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $value = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'"; $query = preg_replace( '/%[ds]/', $value, $query, 1 ); } return $query; }
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( 'START TRANSACTION' === $query ) { $this->backup = $this->products; return 0; }
		if ( 'COMMIT' === $query ) { $this->backup = null; return 0; }
		if ( 'ROLLBACK' === $query ) { $this->products = $this->backup ?? $this->products; $this->backup = null; return 0; }
		if ( str_starts_with( $query, 'UPDATE wp_postmeta' ) && preg_match( "/meta_value = '(\d+)' WHERE post_id = (\d+).*meta_value = '(\d+)'/", $query, $m ) ) {
			$post = (int) $m[2]; if ( $this->fail_update_post === $post ) { $this->last_error = 'injected'; return false; }
			if ( ! isset( $this->products[ $post ] ) || (string) $this->products[ $post ]['stock'] !== $m[3] ) { return 0; }
			$this->products[ $post ]['stock'] = $m[1]; return 1;
		}
		return false;
	}
	public function get_var( string $query ): mixed { $this->queries[] = $query; $this->last_error = ''; if ( preg_match( '/FROM wp_posts WHERE ID = (\d+)/', $query, $m ) ) { $id = (int) $m[1]; $this->locks[] = $id; return isset( $this->products[ $id ] ) ? $id : null; } return null; }
	public function get_row( string $query ): ?object { $this->queries[]=$query; $this->last_error=''; if(str_contains($query,'wp_c2c_orders')&&preg_match('/id = (\d+)/',$query,$m))return(object)['id'=>(int)$m[1],'epoch_id'=>1,'cart_id'=>null]; return null; }
	public function get_results( string $query ): array|null {
		if (null !== ($foundation = $this->foundation_results($query))) return $foundation;
		if (str_contains($query,'information_schema.TABLES')) { preg_match_all("/'([^']+)'/",$query,$m); return array_map(fn($n)=>(object)['TABLE_NAME'=>$n,'ENGINE'=>'InnoDB'],$m[1]); }
		$this->queries[] = $query; $this->last_error = '';
		if ( str_contains( $query, 'FROM wp_posts p' ) && preg_match( '/p.ID = (\d+)/', $query, $m ) ) { $p = $this->products[ (int) $m[1] ] ?? null; if ( null === $p ) return array(); return array( (object) array( 'post_type' => $p['post_type'], 'meta_key' => '_cpt2collection_stock_quantity', 'meta_value' => $p['stock'] ) ); }
		if ( ! str_contains( $query, 'wp_c2c_reservations' ) ) return array();
		$rows = $this->reservations;
		if ( preg_match( '/WHERE id IN \(([^)]+)\)/', $query, $m ) ) { $ids = array_map( 'intval', explode( ',', $m[1] ) ); $rows = array_filter( $rows, fn( $r ) => in_array( $r['id'], $ids, true ) ); }
		elseif ( str_contains( $query, 'status = \'active\'' ) ) { preg_match( "/expires_at > '([^']+)'/", $query, $m ); $rows = array_filter( $rows, fn( $r ) => 'active' === $r['status'] && $r['expires_at'] > $m[1] ); }
		elseif ( preg_match( '/WHERE order_id = (\d+) OR order_item_id IN \(([^)]+)\)/', $query, $m ) ) { $order = (int) $m[1]; $ids = array_map( 'intval', explode( ',', $m[2] ) ); $rows = array_filter( $rows, fn( $r ) => $r['order_id'] === $order || in_array( $r['order_item_id'], $ids, true ) ); }
		usort( $rows, fn( $a, $b ) => str_contains( $query, 'ORDER BY post_id' ) ? array( $a['post_id'], $a['id'] ) <=> array( $b['post_id'], $b['id'] ) : $a['id'] <=> $b['id'] );
		return array_map( fn( $r ) => (object) $r, $rows );
	}
}
function __( string $s, string $d ): string { return $s; }
foreach ( array( 'table-names','money','reservation-statuses','order-item','reservation-record','reservation-repository','transaction-manager','meta-keys','locked-catalog-reader','conversion-reservation-validation-result','conversion-reservation-set-validator','stock-conversion-result','stock-conversion-processor' ) as $class ) require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';

use RodolfoRizzo\Cpt2Collection\{ConversionReservationSetValidator,LockedCatalogReader,OrderItem,ReservationRecord,ReservationRepository,StockConversionProcessor,TableNames,TransactionManager};
function check( bool $ok, string $label ): void { if ( ! $ok ) throw new RuntimeException( $label ); echo "PASS: $label\n"; }
function oi( int $id, int $order, int $post, int $qty ): OrderItem { return new OrderItem( $id,$order,$post,'Item',100,$qty,100*$qty,100*$qty,'EUR','2026-01-01 00:00:00' ); }
function rr( int $id, int $order, int $item, int $post, int $qty, string $status='active', string $expires='2026-09-04 00:00:00', ?string $released=null, ?string $converted=null ): ReservationRecord { return new ReservationRecord( $id,$order,$item,$post,$qty,$status,'2026-01-01 00:00:00','2026-01-01 00:00:00',$expires,$released,$converted ); }
function row( ReservationRecord $r ): array { return array( 'epoch_id'=>1,'id'=>$r->id(),'order_id'=>$r->order_id(),'order_item_id'=>$r->order_item_id(),'post_id'=>$r->post_id(),'quantity'=>$r->quantity(),'status'=>$r->status(),'created_at'=>$r->created_at(),'updated_at'=>$r->updated_at(),'expires_at'=>$r->expires_at(),'released_at'=>$r->released_at(),'converted_at'=>$r->converted_at() ); }
$v = new ConversionReservationSetValidator(); $item = oi( 10,1,20,2 );
check( $v->validate(1,array($item),array(rr(1,1,10,20,2)))->is_valid(), 'active reservation is structurally convertible' );
check( $v->validate(1,array($item),array(rr(1,1,10,20,2,'active','2020-01-01 00:00:00')))->is_valid(), 'past-deadline active reservation remains structurally convertible' );
check( $v->validate(1,array($item),array(rr(1,1,10,20,2,'expired','2020-01-01 00:00:00')))->is_valid(), 'materialized expired reservation is structurally convertible' );
check( 'reservation_released' === $v->validate(1,array($item),array(rr(1,1,10,20,2,'released','2020-01-01 00:00:00','2020-01-01 00:01:00')))->code(), 'released reservation is rejected specifically' );
check( 'reservation_already_converted' === $v->validate(1,array($item),array(rr(1,1,10,20,2,'converted','2020-01-01 00:00:00',null,'2020-01-01 00:01:00')))->code(), 'converted reservation is rejected as fresh conversion' );
check( $v->validate_converted(1,array($item),array(rr(1,1,10,20,2,'converted','2020-01-01 00:00:00',null,'2020-01-01 00:01:00')))->is_valid(), 'exact converted reservation set is valid for idempotency' );
check( ! $v->validate_converted(1,array($item),array(rr(1,1,10,20,2,'converted','2020-01-01 00:00:00','2020-01-01 00:00:30','2020-01-01 00:01:00')))->is_valid(), 'converted idempotency set rejects released timestamp' );
check( ! $v->validate_converted(1,array($item),array(rr(1,1,10,20,2)))->is_valid(), 'converted idempotency set rejects active reservation' );
foreach ( array( rr(1,2,10,20,2), rr(1,1,10,21,2), rr(1,1,10,20,3), rr(1,1,11,20,2) ) as $bad ) check( 'reservation_structural_mismatch' === $v->validate(1,array($item),array($bad))->code(), 'order/item/post/quantity mismatch is rejected' );
check( 'reservation_missing' === $v->validate(1,array($item),array())->code(), 'missing reservation is distinguished' );
$agg = $v->validate( 1,array(oi(10,1,20,2),oi(11,1,20,3)),array(rr(1,1,10,20,2),rr(2,1,11,20,3)) ); check( array(20=>5) === $agg->quantities(), 'same-product order items aggregate Q' );

function scenario( int $stock, array $converting, array $others=array(), array $products=array() ): array {
	$db = new wpdb(); $db->products = $products ?: array( 20=>array('post_type'=>'collection_item','stock'=>(string)$stock) ); $db->reservations = array_map('row',array_merge($converting,$others));
	$t = new TransactionManager($db,new TableNames($db)); $repo = new ReservationRepository($db,new TableNames($db)); $catalog = new LockedCatalogReader($db,$t); $p = new StockConversionProcessor($repo,$catalog,$t); $t->begin_commerce_shared(); return array($db,$t,$p);
}
$own=rr(1,1,10,20,2); [$db,$t,$p]=scenario(5,array($own),array(rr(2,2,20,20,3))); $result=$p->decrement(1,array($item),'collection_item','2026-09-03 00:00:00'); check($result->is_success() && '3'===$db->products[20]['stock'],'Rother includes other active reservation and equality boundary passes'); check(array(1=>'active')===$result->reservations(),'successful result exposes the exact locked validated reservation status map'); $t->rollback();
[$db,$t,$p]=scenario(2,array($own)); $result=$p->decrement(1,array($item),'collection_item','2026-09-03 00:00:00'); check($result->is_success() && 0===$result->stocks()[20],'exact converting reservation ID is excluded from Rother and decrement is S-Q');
[$db,$t,$p]=scenario(2,array($own),array(rr(2,2,20,20,99,'active','2020-01-01 00:00:00'))); check($p->decrement(1,array($item),'collection_item','2026-09-03 00:00:00')->is_success(),'expired competing active row does not count in Rother');
[$db,$t,$p]=scenario(4,array($own),array(rr(2,2,20,20,3))); check('insufficient_stock'===$p->decrement(1,array($item),'collection_item','2026-09-03 00:00:00')->code() && '4'===$db->products[20]['stock'],'S below Q plus Rother fails before mutation');
[$db,$t,$p]=scenario(1,array($own)); check('insufficient_stock'===$p->decrement(1,array($item),'collection_item','2026-09-03 00:00:00')->code() && '1'===$db->products[20]['stock'],'stock can never become negative');
$items=array(oi(10,1,20,2),oi(11,1,10,1)); $reservations=array(rr(2,1,10,20,2),rr(1,1,11,10,1)); [$db,$t,$p]=scenario(0,$reservations,array(),array(20=>array('post_type'=>'collection_item','stock'=>'2'),10=>array('post_type'=>'collection_item','stock'=>'1'))); $db->fail_update_post=20; $result=$p->decrement(1,$items,'collection_item','2026-09-03 00:00:00'); check('database_error'===$result->code(),'later product update failure is explicit'); $t->rollback(); check('1'===$db->products[10]['stock'] && '2'===$db->products[20]['stock'],'caller rollback removes partial multi-product mutation');
check(array(10,20)===$db->locks,'product rows lock once in ascending order');
$locking=array_values(array_filter($db->queries,fn($q)=>str_contains($q,'wp_c2c_reservations')&&str_contains($q,'FOR UPDATE'))); check(2===count($locking)&&str_contains($locking[0],'ORDER BY post_id ASC, id ASC')&&str_contains($locking[1],'ORDER BY post_id ASC, id ASC'),'active and converting reservation locks use deterministic post/id order');
$discovery_index=array_key_first(array_filter($db->queries,fn($q)=>str_contains($q,'WHERE order_id'))); $product_index=array_key_first(array_filter($db->queries,fn($q)=>str_contains($q,'FROM wp_posts WHERE'))); check(is_int($discovery_index)&&is_int($product_index)&&$discovery_index<$product_index&&!str_contains($db->queries[$discovery_index],'FOR UPDATE'),'nonlocking structural discovery precedes product locks');
$db2=new wpdb(); $t2=new TransactionManager($db2,new TableNames($db2)); $catalog2=new LockedCatalogReader($db2,$t2); check(!$catalog2->decrement_stock(20,1,1),'stock writer refuses operation outside transaction');
echo "All Milestone 7F-B isolated stock conversion checks passed.\n";
