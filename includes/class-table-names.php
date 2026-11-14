<?php
/**
 * Database table-name resolution.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes CPT2Collection custom table names for the current site.
 */
final class TableNames {
	public function commerce_resets(): string { return $this->wpdb->prefix . 'c2c_commerce_resets'; }
	public function provider_claims(): string { return $this->wpdb->prefix . 'c2c_provider_claims'; }
	public function provider_identities(): string { return $this->wpdb->prefix . 'c2c_provider_identities'; }
	public function order_deletions(): string { return $this->wpdb->prefix . 'c2c_order_deletions'; }
	public function commerce_control(): string { return $this->wpdb->prefix . 'c2c_commerce_control'; }
	public function commerce_epochs(): string { return $this->wpdb->prefix . 'c2c_commerce_epochs'; }
	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function carts(): string {
		return $this->wpdb->prefix . 'c2c_carts';
	}

	public function cart_items(): string {
		return $this->wpdb->prefix . 'c2c_cart_items';
	}

	public function orders(): string {
		return $this->wpdb->prefix . 'c2c_orders';
	}

	public function order_items(): string {
		return $this->wpdb->prefix . 'c2c_order_items';
	}

	public function reservations(): string {
		return $this->wpdb->prefix . 'c2c_reservations';
	}

	public function payment_sessions(): string {
		return $this->wpdb->prefix . 'c2c_payment_sessions';
	}

	public function payment_events(): string {
		return $this->wpdb->prefix . 'c2c_payment_events';
	}
}
