<?php
/** Ownership checks shared by repositories; never initializes or repairs state. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
final class EpochOwnership {
	public static function context( \wpdb $db, TableNames $tables ): ?CommerceContext {
		$active = TransactionManager::context_on( $db );
		return $active ?? ( new CommerceControlRepository( $db, $tables ) )->read();
	}
	/** Ordinary committed read. Inside admission, epoch and parent ownership are immutable. */
	public static function owns( \wpdb $db, TableNames $tables, string $table, int $id ): bool {
		$context = self::context( $db, $tables );
		if ( null === $context || $id < 1 ) { return false; }
		$db->last_error = '';
		$row = $db->get_row( $db->prepare( 'SELECT id, epoch_id FROM ' . $table . ' WHERE id = %d', $id ) );
		if ( '' !== $db->last_error || null === $row || !isset($row->epoch_id) || (int)$row->epoch_id !== $context->epoch_id() ) { return false; }
		if($table===$tables->payment_sessions()||$table===$tables->reservations()){
			$parent=$db->get_row($db->prepare('SELECT order_id FROM '.$table.' WHERE id=%d',$id));
			if(null===$parent||!self::owns($db,$tables,$tables->orders(),(int)$parent->order_id)){return false;}
		}
		if($table===$tables->orders()){
			$parent=$db->get_row($db->prepare('SELECT c.epoch_id FROM '.$tables->orders().' o JOIN '.$tables->carts().' c ON c.id=o.cart_id WHERE o.id=%d',$id));
			if(''!==$db->last_error||(null!==$parent&&(int)$parent->epoch_id!==$context->epoch_id())){return false;}
		}
		if($table===$tables->reservations()){
			$parent=$db->get_row($db->prepare('SELECT r.order_id, r.post_id, i.order_id AS item_order_id, i.post_id AS item_post_id FROM '.$table.' r JOIN '.$tables->order_items().' i ON i.id=r.order_item_id WHERE r.id=%d',$id));
			if(null===$parent||(int)$parent->order_id!==(int)$parent->item_order_id||(int)$parent->post_id!==(int)$parent->item_post_id){return false;}
		}
		return true;
	}
	public static function predicate( \wpdb $db, TableNames $tables, string $alias = '' ): string {
		$context = self::context( $db, $tables );
		return ( '' === $alias ? '' : $alias . '.' ) . 'epoch_id = ' . ( null === $context ? '0' : (string) $context->epoch_id() );
	}
}
