<?php
/**
 * Cart persistence record.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a persisted cart row.
 */
final class CartRecord {
	private ?int $epoch_id = null;
	public function epoch_id(): ?int { return $this->epoch_id; }

	private int $id;
	private string $token_hash;
	private string $created_at;
	private string $updated_at;
	private string $last_activity_at;
	private string $expires_at;
	private int $content_revision;
	private int $checkout_generation;

	public function __construct( int $id, string $token_hash, string $created_at, string $updated_at, string $last_activity_at, string $expires_at, int $content_revision = 0, int $checkout_generation = 0, ?int $epoch_id = null ) {
		if ( $id <= 0 || $content_revision < 0 || $checkout_generation < 0 ) {
			throw new InvalidArgumentException( 'Cart identity values are invalid.' );
		}
		$this->epoch_id = $epoch_id;
		$this->id               = $id;
		$this->token_hash       = $token_hash;
		$this->created_at       = $created_at;
		$this->updated_at       = $updated_at;
		$this->last_activity_at = $last_activity_at;
		$this->expires_at       = $expires_at;
		$this->content_revision = $content_revision;
		$this->checkout_generation = $checkout_generation;
	}

	/**
	 * @param object{id:mixed,token_hash:mixed,created_at:mixed,updated_at:mixed,last_activity_at:mixed,expires_at:mixed,content_revision?:mixed,checkout_generation?:mixed} $row Database row.
	 */
	public static function from_row( object $row ): self {
		if ( ! property_exists( $row, 'content_revision' ) || ! property_exists( $row, 'checkout_generation' ) ) {
			throw new InvalidArgumentException( 'Cart checkout identity is missing.' );
		}
		$revision   = self::persisted_nonnegative_integer( $row->content_revision, 'content_revision' );
		$generation = self::persisted_nonnegative_integer( $row->checkout_generation, 'checkout_generation' );
		$record = new self(
			(int) $row->id,
			(string) $row->token_hash,
			(string) $row->created_at,
			(string) $row->updated_at,
			(string) $row->last_activity_at,
			(string) $row->expires_at,
			$revision,
			$generation
		);
		$record->epoch_id = isset( $row->epoch_id ) ? (int) $row->epoch_id : null;
		return $record;
	}

	private static function persisted_nonnegative_integer( mixed $value, string $name ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9]\d*)\z/', $value ) ) {
			$parsed = filter_var( $value, FILTER_VALIDATE_INT );
			if ( is_int( $parsed ) && $parsed >= 0 ) {
				return $parsed;
			}
		}
		throw new InvalidArgumentException( 'Cart ' . $name . ' is invalid.' );
	}

	public function id(): int { return $this->id; }
	public function token_hash(): string { return $this->token_hash; }
	public function created_at(): string { return $this->created_at; }
	public function updated_at(): string { return $this->updated_at; }
	public function last_activity_at(): string { return $this->last_activity_at; }
	public function expires_at(): string { return $this->expires_at; }
	public function content_revision(): int { return $this->content_revision; }
	public function checkout_generation(): int { return $this->checkout_generation; }
}
