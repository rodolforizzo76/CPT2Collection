<?php
/** Transactional cart-content application service. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use Throwable;
defined( 'ABSPATH' ) || exit;

final class CartService {
	private CartRecord $cart;
	private CartRepository $repository;
	private CartActivity $activity;
	private CommerceRules $rules;
	private TransactionManager $transactions;

	public function __construct( CartRecord $cart, CartRepository $repository, CartActivity $activity, CommerceRules $rules, TransactionManager $transactions ) {
		$this->cart = $cart; $this->repository = $repository; $this->activity = $activity; $this->rules = $rules; $this->transactions = $transactions;
	}

	public function cart(): CartRecord { return $this->cart; }

	public function add( int $post_id, int $quantity = 1 ): CartOperationResult {
		if ( ! $this->has_valid_cart() ) return $this->failure( 'add', 'invalid_cart', $post_id, null, __( 'Invalid cart.', 'cpt2collection' ) );
		if ( $post_id <= 0 ) return $this->failure( 'add', 'invalid_post', $post_id, null, __( 'post_id must be greater than zero.', 'cpt2collection' ) );
		if ( $quantity < 1 ) return $this->failure( 'add', 'invalid_quantity', $post_id, $quantity, __( 'quantity must be at least 1.', 'cpt2collection' ) );
		if ( ! $this->begin() ) return $this->transaction_failure( 'add', $post_id, $quantity );
		try {
			$cart = $this->lock_cart();
			if ( ! $cart instanceof CartRecord ) return $this->rollback_failure( 'add', false === $cart ? 'repository_error' : 'invalid_cart', $post_id, $quantity, __( 'Unable to lock cart.', 'cpt2collection' ) );
			$current = $this->repository->find_item_locked( $cart->id(), $post_id );
			if ( false === $current ) return $this->rollback_failure( 'add', 'repository_error', $post_id, $quantity, __( 'Unable to read cart item.', 'cpt2collection' ) );
			$final = $quantity + ( $current instanceof CartItem ? $current->quantity() : 0 );
			$rule = $this->rules->can_add_item( $post_id, $final );
			if ( ! $rule->is_allowed() ) return $this->rollback_result( CartOperationResult::failure( 'add', $rule->get_code(), $post_id, $final, $rule->get_message() ) );
			$item = $this->repository->add_item_quantity_locked( $cart->id(), $post_id, $quantity );
			if ( false === $item ) return $this->rollback_failure( 'add', 'repository_error', $post_id, $final, __( 'Unable to add item to cart.', 'cpt2collection' ) );
			if ( ! $this->advance_and_commit( $cart ) ) return $this->transaction_failure( 'add', $post_id, $final );
			return CartOperationResult::success( 'add', 'item_added', $post_id, $item->quantity(), __( 'Item added to cart.', 'cpt2collection' ) );
		} catch ( Throwable $error ) { return $this->exception_failure( 'add', $post_id, $quantity ); }
	}

	public function remove( int $post_id ): CartOperationResult {
		if ( ! $this->has_valid_cart() ) return $this->failure( 'remove', 'invalid_cart', $post_id, null, __( 'Invalid cart.', 'cpt2collection' ) );
		if ( $post_id <= 0 ) return $this->failure( 'remove', 'invalid_post', $post_id, null, __( 'post_id must be greater than zero.', 'cpt2collection' ) );
		if ( ! $this->begin() ) return $this->transaction_failure( 'remove', $post_id, null );
		try {
			$cart = $this->lock_cart();
			if ( ! $cart instanceof CartRecord ) return $this->rollback_failure( 'remove', false === $cart ? 'repository_error' : 'invalid_cart', $post_id, null, __( 'Unable to lock cart.', 'cpt2collection' ) );
			$item = $this->repository->find_item_locked( $cart->id(), $post_id );
			if ( false === $item ) return $this->rollback_failure( 'remove', 'repository_error', $post_id, null, __( 'Unable to read cart item.', 'cpt2collection' ) );
			if ( null === $item ) return $this->rollback_failure( 'remove', 'item_not_found', $post_id, null, __( 'Item is not present in the cart.', 'cpt2collection' ) );
			$deleted = $this->repository->delete_item_locked( $cart->id(), $post_id );
			if ( false === $deleted || 1 !== $deleted ) return $this->rollback_failure( 'remove', 'repository_error', $post_id, null, __( 'Unable to remove item from cart.', 'cpt2collection' ) );
			if ( ! $this->advance_and_commit( $cart ) ) return $this->transaction_failure( 'remove', $post_id, null );
			return CartOperationResult::success( 'remove', 'removed', $post_id, null, __( 'Item removed from cart.', 'cpt2collection' ) );
		} catch ( Throwable $error ) { return $this->exception_failure( 'remove', $post_id, null ); }
	}

	public function set_quantity( int $post_id, int $quantity ): CartOperationResult {
		if ( ! $this->has_valid_cart() ) return $this->failure( 'set_quantity', 'invalid_cart', $post_id, null, __( 'Invalid cart.', 'cpt2collection' ) );
		if ( $post_id <= 0 ) return $this->failure( 'set_quantity', 'invalid_post', $post_id, null, __( 'post_id must be greater than zero.', 'cpt2collection' ) );
		if ( $quantity < 1 ) return $this->failure( 'set_quantity', 'invalid_quantity', $post_id, $quantity, __( 'quantity must be at least 1.', 'cpt2collection' ) );
		if ( ! $this->begin() ) return $this->transaction_failure( 'set_quantity', $post_id, $quantity );
		try {
			$cart = $this->lock_cart();
			if ( ! $cart instanceof CartRecord ) return $this->rollback_failure( 'set_quantity', false === $cart ? 'repository_error' : 'invalid_cart', $post_id, $quantity, __( 'Unable to lock cart.', 'cpt2collection' ) );
			$item = $this->repository->find_item_locked( $cart->id(), $post_id );
			if ( false === $item ) return $this->rollback_failure( 'set_quantity', 'repository_error', $post_id, $quantity, __( 'Unable to read cart item.', 'cpt2collection' ) );
			if ( null === $item ) return $this->rollback_failure( 'set_quantity', 'item_not_found', $post_id, $quantity, __( 'Item is not present in the cart.', 'cpt2collection' ) );
			$rule = $this->rules->can_set_quantity( $post_id, $quantity );
			if ( ! $rule->is_allowed() ) return $this->rollback_result( CartOperationResult::failure( 'set_quantity', $rule->get_code(), $post_id, $quantity, $rule->get_message() ) );
			if ( $item->quantity() === $quantity ) {
				if ( ! $this->commit_unchanged( $cart ) ) return $this->transaction_failure( 'set_quantity', $post_id, $quantity );
				return CartOperationResult::success( 'set_quantity', 'quantity_unchanged', $post_id, $quantity, __( 'Item quantity is unchanged.', 'cpt2collection' ) );
			}
			$updated = $this->repository->update_item_quantity_locked( $cart->id(), $post_id, $quantity );
			if ( false === $updated || 1 !== $updated ) return $this->rollback_failure( 'set_quantity', 'repository_error', $post_id, $quantity, __( 'Unable to update item quantity.', 'cpt2collection' ) );
			if ( ! $this->advance_and_commit( $cart ) ) return $this->transaction_failure( 'set_quantity', $post_id, $quantity );
			return CartOperationResult::success( 'set_quantity', 'quantity_updated', $post_id, $quantity, __( 'Item quantity updated.', 'cpt2collection' ) );
		} catch ( Throwable $error ) { return $this->exception_failure( 'set_quantity', $post_id, $quantity ); }
	}

	public function clear(): CartOperationResult {
		if ( ! $this->has_valid_cart() ) return $this->failure( 'clear', 'invalid_cart', null, null, __( 'Invalid cart.', 'cpt2collection' ) );
		if ( ! $this->begin() ) return $this->transaction_failure( 'clear', null, null );
		try {
			$cart = $this->lock_cart();
			if ( ! $cart instanceof CartRecord ) return $this->rollback_failure( 'clear', false === $cart ? 'repository_error' : 'invalid_cart', null, null, __( 'Unable to lock cart.', 'cpt2collection' ) );
			$deleted = $this->repository->delete_items_locked( $cart->id() );
			if ( false === $deleted ) return $this->rollback_failure( 'clear', 'repository_error', null, null, __( 'Unable to clear cart.', 'cpt2collection' ) );
			if ( 0 === $deleted ) {
				if ( ! $this->commit_unchanged( $cart ) ) return $this->transaction_failure( 'clear', null, null );
				return CartOperationResult::success( 'clear', 'cart_already_empty', null, null, __( 'Cart is already empty.', 'cpt2collection' ) );
			}
			if ( ! $this->advance_and_commit( $cart ) ) return $this->transaction_failure( 'clear', null, null );
			return CartOperationResult::success( 'clear', 'cleared', null, null, __( 'Cart cleared.', 'cpt2collection' ) );
		} catch ( Throwable $error ) { return $this->exception_failure( 'clear', null, null ); }
	}

	/** @return CartItem[] */
	public function get_items(): array { return $this->has_valid_cart() ? $this->repository->get_items( $this->cart->id() ) : array(); }
	public function get_unique_item_count(): int { return count( $this->get_items() ); }
	public function get_total_quantity(): int { $total = 0; foreach ( $this->get_items() as $item ) $total += $item->quantity(); return $total; }

	private function begin(): bool { return $this->transactions->supports_cart_mutation() && $this->transactions->begin_commerce_shared(); }
	/** @return CartRecord|false|null */
	private function lock_cart() { return $this->cart->epoch_id() === $this->transactions->commerce_context()?->epoch_id() ? $this->repository->find_for_update( $this->cart->id() ) : null; }
	private function advance_and_commit( CartRecord $cart ): bool {
		$times = $this->activity->mutation_timestamps( $cart );
		if ( ! $this->repository->advance_content_revision( $cart->id(), $cart->content_revision(), $times['updated_at'], $times['last_activity_at'], $times['expires_at'] ) ) {
			$this->transactions->rollback(); return false;
		}
		if ( ! $this->transactions->commit() ) return false;
		$this->cart = new CartRecord( $cart->id(), $cart->token_hash(), $cart->created_at(), $times['updated_at'], $times['last_activity_at'] ?? $cart->last_activity_at(), $times['expires_at'] ?? $cart->expires_at(), $cart->content_revision() + 1, 0, $cart->epoch_id() );
		return true;
	}
	private function commit_unchanged( CartRecord $cart ): bool {
		$times = $this->activity->mutation_timestamps( $cart );
		$fresh = $cart;
		if ( null !== $times['last_activity_at'] ) {
			$touched = $this->repository->touch( $cart->id(), $times['updated_at'], $times['last_activity_at'], (string) $times['expires_at'] );
			if ( false === $touched ) { $this->transactions->rollback(); return false; }
			$fresh = $touched;
		}
		if ( ! $this->transactions->commit() ) return false;
		$this->cart = $fresh;
		return true;
	}
	private function rollback_result( CartOperationResult $result ): CartOperationResult { return $this->transactions->rollback() ? $result : $this->transaction_failure( $result->get_operation(), $result->get_post_id(), $result->get_quantity() ); }
	private function rollback_failure( string $operation, string $code, ?int $post_id, ?int $quantity, string $message ): CartOperationResult { return $this->rollback_result( $this->failure( $operation, $code, $post_id, $quantity, $message ) ); }
	private function exception_failure( string $operation, ?int $post_id, ?int $quantity ): CartOperationResult { if ( ! $this->transactions->rollback() || $this->transactions->is_outcome_uncertain() ) return $this->transaction_failure( $operation, $post_id, $quantity ); return $this->failure( $operation, 'repository_error', $post_id, $quantity, __( 'The cart mutation failed.', 'cpt2collection' ) ); }
	private function transaction_failure( string $operation, ?int $post_id, ?int $quantity ): CartOperationResult { return $this->failure( $operation, $this->transactions->is_outcome_uncertain() ? 'transaction_outcome_unknown' : 'repository_error', $post_id, $quantity, __( 'The cart mutation transaction could not be completed.', 'cpt2collection' ) ); }
	private function failure( string $operation, string $code, ?int $post_id, ?int $quantity, string $message ): CartOperationResult { return CartOperationResult::failure( $operation, $code, $post_id, $quantity, __( $message, 'cpt2collection' ) ); }
	private function has_valid_cart(): bool { return $this->cart->id() > 0; }
}
