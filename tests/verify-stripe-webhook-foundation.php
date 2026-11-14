<?php
/** Executable Milestone 7F-D1 Stripe webhook foundation verification. */
declare( strict_types=1 );
namespace Stripe\Exception { class SignatureVerificationException extends \Exception {} }
namespace Stripe {
	final class Event { public function __construct(private array $data){} public function toArray():array{return $this->data;} }
	final class Webhook {
		public static function constructEvent(string $payload,string $header,string $secret,int $tolerance):Event {
			if(!preg_match('/(?:^|,)t=(\d+)(?:,|$)/',$header,$tm)||!preg_match('/(?:^|,)v1=([0-9a-f]+)(?:,|$)/',$header,$sm))throw new Exception\SignatureVerificationException('signature header '. $header);
			if(abs(time()-(int)$tm[1])>$tolerance)throw new Exception\SignatureVerificationException('timestamp');
			$expected=hash_hmac('sha256',$tm[1].'.'.$payload,$secret);if(!hash_equals($expected,$sm[1]))throw new Exception\SignatureVerificationException('secret '.$secret.' payload '.$payload);
			$data=json_decode($payload,true);if(!is_array($data))throw new \UnexpectedValueException('payload '.$payload);return new Event($data);
		}
	}
}
namespace {
define('ABSPATH',__DIR__); $GLOBALS['c2c_options']=array();
function get_option(string $name,mixed $default=false):mixed{return $GLOBALS['c2c_options'][$name]??$default;} function apply_filters(string $name,mixed $value):mixed{return $value;} function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}
function __(string $message,string $domain):string{return $message;}
class wpdb {}
foreach(array('table-names','money','payment-event-statuses','payment-event-record','payment-event-receipt','payment-event-insert-result','payment-event-receipt-result','payment-event-association-result','payment-event-attention-result','payment-event-repository','payment-session-statuses','stripe-settings','stripe-webhook-verification-result','stripe-webhook-verifier','stripe-event-normalization-result','stripe-event-normalizer','webhook-outcome-categories','sale-conversion-result-classifier')as$class)require_once dirname(__DIR__).'/includes/class-'.$class.'.php';
use RodolfoRizzo\Cpt2Collection\{PaymentEventAssociationResult,PaymentEventAttentionResult,PaymentEventReceiptResult,PaymentEventRepository,PaymentEventStatuses,SaleConversionResultClassifier,StripeEventNormalizationResult,StripeEventNormalizer,StripeSettings,StripeWebhookVerificationResult,StripeWebhookVerifier,TableNames,WebhookOutcomeCategories};
function check(bool $ok,string $label):void{if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
function stripe_event(string $type='checkout.session.completed',array $changes=array()):array{$ref=str_repeat('a',32);$object=array('object'=>'checkout.session','id'=>'cs_test_1','mode'=>'payment','client_reference_id'=>$ref,'metadata'=>array('c2c_payment_session_reference'=>$ref,'customer_email'=>'private@example.test'),'payment_intent'=>'pi_1','currency'=>'eur','amount_total'=>1200,'payment_status'=>'paid','status'=>'complete','expires_at'=>time()+600,'customer_details'=>array('email'=>'private@example.test'));return array('id'=>'evt_1','type'=>$type,'created'=>time(),'livemode'=>false,'data'=>array('object'=>array_replace_recursive($object,$changes)));}

// Exhaustive current SaleConversionService result taxonomy and unknown-code fail-safe.
$expected=array('sale_converted'=>WebhookOutcomeCategories::SUCCESS,'sale_already_converted'=>WebhookOutcomeCategories::SUCCESS,'order_deleted'=>WebhookOutcomeCategories::SUCCESS,'event_ignored'=>WebhookOutcomeCategories::SUCCESS,'database_error'=>WebhookOutcomeCategories::RETRYABLE,'transaction_required'=>WebhookOutcomeCategories::RETRYABLE,'transaction_outcome_unknown'=>WebhookOutcomeCategories::OUTCOME_UNKNOWN);
// Infrastructure capability failures must remain retryable without domain terminalization.
$expected['transactions_not_supported']=WebhookOutcomeCategories::RETRYABLE;
foreach(['commerce_reset_fenced','expiration_already_processed'] as $code)$expected[$code]=WebhookOutcomeCategories::SUCCESS;
foreach(['continuation_verification_required','processed_state_incoherent','transaction_already_active'] as $code)$expected[$code]=WebhookOutcomeCategories::RETRYABLE;
foreach(array('payment_association_mismatch','product_invalid','order_not_found','payment_session_not_found','payment_event_not_found','event_already_processing','event_requires_attention','event_state_conflict','inconsistent_conversion_state','order_terminal_conflict','session_state_conflict','payment_identity_conflict','payment_currency_mismatch','payment_amount_mismatch','reservation_missing','reservation_structural_mismatch','reservation_released','reservation_already_converted','product_missing','stock_invalid','insufficient_stock')as$code)$expected[$code]=WebhookOutcomeCategories::DETERMINISTIC_ATTENTION;
foreach($expected as $code=>$category)check(SaleConversionResultClassifier::classify($code)===$category,"classifier {$code}");check(SaleConversionResultClassifier::classify('future_new_code')===WebhookOutcomeCategories::RETRYABLE,'unknown result code fails safely as retryable');check(array_diff_key($expected,SaleConversionResultClassifier::known_codes())===array()&&array_diff_key(SaleConversionResultClassifier::known_codes(),$expected)===array(),'classifier known-code table is exhaustive');

// Exact raw payload verification without diagnostic leakage.
$secret='whsec_test_foundation';$payload=json_encode(stripe_event(),JSON_UNESCAPED_SLASHES);$timestamp=time();$signature='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$payload,$secret);$verifier=new StripeWebhookVerifier($secret);
check($verifier->verify($payload,$signature)->is_verified(),'valid Stripe signature');check(StripeWebhookVerificationResult::INVALID_SIGNATURE===$verifier->verify($payload,'')->outcome(),'missing signature');check(StripeWebhookVerificationResult::INVALID_SIGNATURE===$verifier->verify($payload,$signature.'0')->outcome(),'invalid signature');check(StripeWebhookVerificationResult::INVALID_SIGNATURE===(new StripeWebhookVerifier('whsec_wrong'))->verify($payload,$signature)->outcome(),'wrong webhook secret');$old=time()-1000;$old_signature='t='.$old.',v1='.hash_hmac('sha256',$old.'.'.$payload,$secret);check(StripeWebhookVerificationResult::INVALID_SIGNATURE===$verifier->verify($payload,$old_signature)->outcome(),'timestamp outside tolerance');check(StripeWebhookVerificationResult::INVALID_SIGNATURE===$verifier->verify($payload." ",$signature)->outcome(),'signature requires exact raw body');check(StripeWebhookVerificationResult::INVALID_CONFIGURATION===(new StripeWebhookVerifier('sk_test_not_webhook'))->verify($payload,$signature)->outcome(),'API key cannot be used as webhook secret');$bad='{';$bad_sig='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$bad,$secret);$verification=$verifier->verify($bad,$bad_sig);check(StripeWebhookVerificationResult::MALFORMED_PAYLOAD===$verification->outcome()&&!str_contains(json_encode($verification->event()),$secret),'typed verifier failure leaks no secret, signature, or payload');
$GLOBALS['c2c_options'][StripeSettings::OPTION_WEBHOOK_SECRET]=$secret;check(StripeSettings::webhook_secret()===$secret&&StripeSettings::sanitize_webhook_secret('')===$secret,'empty secret submission preserves configured secret');check(StripeSettings::sanitize_webhook_secret('sk_test_wrong')===$secret,'invalid webhook secret is not substituted');

// Pure normalization.
$normalizer=new StripeEventNormalizer();$hash=hash('sha256',$payload);$paid=$normalizer->normalize(stripe_event(),$hash);check($paid->outcome()===StripeEventNormalizationResult::PAYMENT_SUCCESS&&$paid->receipt()?->currency()==='EUR'&&$paid->receipt()?->amount_minor()===1200,'paid completed event normalized');
$pending=$normalizer->normalize(stripe_event('checkout.session.completed',array('payment_status'=>'unpaid','payment_intent'=>null)),$hash);check($pending->outcome()===StripeEventNormalizationResult::LIFECYCLE,'unpaid completed event is lifecycle-only');
$async=$normalizer->normalize(stripe_event('checkout.session.async_payment_succeeded'),$hash);check($async->outcome()===StripeEventNormalizationResult::PAYMENT_SUCCESS,'async payment success normalized');
$expired=$normalizer->normalize(stripe_event('checkout.session.expired',array('payment_status'=>'unpaid','payment_intent'=>null)),$hash);check($expired->outcome()===StripeEventNormalizationResult::EXPIRATION,'expired event is lifecycle expiration');
$unsupported=$normalizer->normalize(stripe_event('charge.succeeded'),$hash);check($unsupported->outcome()===StripeEventNormalizationResult::UNSUPPORTED,'unsupported event is normalized for early classification');
$missing_id=stripe_event();unset($missing_id['id']);check($normalizer->normalize($missing_id,$hash)->outcome()===StripeEventNormalizationResult::INVALID_EVENT,'missing provider event id rejected without synthetic id');
foreach(array('session'=>array('id'=>null),'payment'=>array('payment_intent'=>null),'amount'=>array('amount_total'=>-1),'currency'=>array('currency'=>'zzz'))as$name=>$change){$r=$normalizer->normalize(stripe_event('checkout.session.async_payment_succeeded',$change),$hash);check($r->outcome()===StripeEventNormalizationResult::DETERMINISTIC_ATTENTION,"malformed {$name} needs deterministic attention");}
$conflict=$normalizer->normalize(stripe_event('checkout.session.completed',array('metadata'=>array('c2c_payment_session_reference'=>str_repeat('b',32)))),$hash);check($conflict->outcome()===StripeEventNormalizationResult::DETERMINISTIC_ATTENTION&&null===$conflict->receipt()?->local_reference(),'reference disagreement retained but not selected');$minimal=(string)$paid->receipt()?->normalized_payload();check(str_contains($minimal,'client_reference_id')&&!str_contains($minimal,'private@example.test')&&!str_contains($minimal,'customer_details'),'normalized payload is minimal and excludes PII');

// Native receipt, immutable duplicate, association and attention-CAS assertions
// moved to verify-commerce-modern-receipts.php on actual MariaDB and scoped claims.
echo "Pure Stripe signature/normalization/classification checks passed.\n";
}
