<?php
/**
 * Lazy cart context.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates explicit cart retrieval/creation without doing work during bootstrap.
 */
final class CartContext {
	public const MAX_TOKEN_CREATE_ATTEMPTS = 3;

	private CartRepository $repository;
	private CartToken $tokens;
	private CartCookie $cookie;
	private UtcClock $clock;
	private CartActivity $activity;

	public function __construct( CartRepository $repository, CartToken $tokens, CartCookie $cookie, UtcClock $clock, CartActivity $activity ) {
		$this->repository = $repository;
		$this->tokens     = $tokens;
		$this->cookie     = $cookie;
		$this->clock      = $clock;
		$this->activity   = $activity;
	}

	/**
	 * Gets the current cart or creates one only when this method is explicitly called.
	 *
	 * @return CartRecord|false False indicates database creation or cookie persistence failed.
	 */
	public function get_or_create_cart() {
		$now       = $this->clock->now_timestamp();
		$cart      = $this->find_existing_cart( $now );

		if ( null !== $cart ) {
			return $this->activity->maybe_touch( $cart, $now );
		}

		return $this->create_with_new_token( $now );
	}

	/**
	 * Gets an active cart without creating records, tokens, cookies, or activity writes.
	 */
	public function get_existing_cart(): ?CartRecord {
		return $this->find_existing_cart( $this->clock->now_timestamp() );
	}

	private function find_existing_cart( int $now ): ?CartRecord {
		$raw_token = $this->cookie->read();
		if ( null === $raw_token ) {
			return null;
		}

		return $this->repository->find_active_by_token_hash(
			$this->tokens->hash( $raw_token ),
			$this->clock->format_mysql( $now )
		);
	}

	/**
	 * Generates a fresh token for absent, malformed, stale, or collided carts.
	 *
	 * A bounded retry protects against the practically impossible SHA-256 token-hash collision
	 * while relying on the database unique index as the final concurrency safeguard.
	 *
	 * @return CartRecord|false
	 */
	private function create_with_new_token( int $now ) {
		for ( $attempt = 1; $attempt <= self::MAX_TOKEN_CREATE_ATTEMPTS; $attempt++ ) {
			$raw_token  = $this->tokens->generate();
			$token_hash = $this->tokens->hash( $raw_token );
			$expires_ts = $now + CartCookie::LIFETIME_SECONDS;
			$now_mysql  = $this->clock->format_mysql( $now );
			$transactions = $this->repository->transactions();
			if ( ! $transactions->supports_cart_mutation() || ! $transactions->begin_commerce_shared() ) { return false; }
			try {
			$cart       = $this->repository->create(
				$token_hash,
				$now_mysql,
				$now_mysql,
				$now_mysql,
				$this->clock->format_mysql( $expires_ts )
			);

			if ( false === $cart ) {
				$errno = $transactions->last_error_number();
				if ( ! $transactions->rollback() || 1062 !== $errno ) { return false; }
				continue;
			}
			if ( ! $transactions->commit() ) { return false; }
			} catch ( \Throwable $error ) { $transactions->rollback(); return false; }

			if ( ! $this->cookie->write( $raw_token, $expires_ts ) ) {
				return false;
			}

			return $cart;
		}

		return false;
	}
}
