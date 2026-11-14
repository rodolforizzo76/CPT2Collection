<?php
/**
 * Administration notices.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Displays user-scoped validation notices after redirects.
 */
final class AdminNotices {
	private const USER_META_KEY = '_cpt2collection_admin_notices';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'display' ) );
	}

	public static function add_notice( string $message, string $type = 'error', int $post_id = 0, string $post_type = '' ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$notices   = get_user_meta( $user_id, self::USER_META_KEY, true );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message'   => $message,
			'type'      => in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'error',
			'post_id'   => max( 0, $post_id ),
			'post_type' => sanitize_key( $post_type ),
		);

		update_user_meta( $user_id, self::USER_META_KEY, $notices );
	}

	public function display(): void {
		if ( ! $this->is_post_type_list_screen() ) {
			return;
		}

		$notices = $this->get_current_user_notices();
		if ( array() === $notices ) {
			return;
		}

		$remaining = array();

		foreach ( $notices as $notice ) {
			if ( ! $this->is_valid_notice( $notice ) ) {
				continue;
			}

			if ( ! $this->is_relevant_list_screen_notice( $notice ) ) {
				$remaining[] = $notice;
				continue;
			}

			$type = sanitize_html_class( (string) $notice['type'] );

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( (string) $notice['message'] )
			);
		}

		$this->save_current_user_notices( $remaining );
	}

	private function is_post_type_list_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return isset( $screen->base ) && 'edit' === $screen->base;
	}

	/**
	 * Checks whether a stored notice belongs on the current post-type list screen.
	 *
	 * @param array<string,mixed> $notice Stored notice data.
	 */
	private function is_relevant_list_screen_notice( array $notice ): bool {
		$notice_post_type = isset( $notice['post_type'] ) ? sanitize_key( (string) $notice['post_type'] ) : '';
		if ( '' === $notice_post_type ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! isset( $screen->base, $screen->post_type ) || 'edit' !== $screen->base ) {
			return false;
		}

		return $notice_post_type === sanitize_key( (string) $screen->post_type );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_current_user_notices(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$notices = get_user_meta( $user_id, self::USER_META_KEY, true );

		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * @param array<int,array<string,mixed>> $notices Notices to keep queued.
	 */
	private function save_current_user_notices( array $notices ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		if ( array() === $notices ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
			return;
		}

		update_user_meta( $user_id, self::USER_META_KEY, $notices );
	}

	/**
	 * @param mixed $notice Stored notice data.
	 */
	private function is_valid_notice( $notice ): bool {
		return is_array( $notice ) && ! empty( $notice['message'] );
	}
}
