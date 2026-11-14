<?php
/** Shared real-SQL adapter for cart and checkout concurrency tests. */
declare( strict_types=1 );

require_once __DIR__ . '/commerce-fixtures.php';

/** Minimal wpdb-compatible adapter: production repositories still issue their real SQL. */
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix;
	public string $posts;
	public string $postmeta;
	public string $last_error = '';
	public int $last_errno = 0;
	public int $insert_id = 0;
	public mysqli $dbh;

	public function __construct( string $prefix ) {
		global $db_host, $db_port, $db_user, $db_pass, $db_name;
		$this->prefix   = $prefix;
		$this->posts    = $prefix . 'posts';
		$this->postmeta = $prefix . 'postmeta';
		$this->dbh      = new mysqli( $db_host, $db_user, $db_pass, $db_name, $db_port );
		if ( $this->dbh->connect_errno ) { throw new RuntimeException( 'MariaDB connection failed: ' . $this->dbh->connect_error ); }
		$this->dbh->set_charset( 'utf8mb4' );
	}

	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function get_col( string $query ): ?array { $rows = $this->get_results( $query ); return null === $rows ? null : array_map( static fn($r) => array_values(get_object_vars($r))[0], $rows ); }
	public function close(): void { $this->dbh->close(); }
	private function reset_error(): void { $this->last_error = ''; $this->last_errno = 0; }
	private function failed(): false { $this->last_error = $this->dbh->error; $this->last_errno = $this->dbh->errno; return false; }
	public function prepare( string $query, mixed ...$args ): string {
		$index = 0;
		return (string) preg_replace_callback( '/%(?:%|d|s)/', function ( array $match ) use ( $args, &$index ): string {
			if ( '%%' === $match[0] ) { return '%'; }
			$value = $args[ $index++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . $this->dbh->real_escape_string( (string) $value ) . "'";
		}, $query );
	}
	public function query( string $query ): int|false {
		$this->reset_error(); $result = $this->dbh->query( $query );
		if ( false === $result ) { return $this->failed(); }
		$this->insert_id = (int) $this->dbh->insert_id;
		return true === $result ? $this->dbh->affected_rows : $result->num_rows;
	}
	public function get_results( string $query ): ?array {
		$this->reset_error(); $result = $this->dbh->query( $query );
		if ( false === $result ) { $this->failed(); return null; }
		$rows = array(); while ( $row = $result->fetch_object() ) { $rows[] = $row; } $result->free(); return $rows;
	}
	public function get_row( string $query ): ?object { $rows = $this->get_results( $query ); return null === $rows ? null : ( $rows[0] ?? null ); }
	public function get_var( string $query ): mixed { $row = $this->get_row( $query ); if ( null === $row ) { return null; } $values = get_object_vars( $row ); return reset( $values ); }
	public function insert( string $table, array $data, array $formats ): int|false {
		$columns = array(); $values = array(); $index = 0;
		foreach ( $data as $column => $value ) { $columns[] = '`' . str_replace( '`', '``', $column ) . '`'; $format = $formats[ $index++ ] ?? '%s'; $values[] = null === $value || null === $format ? 'NULL' : $this->prepare( $format, $value ); }
		return $this->query( 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
	}
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
		$set = array(); $conditions = array(); $index = 0;
		foreach ( $data as $column => $value ) { $format = $formats[ $index++ ] ?? '%s'; $set[] = '`' . $column . '`=' . ( null === $value ? 'NULL' : $this->prepare( $format, $value ) ); }
		// Match wpdb NULL predicates used by immutable event-association CAS.
		$index = 0; foreach ( $where as $column => $value ) { $format = $where_formats[ $index++ ] ?? '%s'; $conditions[] = '`' . $column . '`' . ( null === $value ? ' IS NULL' : '=' . $this->prepare( $format, $value ) ); }
		return $this->query( 'UPDATE `' . str_replace( '`', '``', $table ) . '` SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
	}
	public function delete( string $table, array $where, array $formats ): int|false {
		$conditions = array(); $index = 0;
		foreach ( $where as $column => $value ) { $conditions[] = '`' . $column . '`=' . $this->prepare( $formats[$index++], $value ); }
		return $this->query( 'DELETE FROM `' . $table . '` WHERE ' . implode( ' AND ', $conditions ) );
	}

}
