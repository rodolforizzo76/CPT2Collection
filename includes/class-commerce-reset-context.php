<?php
/** Immutable maintenance snapshot, never normal commerce authority. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-reset-record.php';

final class CommerceResetContext {
	public function __construct( private CommerceResetRecord $operation ) {}
	public function operation(): CommerceResetRecord { return $this->operation; }
	public function equals( self $other ): bool { return $this->operation->equals( $other->operation ); }
}
