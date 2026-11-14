<?php

/**
 * Compare tracked PHP files with a trusted Git revision after formatting.
 * Usage: php tools/verify-php-formatting.php [revision]
 */

declare( strict_types=1 );

function git_output( string ...$arguments ): string {
	$process = proc_open( array( 'git', ...$arguments ), array( 1 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Cannot start Git.' );
	}
	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	if ( 0 !== proc_close( $process ) || false === $output ) {
		throw new RuntimeException( 'Cannot read Git baseline.' );
	}
	return $output;
}

function significant_tokens( string $source ): array {
	$signature = array();
	foreach ( token_get_all( $source, TOKEN_PARSE ) as $token ) {
		if ( is_string( $token ) ) {
			$signature[] = $token;
		} elseif ( T_WHITESPACE !== $token[0] ) {
			$signature[] = array( $token[0], $token[1] );
		}
	}
	return $signature;
}

$baseline = trim( git_output( 'rev-parse', '--verify', '--end-of-options', ( $argv[1] ?? 'HEAD' ) . '^{commit}' ) );
$files = array_filter( explode( "\0", git_output( 'ls-tree', '-r', '--name-only', '-z', $baseline ) ), static fn( string $path ): bool => str_ends_with( $path, '.php' ) );
$failed = false;
foreach ( $files as $file ) {
	$current = file_get_contents( dirname( __DIR__ ) . '/' . $file );
	if ( false === $current || significant_tokens( git_output( 'show', $baseline . ':' . $file ) ) !== significant_tokens( $current ) ) {
		fwrite( STDERR, "PHP tokens other than T_WHITESPACE differ: {$file}\n" );
		$failed = true;
	}
}

if ( $failed ) {
	exit( 1 );
}
echo 'PASS: ' . count( $files ) . " PHP files preserve all tokens other than T_WHITESPACE against {$baseline}.\n";
