<?php
/** Deterministic provider used only by Milestone 7D verification. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Tests;
use RodolfoRizzo\Cpt2Collection\PaymentProviderException;
use RodolfoRizzo\Cpt2Collection\PaymentProviderInterface;
use RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest;
use RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult;
final class FakePaymentProvider implements PaymentProviderInterface {
	public string $scenario = 'success'; public int $calls = 0; public array $requests = array(); public $transaction_probe = null; public bool $called_during_transaction = false; private array $sessions = array();
	public function name(): string { return 'fake'; }
	public function create_session( PaymentProviderSessionRequest $request ): PaymentProviderSessionResult {
		++$this->calls; $this->requests[] = $request; $this->called_during_transaction = $this->called_during_transaction || ( is_callable( $this->transaction_probe ) && (bool) call_user_func( $this->transaction_probe ) );
		if ( isset( $this->sessions[ $request->idempotency_key() ] ) ) { return $this->sessions[ $request->idempotency_key() ]; }
		if ( 'rejected' === $this->scenario ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); }
		if ( 'unavailable' === $this->scenario ) { throw new PaymentProviderException( PaymentProviderException::UNAVAILABLE ); }
		$result = new PaymentProviderSessionResult( 'fake_' . $request->reference(), 'https://payments.example.test/session/' . $request->reference(), gmdate( 'Y-m-d H:i:s', strtotime( $request->reservation_deadline() . ' UTC' ) - 60 ) );
		$this->sessions[ $request->idempotency_key() ] = $result;
		if ( 'response_loss' === $this->scenario ) { $this->scenario = 'success'; throw new PaymentProviderException( PaymentProviderException::OUTCOME_UNKNOWN ); }
		if ( 'invalid_id' === $this->scenario ) { return new PaymentProviderSessionResult( '', $result->redirect_url(), $result->expires_at() ); }
		if ( 'invalid_url' === $this->scenario ) { return new PaymentProviderSessionResult( $result->provider_session_id(), 'javascript:bad', $result->expires_at() ); }
		if ( 'expired' === $this->scenario ) { return new PaymentProviderSessionResult( $result->provider_session_id(), $result->redirect_url(), '2000-01-01 00:00:00' ); }
		if ( 'too_late' === $this->scenario ) { return new PaymentProviderSessionResult( $result->provider_session_id(), $result->redirect_url(), gmdate( 'Y-m-d H:i:s', strtotime( $request->reservation_deadline() . ' UTC' ) + 60 ) ); }
		return $result;
	}
}
