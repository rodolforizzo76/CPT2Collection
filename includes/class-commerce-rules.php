<?php
/**
 * Read-only commercial decision rules.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates current catalog state without mutating the cart or catalog.
 */
final class CommerceRules {
	private PostTypes $post_types;

	public function __construct( PostTypes $post_types ) {
		$this->post_types = $post_types;
	}

	public function can_add_item( int $post_id, int $quantity ): CommerceRuleResult {
		return $this->validate_item( $post_id, $quantity );
	}

	public function can_set_quantity( int $post_id, int $quantity ): CommerceRuleResult {
		return $this->validate_item( $post_id, $quantity );
	}

	/**
	 * Validates each item before checking exemption and total quantity, in that order.
	 *
	 * @param CartItem[] $items Cart items to revalidate against current catalog state.
	 */
	public function can_checkout( array $items ): CommerceRuleResult {
		if ( array() === $items ) {
			return CommerceRuleResult::denied( 'empty_cart', __( 'The cart must contain at least one item.', 'cpt2collection' ) );
		}

		$total_quantity = 0;
		$has_exemption  = false;

		foreach ( $items as $item ) {
			if ( ! $item instanceof CartItem ) {
				return CommerceRuleResult::denied( 'invalid_quantity', __( 'The cart contains an invalid item.', 'cpt2collection' ) );
			}

			$result = $this->validate_item( $item->post_id(), $item->quantity() );
			if ( ! $result->is_allowed() ) {
				return $result;
			}

			$total_quantity += $item->quantity();
			$has_exemption   = $has_exemption || Catalog::is_minimum_order_exempt( $item->post_id() );
		}

		if ( $has_exemption ) {
			return CommerceRuleResult::allowed( 'minimum_order_exempt', __( 'Checkout is allowed because the cart contains a minimum-order exempt item.', 'cpt2collection' ) );
		}

		if ( $total_quantity < CommerceSettings::get_minimum_order_quantity() ) {
			return CommerceRuleResult::denied( 'minimum_order_not_met', __( 'The minimum order quantity has not been reached.', 'cpt2collection' ) );
		}

		return $this->allowed_result();
	}

	/**
	 * Applies post, post type, quantity, status, price, stock, and requested-stock checks.
	 */
	private function validate_item( int $post_id, int $quantity ): CommerceRuleResult {
		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			return CommerceRuleResult::denied( 'invalid_post', __( 'The requested item does not exist.', 'cpt2collection' ) );
		}

		if ( get_post_type( $post_id ) !== $this->post_types->get_selected_post_type() ) {
			return CommerceRuleResult::denied( 'wrong_post_type', __( 'The requested item is not part of the configured collection.', 'cpt2collection' ) );
		}

		if ( $quantity <= 0 ) {
			return CommerceRuleResult::denied( 'invalid_quantity', __( 'The requested quantity must be greater than zero.', 'cpt2collection' ) );
		}

		$status = (string) get_post_meta( $post_id, MetaKeys::STATUS, true );
		if ( 'available' !== $status ) {
			return CommerceRuleResult::denied( 'not_available', __( 'The requested item is not available.', 'cpt2collection' ) );
		}

		$price_minor = get_post_meta( $post_id, MetaKeys::PRICE_MINOR, true );
		if ( ! $this->is_valid_price_minor( $price_minor ) ) {
			return CommerceRuleResult::denied( 'invalid_price', __( 'The requested item does not have a valid price.', 'cpt2collection' ) );
		}

		$stock = Catalog::get_stock_quantity( $post_id );
		if ( 0 === $stock ) {
			return CommerceRuleResult::denied( 'out_of_stock', __( 'The requested item is out of stock.', 'cpt2collection' ) );
		}

		if ( $quantity > $stock ) {
			return CommerceRuleResult::denied( 'insufficient_stock', __( 'The requested quantity exceeds the available stock.', 'cpt2collection' ) );
		}

		return $this->allowed_result();
	}

	/**
	 * Accepts only positive integer minor units, without numeric coercion.
	 *
	 * @param mixed $price_minor Raw stored price.
	 */
	private function is_valid_price_minor( $price_minor ): bool {
		if ( is_int( $price_minor ) ) {
			return $price_minor > 0;
		}

		if ( ! is_string( $price_minor ) || 1 !== preg_match( '/^[1-9]\d*$/', $price_minor ) ) {
			return false;
		}

		$price = filter_var( $price_minor, FILTER_VALIDATE_INT );

		return is_int( $price ) && $price > 0;
	}

	private function allowed_result(): CommerceRuleResult {
		return CommerceRuleResult::allowed( 'allowed', __( 'The commercial rules allow this request.', 'cpt2collection' ) );
	}
}
