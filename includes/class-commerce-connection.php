<?php
/** Transaction connection contract. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

interface CommerceConnection {
	/** Pin before BEGIN, including the interval in which its response is uncertain. */
	public function pin_transaction( object $owner ): bool;
	/** Only a confirmed COMMIT/ROLLBACK may release ownership. */
	public function release_transaction( object $owner ): void;
	public function poison_transaction(): void;
	public function transaction_connection_usable(): bool;
	// Implementations that close/discard or replace a physical connection must revoke
	// its manager via TransactionManager::connection_discarded_on() and permanently
	// deny reuse. This differs from release_transaction(); discard confirms no outcome.
}

/** Optional lifetime session fence for callers holding connection-scoped locks. */
interface CommerceSessionConnection extends CommerceConnection {
	/** Permanently deny reconnect/replacement until close, even outside transactions. */
	public function pin_session(): bool;
}
