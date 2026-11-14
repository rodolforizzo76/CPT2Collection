<?php
/** Minimal hosted payment-session provider contract. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
interface PaymentProviderInterface {
	public function name(): string;
	public function create_session( PaymentProviderSessionRequest $request ): PaymentProviderSessionResult;
}
