<?php
/** Caller-authenticated/correlated input DATA. Factories do not authenticate or grant authority. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ProviderDiscoveryCandidate {
	private function __construct(
		public readonly string $provider, public readonly string $environment,
		public readonly ?ProviderClaimLocator $locator, public readonly array $keys,
		public readonly ?string $installation, public readonly ?string $epoch_identity,
		public readonly ?int $epoch_id, public readonly ?ProviderClaimRecord $captured_claim,
		public readonly ?string $event_hash, public readonly ?string $event_type,
		public readonly string $observed_at, public readonly string $scope
	) {}
	/** Internal caller must have correlated these facts; not a public HTTP API. */
	public static function correlated( string $provider, string $environment, ?ProviderClaimLocator $locator, array $keys, string $observed_at, ?string $installation=null, ?string $epoch_identity=null, ?int $epoch_id=null, ?ProviderClaimRecord $captured=null ): self {
		self::validate($provider,$environment,$keys,$observed_at);
		if(isset($keys['event'])) throw new \InvalidArgumentException('Event candidates require a verified receipt.');
		if(($installation===null)!==($epoch_identity===null)||($installation!==null&&(!CommerceBaseline::identity($installation)||!CommerceBaseline::identity($epoch_identity)))||($epoch_id!==null&&($epoch_id<1||$installation===null))) throw new \InvalidArgumentException('Invalid captured scope.');
		return new self($provider,$environment,$locator,$keys,$installation,$epoch_identity,$epoch_id,$captured,null,null,$observed_at,'managed');
	}
	public static function response(ProviderClaimRecord $claim,string $session,?string $payment,string $observed_at):self {
		$keys=['session'=>$session];if($payment!==null)$keys['payment']=$payment;
		return self::correlated($claim->provider(),$claim->environment(),ProviderClaimLocator::reference($claim->reference()),$keys,$observed_at,$claim->installation_id(),$claim->epoch_identity(),$claim->epoch_id(),$claim);
	}
	/** Precondition: existing signature verification AND normalization succeeded. */
	public static function verified_receipt(PaymentEventReceipt $receipt,string $observed_at):self {
		$p=json_decode($receipt->normalized_payload()??'',true);
		$keys=['event'=>$receipt->provider_event_id()];
		if($receipt->provider_session_id()!==null)$keys['session']=$receipt->provider_session_id();
		if($receipt->provider_payment_id()!==null)$keys['payment']=$receipt->provider_payment_id();
		$env=$receipt->livemode()?'live':'test';self::validate($receipt->provider(),$env,$keys,$observed_at);
		$scope=is_array($p)&&($p['integration']??null)==='cpt2collection'&&!($p['identity_invalid']??false)&&CommerceBaseline::identity($p['installation_id']??null)&&CommerceBaseline::identity($p['epoch_identity']??null)?'managed':'unsupported';
		return new self($receipt->provider(),$env,$receipt->local_reference()===null?null:ProviderClaimLocator::reference($receipt->local_reference()),$keys,'managed'===$scope?$p['installation_id']:null,'managed'===$scope?$p['epoch_identity']:null,null,null,$receipt->payload_sha256(),$receipt->event_type(),$observed_at,$scope);
	}
	/** D1 DATA: capture the original native request along with an authenticated receipt.
	 * Contradictions are validated by CommittedOperation and independently by discovery.
	 */
	public static function committed_receipt(ProviderClaimRecord $claim,PaymentEventReceipt $receipt,string $observed_at):self {
		$i=self::verified_receipt($receipt,$observed_at);
		return new self($i->provider,$i->environment,ProviderClaimLocator::id($claim->id()),$i->keys,$i->installation,$i->epoch_identity,$claim->epoch_id(),$claim,$i->event_hash,$i->event_type,$i->observed_at,$i->scope);
	}
	private static function validate(string $provider,string $env,array $keys,string $at):void {
		if(!preg_match('/\A[a-z0-9_]{1,32}\z/',$provider)||!in_array($env,['test','live'],true)||!ProviderClaimRecord::valid_date($at)||count($keys)>3) throw new \InvalidArgumentException('Invalid provider discovery input.');
		foreach($keys as $kind=>$value)if(!in_array($kind,['session','payment','event'],true)||!is_string($value)||!preg_match('/\A[\x21-\x7e]{1,255}\z/D',$value)) throw new \InvalidArgumentException('Invalid provider identity input.');
		// Event facts require the verified normalized receipt factory.
		if(isset($keys['event'])&&!preg_match('/\A[a-zA-Z0-9_.]{1,255}\z/',$keys['event'])) throw new \InvalidArgumentException('Invalid event key.');
	}
}
