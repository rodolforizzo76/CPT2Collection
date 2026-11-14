<?php
/** Strict identity association with global order -> session -> event locking. @package CPT2Collection */
declare(strict_types=1); namespace RodolfoRizzo\Cpt2Collection; defined('ABSPATH')||exit;
final class PaymentEventAssociationService {
	private PaymentEventContinuation $continuation;
	private OrderRepository $orders; private PaymentSessionRepository $sessions; private PaymentEventRepository $events; private TransactionManager $tx; private UtcClock $clock; private bool $livemode; private ?OrderDeletionRegistry $deletions;
	public function __construct(OrderRepository $o,PaymentSessionRepository $s,PaymentEventRepository $e,TransactionManager $t,UtcClock $c,bool $livemode,?OrderDeletionRegistry $deletions=null,?PaymentEventContinuation $continuation=null){$this->orders=$o;$this->sessions=$s;$this->events=$e;$this->tx=$t;$this->clock=$c;$this->livemode=$livemode;$this->deletions=$deletions;$this->continuation=$continuation??$e->continuation();}
	/** Fresh-request read used only by the processed-delivery coherence probe. */
	public function session_for_probe(int $id){return $this->sessions->find($id);}
	public function associate(PaymentEventRecord $event,?CommittedOperation $operation=null):WebhookAssociationResult {
		$stage=null;
		try {
		if($event->livemode()!==$this->livemode){return WebhookAssociationResult::result(WebhookAssociationResult::IDENTITY_CONFLICT);}
		$admission=$this->continuation->begin($operation,$event,$this->tx);
		if($admission->status!=='admitted')return WebhookAssociationResult::result(PaymentEventContinuation::disposition($admission));
		if(!$this->tx->supports_payment_event_processing())return $this->rollback_result(WebhookAssociationResult::DATABASE_FAILURE);
		$registry=$this->events->registry();$context=$this->tx->commerce_context();$route=$registry->route($event,$context);
		if('active'!==$route['status']){
			return $this->rollback_result('database_error'===$route['status']?WebhookAssociationResult::DATABASE_FAILURE:('unresolved'===$route['status']?WebhookAssociationResult::UNMATCHED:WebhookAssociationResult::IDENTITY_CONFLICT));
		}
		$claim=$route['claim'];
		if((int)$claim->id!==$operation->claim->id())return $this->rollback_result(WebhookAssociationResult::IDENTITY_CONFLICT);
		$order=$this->orders->find_for_update((int)$claim->order_id);
		$session=$order instanceof OrderRecord?$this->sessions->find_for_update((int)$claim->payment_session_id):false;
		$locked=$session instanceof PaymentSessionRecord?$this->events->find_for_update($event->id()):false;
		if(!$order instanceof OrderRecord||!$session instanceof PaymentSessionRecord||!$locked instanceof PaymentEventRecord){return $this->rollback_result(WebhookAssociationResult::DATABASE_FAILURE);}
		if(!$this->continuation->validate($operation,$event,$order,$session,$locked)||!in_array($locked->processing_status(),[PaymentEventStatuses::RECEIVED,PaymentEventStatuses::RETRYABLE_FAILED],true)||$locked->processing_status()!==$event->processing_status()||$locked->attempt_count()!==$event->attempt_count()||$locked->last_attempt_at()!==$event->last_attempt_at())return $this->rollback_result(WebhookAssociationResult::IDENTITY_CONFLICT);
		if(!$registry->matches($claim,$session,$context)||$session->order_id()!==$order->id()||'quarantined'===$locked->ownership_state()||(null!==$locked->epoch_id()&&$locked->epoch_id()!==$context->epoch_id())||null!==$session->provider_session_id()&&$session->provider_session_id()!==$event->provider_session_id()||null!==$session->provider_payment_id()&&null!==$event->provider_payment_id()&&$session->provider_payment_id()!==$event->provider_payment_id()) {return $this->rollback_result(WebhookAssociationResult::IDENTITY_CONFLICT);}
		$at=$this->clock->now_mysql();$stage=$this->continuation->stage('association',$order,$session,$locked,$at);
		if(null===$session->provider_session_id()&&!$this->sessions->bind_provider_session_id_if_missing($session->id(),$event->provider(),(string)$event->provider_session_id(),$at)){return $this->end_failure(WebhookAssociationResult::DATABASE_FAILURE,$operation,$stage);}
		$bound=$this->events->bind_association($event->id(),$session->id(),$event->provider_session_id(),$event->local_reference(),$event->provider_payment_id(),$event->currency(),$event->amount_minor());
		if(!in_array($bound->outcome(),array(PaymentEventAssociationResult::BOUND,PaymentEventAssociationResult::ALREADY_BOUND),true)||!$this->events->bind_epoch($event->id(),$context->epoch_id())){return $this->end_failure(WebhookAssociationResult::IDENTITY_CONFLICT,$operation,$stage);}
		$check=$registry->bind_event($locked,$claim,$this->clock->now_mysql());
		if('bound'!==$check){return $this->end_failure('conflict'===$check?WebhookAssociationResult::IDENTITY_CONFLICT:WebhookAssociationResult::DATABASE_FAILURE,$operation,$stage);}
		if(!$this->tx->commit()){return $this->unknown($operation,$stage);}
		return WebhookAssociationResult::result(PaymentEventAssociationResult::BOUND===$bound->outcome()?WebhookAssociationResult::ASSOCIATED:WebhookAssociationResult::ALREADY_ASSOCIATED,$session->id(),$order->id());
		} catch(\Throwable $error) {
			return $operation!==null&&$stage!==null?$this->end_failure(WebhookAssociationResult::DATABASE_FAILURE,$operation,$stage):$this->rollback_result(WebhookAssociationResult::DATABASE_FAILURE);
		}
	}
	private function rollback_result(string $code):WebhookAssociationResult{if($this->tx->rollback())return WebhookAssociationResult::result($code);$this->continuation->discard();return WebhookAssociationResult::result(WebhookAssociationResult::OUTCOME_UNKNOWN);}
	private function end_failure(string $code,CommittedOperation $op,PaymentEventStageInspection $stage):WebhookAssociationResult{return $this->tx->rollback()?WebhookAssociationResult::result($code):$this->unknown($op,$stage);}
	private function unknown(CommittedOperation $op,PaymentEventStageInspection $stage):WebhookAssociationResult {
		// Inspection cannot resume conversion on this discarded connection.
		return WebhookAssociationResult::result(WebhookAssociationResult::OUTCOME_UNKNOWN,inspection:$this->continuation->inspect($op,$stage));
	}
}
