<?php
/**
 * Read-only REST cart representation.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class CartResponseBuilder {
	private CartRepository $repository;
	private CommerceRules $rules;

	public function __construct( CartRepository $repository, CommerceRules $rules ) {
		$this->repository = $repository;
		$this->rules      = $rules;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build( ?CartRecord $cart ): array {
		$items            = null === $cart ? array() : $this->repository->get_items( $cart->id() );
		$response_items   = array();
		$total_quantity   = 0;
		$total_minor      = 0;
		$has_invalid      = false;

		foreach ( $items as $item ) {
			$quantity       = $item->quantity();
			$total_quantity = $this->safe_add( $total_quantity, $quantity );
			$commercial     = $this->rules->can_set_quantity( $item->post_id(), $quantity );
			$price_minor    = $this->get_price_minor( $item->post_id() );
			$subtotal_minor = $commercial->is_allowed() && null !== $price_minor ? $this->safe_multiply( $price_minor, $quantity ) : null;

			if ( ! $commercial->is_allowed() || null === $subtotal_minor ) {
				$has_invalid = true;
				$total_minor = null;
			} elseif ( null !== $total_minor ) {
				$total_minor = $this->safe_price_add( $total_minor, $subtotal_minor );
				if ( null === $total_minor ) {
					$has_invalid = true;
				}
			}

			$response_items[] = array(
				'post_id'        => $item->post_id(),
				// Decode one entity layer for display; the frontend inserts this as textContent.
				'title'          => html_entity_decode( get_the_title( $item->post_id() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'permalink'      => get_permalink( $item->post_id() ) ?: '',
				'thumbnail'      => $this->get_thumbnail( $item->post_id() ),
				'quantity'       => $quantity,
				'stock'          => Catalog::get_stock_quantity( $item->post_id() ),
				'price_minor'    => $price_minor,
				'subtotal_minor' => $subtotal_minor,
				'commercial'     => array(
					'valid'   => $commercial->is_allowed(),
					'code'    => $commercial->get_code(),
					'message' => $commercial->get_message(),
				),
			);
		}

		$minimum  = CommerceSettings::get_minimum_order_quantity();
		$checkout = $this->rules->can_checkout( $items );
		$remaining = $checkout->is_allowed() && 'minimum_order_exempt' === $checkout->get_code()
			? 0
			: max( 0, $minimum - $total_quantity );

		return array(
			'checkout_context'         => null === $cart ? null : $this->repository->browser_checkout_context($cart),
			'items'                    => $response_items,
			'unique_item_count'        => count( $response_items ),
			'total_quantity'           => $total_quantity,
			'total_minor'              => $total_minor,
			'has_invalid_items'        => $has_invalid,
			'minimum_order_quantity'   => $minimum,
			'minimum_order_remaining'  => $remaining,
			'checkout'                 => array(
				'allowed' => $checkout->is_allowed(),
				'code'    => $checkout->get_code(),
				'message' => $checkout->get_message(),
			),
		);
	}

	/**
	 * Returns lightweight, structured image data; markup remains a frontend concern.
	 *
	 * @return array{url:string,alt:string,width:int,height:int,available:bool}
	 */
	private function get_thumbnail( int $post_id ): array {
		$fallback = array(
			'url'       => '',
			'alt'       => '',
			'width'     => 0,
			'height'    => 0,
			'available' => false,
		);
		$attachment_id = get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id ) {
			return $fallback;
		}

		$image = wp_get_attachment_image_src( $attachment_id, 'medium' );
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
			return $fallback;
		}

		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		return array(
			'url'       => (string) $image[0],
			'alt'       => '' !== $alt ? $alt : __( 'Product image', 'cpt2collection' ),
			'width'     => max( 0, (int) ( $image[1] ?? 0 ) ),
			'height'    => max( 0, (int) ( $image[2] ?? 0 ) ),
			'available' => true,
		);
	}

	private function get_price_minor( int $post_id ): ?int {
		$value = get_post_meta( $post_id, MetaKeys::PRICE_MINOR, true );
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9]\d*$/', $value ) ) {
			return null;
		}
		$price = filter_var( $value, FILTER_VALIDATE_INT );

		return is_int( $price ) && $price > 0 ? $price : null;
	}

	private function safe_multiply( int $left, int $right ): ?int {
		return 0 !== $left && $right > intdiv( PHP_INT_MAX, $left ) ? null : $left * $right;
	}

	private function safe_add( int $left, int $right ): int {
		return $right > PHP_INT_MAX - $left ? PHP_INT_MAX : $left + $right;
	}

	private function safe_price_add( int $left, int $right ): ?int {
		return $right > PHP_INT_MAX - $left ? null : $left + $right;
	}
}
