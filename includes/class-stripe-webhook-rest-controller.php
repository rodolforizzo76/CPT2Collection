<?php
/** Public Stripe-signature-authenticated REST ingress. @package CPT2Collection */
declare(strict_types=1); namespace RodolfoRizzo\Cpt2Collection; defined('ABSPATH')||exit;
final class StripeWebhookRestController {
 public const NAMESPACE='cpt2collection/v1', ROUTE='/stripe/webhook', MAX_PAYLOAD_BYTES=1048576;
 private StripeWebhookVerifier $verifier; private PaymentWebhookOrchestrator $orchestrator;
 public function __construct(StripeWebhookVerifier $v,PaymentWebhookOrchestrator $o, private ?\Closure $release = null){$this->verifier=$v;$this->orchestrator=$o;}
 public function register():void {register_rest_route(self::NAMESPACE,self::ROUTE,array('methods'=>'POST','callback'=>array($this,'receive'),'permission_callback'=>'__return_true'));}
 public function receive(\WP_REST_Request $request):\WP_REST_Response {
  $raw=$request->get_body();if(strlen($raw)>self::MAX_PAYLOAD_BYTES)return new \WP_REST_Response(array('code'=>'payload_too_large'),413);
  $verified=$this->verifier->verify($raw,(string)$request->get_header('stripe-signature'));
  if(!$verified->is_verified()){$status=in_array($verified->outcome(),array(StripeWebhookVerificationResult::INVALID_SIGNATURE,StripeWebhookVerificationResult::MALFORMED_PAYLOAD),true)?400:(StripeWebhookVerificationResult::INVALID_CONFIGURATION===$verified->outcome()?503:500);return new \WP_REST_Response(array('code'=>$status<500?'invalid_webhook':'webhook_unavailable'),$status);}
  $released=false;
  try {
   $result=$this->orchestrator->handle((array)$verified->event(),hash('sha256',$raw));
  } finally {
   try {
	$released=$this->release!==null && false!==($this->release)();
   } catch(\Throwable $error) {
	// Connection discard already revoked authority. Leave dispatch to the sweep.
   }
  }
  if($released && $result->paid_committed) PaymentNotificationCron::wake();
  return new \WP_REST_Response(array('code'=>$result->is_accepted()?'accepted':'delivery_failed'),$result->status());
 }
}
