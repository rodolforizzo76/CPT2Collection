<?php
/**
 * Post type selection helpers.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use WP_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the configured and eligible collection post types.
 */
final class PostTypes {
	public const OPTION_POST_TYPE = 'cpt2collection_post_type';

	/**
	 * Gets the selected post type if it is currently eligible.
	 */
	public function get_selected_post_type(): string {
		$post_type = (string) get_option( self::OPTION_POST_TYPE, '' );

		return $this->is_eligible( $post_type ) ? $post_type : '';
	}

	/**
	 * Gets eligible custom post types after WordPress post types are registered.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	public function get_eligible_post_types(): array {
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$eligible   = array();

		foreach ( $post_types as $slug => $post_type ) {
			if ( ! $post_type instanceof WP_Post_Type ) {
				continue;
			}

			if ( $post_type->_builtin ) {
				continue;
			}

			if ( ! $this->is_frontend_collection_post_type( $post_type ) ) {
				continue;
			}

			$eligible[ $slug ] = $post_type;
		}

		/**
		 * Filters custom post types eligible for CPT2Collection management.
		 *
		 * @param array<string,WP_Post_Type> $eligible Eligible post type objects keyed by slug.
		 */
		$eligible = apply_filters( 'cpt2collection_eligible_post_types', $eligible );

		return is_array( $eligible ) ? $eligible : array();
	}

	/**
	 * Checks whether a post type is eligible.
	 */
	public function is_eligible( string $post_type ): bool {
		if ( '' === $post_type ) {
			return false;
		}

		$eligible = $this->get_eligible_post_types();

		return isset( $eligible[ $post_type ] );
	}

	/**
	 * Checks whether a post type looks suitable for frontend collection content.
	 */
	private function is_frontend_collection_post_type( WP_Post_Type $post_type ): bool {
		if ( $post_type->_builtin || true !== $post_type->show_ui || true !== $post_type->public ) {
			return false;
		}

		$internal_post_types = array(
			'acf-field',
			'acf-field-group',
			'acf-post-type',
			'acf-taxonomy',
			'acf-ui-options-page',
			'bricks_font',
			'bricks_template',
		);

		if ( in_array( $post_type->name, $internal_post_types, true ) ) {
			return false;
		}

		return true;
	}
}
