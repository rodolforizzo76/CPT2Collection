<?php
/** Testable boundary around the Stripe PHP SDK. @package CPT2Collection */
declare( strict_types=1 ); namespace RodolfoRizzo\Cpt2Collection; defined( 'ABSPATH' ) || exit;
interface StripeCheckoutClientInterface { /** @return array<string,mixed> */ public function create_checkout_session( array $parameters, array $options ): array; }
