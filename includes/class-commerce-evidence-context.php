<?php
/** Historical attribution only; never operational or maintenance authority. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-reset-record.php';
require_once __DIR__ . '/class-provider-claim-record.php';
final class CommerceEvidenceContext {
	public function __construct( private CommerceResetRecord $operation, private ProviderClaimRecord $claim ) {}
	public function operation(): CommerceResetRecord { return $this->operation; }
	public function claim(): ProviderClaimRecord { return $this->claim; }
	public function equals( self $other ): bool { return $this->operation->equals( $other->operation ) && $this->claim->equals( $other->claim ); }
}
