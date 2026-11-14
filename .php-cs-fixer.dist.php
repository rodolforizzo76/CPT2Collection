<?php

$finder = PhpCsFixer\Finder::create()
	->in( array( __DIR__ . '/includes', __DIR__ . '/tests', __DIR__ . '/cpt2collection-7b-harness' ) )
	->append( array( __DIR__ . '/cpt2collection.php', __DIR__ . '/uninstall.php', __DIR__ . '/scoper.inc.php' ) )
	->notPath( 'class-reservation-repository.php' );

return ( new PhpCsFixer\Config() )
	->setFinder( $finder )
	->setIndent( "\t" )
	->setUsingCache( false )
	->setRiskyAllowed( false )
	->setRules( array(
		'indentation_type' => true,
		'no_trailing_whitespace' => true,
		'no_whitespace_in_blank_line' => true,
		'single_blank_line_at_eof' => true,
	) );
