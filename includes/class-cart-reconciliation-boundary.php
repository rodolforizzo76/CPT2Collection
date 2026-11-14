<?php
/** K11 exact original sale boundary. Every successful begin owns a new transaction. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
require_once __DIR__.'/class-payment-event-continuation.php';
require_once __DIR__.'/class-payment-financial-stage-inspection.php';

final class CartReconciliationBoundary {
 private PaymentEventContinuation $continuation;
 public function __construct(private \wpdb $db, private TableNames $tables, ?callable $connections=null) {
  $this->continuation=new PaymentEventContinuation($db,$tables,$connections);
 }
 public function is_usable():bool {return $this->db instanceof CommerceConnection&&$this->db->transaction_connection_usable();}
 public function begin(CommittedOperation $operation,TransactionManager $tx):ProviderDiscoveryResult {
  return $this->continuation->begin_financial($operation,$tx);
 }
 /** Cart and original Order are already locked. No financial writer is invoked. */
 public function validate(CommittedOperation $operation,OrderRecord $order):OperationalInspectionResult {
  $sessions=new PaymentSessionRepository($this->db,$this->tables);
  $session=$sessions->find_for_update($operation->claim->payment_session_id());
  if(!$session instanceof PaymentSessionRecord)return new OperationalInspectionResult($session===false?'database_error':'verification_required','original_session_unavailable');
  $later=$sessions->has_later_attempt_for_update($order->id(),$session->id());
  if($later!==false)return new OperationalInspectionResult($later===null?'database_error':'verification_required','original_attempt_superseded_or_unproved');
  $events=new PaymentEventRepository($this->db,$this->tables);
  $event=$events->find_by_provider_event_id($operation->receipt->provider(),$operation->receipt->provider_event_id());
  if(!$event instanceof PaymentEventRecord)return new OperationalInspectionResult($event===false?'database_error':'verification_required','original_receipt_unavailable');
  $locked=$events->find_for_update($event->id());
  if(!$locked instanceof PaymentEventRecord)return new OperationalInspectionResult($locked===false?'database_error':'verification_required','original_receipt_unavailable');
  if(!$this->continuation->validate($operation,$event,$order,$session,$locked))return new OperationalInspectionResult($this->continuation->validation_failure_code()==='database_error'?'database_error':'conflict','cart_sale_owner_unproved');
  // Reuses only D3's pure full paid/converted predicate, never conversion or terminalization.
  $proof=(new PaymentFinancialStageInspection($locked->id(),'processed_replay'))->inspect_current($operation,$this->db,$this->tables);
  if($proof->status!=='logically_completed')return $proof;
  if($order->status()!==OrderStatuses::PAID)return new OperationalInspectionResult('conflict','sale_not_committed');
  return new OperationalInspectionResult('logically_completed','coherent_original_sale');
 }
 public function inspect(CommittedOperation $operation,OperationalStageInspection $stage):OperationalInspectionResult {
  return $this->continuation->inspect($operation,$stage);
 }
 public function discard():void {$this->continuation->discard();}
}
