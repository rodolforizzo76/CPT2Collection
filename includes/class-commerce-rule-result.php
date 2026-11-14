<?php
/**
 * Commerce rule result value object.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result returned by commerce decisions.
 */
final class CommerceRuleResult {
	private bool $allowed;
	private string $code;
	private string $message;

	private function __construct( bool $allowed, string $code, string $message ) {
		$this->allowed = $allowed;
		$this->code    = $code;
		$this->message = $message;
	}

	public static function allowed( string $code, string $message ): self {
		return new self( true, $code, $message );
	}

	public static function denied( string $code, string $message ): self {
		return new self( false, $code, $message );
	}

	public function is_allowed(): bool { return $this->allowed; }
	public function get_code(): string { return $this->code; }
	public function get_message(): string { return $this->message; }
}
