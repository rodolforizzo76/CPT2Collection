<?php
/** WordPress SQL API with no reconnect/replay during commerce transactions. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use Throwable;
use wpdb;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-connection.php';

final class CommerceWpdb extends wpdb implements CommerceSessionConnection {
	private ?object $transaction_owner = null;
	private bool $transaction_poisoned = false;
	private bool $session_pinned = false;
	private bool $private_update = false;

	/** A private connection, never a replacement for WordPress's global connection. */
	public static function connect( wpdb $wordpress ): self {
		$db = new self( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->set_prefix( $wordpress->prefix );
		// Preserve multisite/custom table resolution from the selected WordPress site.
		$db->posts = $wordpress->posts;
		$db->postmeta = $wordpress->postmeta;
		$db->set_charset( $db->dbh, $wordpress->charset, $wordpress->collate );
		return $db;
	}

	/** Connection-scoped locks must never survive logically onto a replacement socket. */
	public function pin_session(): bool {
		if ( ( defined( 'DB_HOST' ) && str_starts_with( strtolower( DB_HOST ), 'p:' ) ) || $this->transaction_poisoned || null !== $this->transaction_owner || ! $this->ready || ! $this->dbh instanceof \mysqli ) { return false; }
		$this->session_pinned = true;
		return true;
	}

	public function pin_transaction( object $owner ): bool {
		if ( null !== $this->transaction_owner || $this->transaction_poisoned ) { return false; }
		$this->transaction_owner = $owner;
		return true;
	}
	public function release_transaction( object $owner ): void {
		if ( $owner === $this->transaction_owner && ! $this->transaction_poisoned ) { $this->transaction_owner = null; }
	}
	public function poison_transaction(): void { $this->transaction_poisoned = true; }
	public function transaction_connection_usable(): bool { return ! $this->transaction_poisoned; }

	/** Permanent discard, unlike release after an acknowledged COMMIT/ROLLBACK.
	 * Revoke before touching the driver: close can return false or throw.
	 */
	private function discard_transaction_connection(): void {
		$this->transaction_poisoned = true;
		$this->transaction_owner = null;
		// No registry can exist before the manager class has been loaded.
		if ( class_exists( TransactionManager::class, false ) ) { TransactionManager::connection_discarded_on( $this ); }
	}
	public function close() {
		$this->discard_transaction_connection();
		return parent::close();
	}

	/** wpdb's constructor also enters here; only an unowned healthy connection may connect. */
	public function db_connect( $allow_bail = true ) {
		if ( null !== $this->transaction_owner || $this->transaction_poisoned || $this->session_pinned ) {
			$this->discard_transaction_connection();
			return false;
		}
		return parent::db_connect( $allow_bail );
	}

	/** wpdb calls this before replaying a statement on a replacement connection. */
	public function check_connection( $allow_bail = true ) {
		if ( null !== $this->transaction_owner || $this->transaction_poisoned || $this->session_pinned ) {
			$this->discard_transaction_connection();
			return false;
		}
		return parent::check_connection( $allow_bail );
	}

	/** Keep personal data out of wpdb's query and error diagnostics. */
	public function update_private_values( string $table, array $data, array $where, array $format, array $where_format ): int|false {
		$this->private_update = true;
		try {
			return $this->update( $table, $data, $where, $format, $where_format );
		} finally {
			$this->last_query = '';
			$this->func_call = '';
			if ( '' !== $this->last_error ) {
				$this->last_error = 'Commerce private update failed.';
			}
			$this->private_update = false;
		}
	}

	public function log_query( $query, $query_time, $query_callstack, $query_start, $query_data ) {
		parent::log_query( $this->private_update ? '[commerce private update]' : $query, $query_time, $query_callstack, $query_start, $query_data );
	}

	public function print_error( $str = '' ) {
		return $this->private_update ? false : parent::print_error( $str );
	}

	public function query( $query ) {
		if ( $this->transaction_poisoned ) {
			$this->last_error = 'Commerce transaction connection is unavailable.';
			return false;
		}
		try {
			$result = parent::query( $query );
			if ( false === $result && ( null !== $this->transaction_owner || $this->session_pinned ) && $this->dbh instanceof \mysqli && in_array( mysqli_errno( $this->dbh ), array( 2006, 2013, 2055 ), true ) ) {
				$this->transaction_poisoned = true;
			}
			return $result;
		} catch ( Throwable $error ) {
			// Do not permit a caller to continue after an unknown driver outcome.
			if ( null !== $this->transaction_owner || $this->session_pinned ) { $this->discard_transaction_connection(); }
			$this->last_error = 'Commerce database request failed.';
			return false;
		}
	}
}
