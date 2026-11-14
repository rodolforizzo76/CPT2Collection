<?php
/**
 * Shared cart activity touch policy.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the shared cart activity policy for reads and mutations.
 */
final class CartActivity {
	public const TOUCH_THROTTLE_SECONDS = 5 * MINUTE_IN_SECONDS;

	private CartRepository $repository;
	private UtcClock $clock;

	public function __construct( CartRepository $repository, UtcClock $clock ) {
		$this->repository = $repository;
		$this->clock      = $clock;
	}

	/**
	 * Touches a cart only when the shared throttle window has elapsed.
	 *
	 * @return CartRecord|false
	 */
	public function maybe_touch( CartRecord $cart, ?int $now = null ) {
		if(!$this->repository->is_current($cart)){return false;}
		$now           = null === $now ? $this->clock->now_timestamp() : $now;
		$last_activity = $this->clock->mysql_to_timestamp( $cart->last_activity_at() );

		if ( $last_activity > 0 && ( $now - $last_activity ) < self::TOUCH_THROTTLE_SECONDS ) {
			return $cart;
		}

		$transactions = $this->repository->transactions();
		if ( ! $transactions->supports_cart_mutation() || ! $transactions->begin_commerce_shared() ) { return false; }
		try {
			$fresh = $this->repository->find_for_update( $cart->id() );
			if ( $cart->epoch_id() !== $transactions->commerce_context()->epoch_id() || ! $fresh instanceof CartRecord || $fresh->expires_at() <= $this->clock->format_mysql( $now ) ) { $transactions->rollback(); return false; }
			$last = $this->clock->mysql_to_timestamp( $fresh->last_activity_at() );
			if ( $last <= 0 || ( $now - $last ) >= self::TOUCH_THROTTLE_SECONDS ) {
				$fresh = $this->repository->touch( $fresh->id(), $this->clock->format_mysql( $now ), $this->clock->format_mysql( $now ), $this->clock->plus_seconds_mysql( CartCookie::LIFETIME_SECONDS, $now ) );
				if ( false === $fresh ) { $transactions->rollback(); return false; }
			}
			return $transactions->commit() ? $fresh : false;
		} catch ( \Throwable $error ) { $transactions->rollback(); return false; }

	}

	/** @return array{updated_at:string,last_activity_at:?string,expires_at:?string} */
	public function mutation_timestamps( CartRecord $cart, ?int $now = null ): array {
		$now = null === $now ? $this->clock->now_timestamp() : $now;
		$at  = $this->clock->format_mysql( $now );
		$last_activity = $this->clock->mysql_to_timestamp( $cart->last_activity_at() );
		$due = $last_activity <= 0 || ( $now - $last_activity ) >= self::TOUCH_THROTTLE_SECONDS;
		return array(
			'updated_at'       => $at,
			'last_activity_at' => $due ? $at : null,
			'expires_at'       => $due ? $this->clock->plus_seconds_mysql( CartCookie::LIFETIME_SECONDS, $now ) : null,
		);
	}
}
