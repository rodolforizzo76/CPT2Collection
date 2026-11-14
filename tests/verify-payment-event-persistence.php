<?php
/** Isolated verification for Milestone 7F-A payment persistence foundations. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix='wp_'; public string $posts='wp_posts'; public string $postmeta='wp_postmeta'; public string $last_error=''; public int $last_errno=0; public int $insert_id=0;
	public array $events=array(); public array $sessions=array(); public array $queries=array();
	public function prepare(string $query,mixed ...$args):string { foreach($args as $arg){$value=is_int($arg)?(string)$arg:"'".addslashes((string)$arg)."'";$query=(string)preg_replace('/%[ds]/',$value,$query,1);}return $query; }
	public function insert(string $table,array $data,array $formats):int|false {
		$this->last_error='';$this->last_errno=0;
		if('wp_c2c_payment_events'!==$table){return false;}
		foreach($this->events as $row){if($row['provider']===$data['provider']&&$row['provider_event_id']===$data['provider_event_id']){$this->last_errno=1062;$this->last_error='Duplicate entry for provider_event';return false;}}
		$id=++$this->insert_id;$data['id']=$id;$this->events[$id]=$data;return 1;
	}
	public function get_row(string $query):?object {
		if(str_contains($query,'wp_c2c_provider_claims')&&preg_match("/reference = '([^']+)'/",$query,$m)){foreach($this->sessions as $row){if($row['reference']===$m[1])return(object)['id'=>$row['id'],'installation_id'=>str_repeat('a',32),'epoch_id'=>1,'epoch_identity'=>str_repeat('b',32),'provider'=>$row['provider'],'reference'=>$row['reference'],'order_id'=>$row['order_id'],'payment_session_id'=>$row['id'],'creation_key_hash'=>$row['creation_key_hash'],'disposition'=>'open'];}return null;}
		if(str_contains($query,'wp_c2c_orders')&&preg_match('/id\s*=\s*(\d+)/',$query,$m))return(object)['id'=>(int)$m[1],'epoch_id'=>1,'cart_id'=>null];
		$store=str_contains($query,'wp_c2c_payment_events')?$this->events:$this->sessions;
		if(preg_match("/WHERE provider = '([^']+)' AND provider_(?:event|session|payment)_id = '([^']+)'/",$query,$m)){$key=str_contains($query,'provider_event_id =')?'provider_event_id':(str_contains($query,'provider_payment_id =')?'provider_payment_id':'provider_session_id');foreach($store as $row){if($row['provider']===$m[1]&&$row[$key]===$m[2]){return (object)$row;}}return null;}
		if(preg_match("/WHERE reference = '([^']+)'/",$query,$m)){foreach($store as $row){if($row['reference']===$m[1]){return (object)$row;}}return null;}
		if(preg_match('/WHERE id\s*=\s*(\d+)/',$query,$m)){return isset($store[(int)$m[1]])?(object)$store[(int)$m[1]]:null;}return null;
	}
	public function update(string $table,array $data,array $where,array $formats,array $where_formats):int|false {
		$store=&$this->events;if('wp_c2c_payment_sessions'===$table){$store=&$this->sessions;}
		$id=(int)($where['id']??0);if(!isset($store[$id])){return 0;}foreach($where as $key=>$value){if(($store[$id][$key]??null)!==$value){return 0;}}
		if('wp_c2c_payment_sessions'===$table&&isset($data['provider_session_id'])){foreach($store as $other_id=>$row){if($other_id!==$id&&$row['provider']===$store[$id]['provider']&&$row['provider_session_id']===$data['provider_session_id']){$this->last_errno=1062;$this->last_error='Duplicate provider session';return false;}}}
		$store[$id]=array_merge($store[$id],$data);return 1;
	}
	public function query(string $query):int|false {
		$this->queries[]=$query;
		if(preg_match("/UPDATE wp_c2c_payment_events SET processing_status = 'processing'.*last_attempt_at = '([^']+)'.*WHERE id = (\d+) AND processing_status = '([^']+)'/",$query,$m)){$id=(int)$m[2];if(!isset($this->events[$id])||$this->events[$id]['processing_status']!==$m[3]){return 0;}$this->events[$id]['processing_status']='processing';++$this->events[$id]['attempt_count'];$this->events[$id]['last_attempt_at']=$m[1];$this->events[$id]['error_code']=null;$this->events[$id]['error_detail']=null;return 1;}return 0;
	}
	public function get_results(string $query):array { if (null !== ($foundation = $this->foundation_results($query))) { return $foundation; }$this->queries[]=$query;if(str_contains($query,'information_schema.TABLES')){preg_match_all("/'([^']+)'/",$query,$m);return array_map(static fn(string $name):object=>(object)array('TABLE_NAME'=>$name,'ENGINE'=>'InnoDB'),$m[1]);}return array();}
}
function __(string $message,string $domain):string{return $message;} function get_option(string $name,mixed $default=false):mixed{return 'cpt2collection_currency'===$name?'EUR':$default;}
foreach(array('table-names','money','payment-event-statuses','payment-event-record','payment-event-insert-result','payment-event-repository','payment-session-statuses','payment-session-record','payment-session-repository','transaction-manager','database') as $class){require_once dirname(__DIR__).'/includes/class-'.$class.'.php';}
use RodolfoRizzo\Cpt2Collection\{Database,PaymentEventRecord,PaymentEventRepository,PaymentEventStatuses,PaymentSessionRecord,PaymentSessionRepository,TableNames,TransactionManager};
function check(bool $condition,string $label):void{if(!$condition){throw new RuntimeException($label);}echo "PASS: {$label}\n";}

$db=new wpdb();$tables=new TableNames($db);$fixture_tx=new TransactionManager($db,$tables); check($fixture_tx->begin_commerce_shared(),'repository fixture admitted'); $events=new PaymentEventRepository($db,$tables);$now='2026-09-02 12:00:00';$hash=hash('sha256','signed bytes');
check('0.12.0'===Database::SCHEMA_VERSION&&'wp_c2c_payment_events'===$tables->payment_events(),'schema version and payment-event table name');
$schema=(string)file_get_contents(dirname(__DIR__).'/includes/class-database.php');
foreach(array('provider_event_id VARCHAR(255) NOT NULL','UNIQUE KEY provider_event (provider, provider_event_id)','UNIQUE KEY provider_payment (provider, provider_payment_id)','normalized_payload LONGTEXT NULL','reconciliation_required_at DATETIME NULL','expired_at DATETIME NULL') as $fragment){check(str_contains($schema,$fragment),"schema contains {$fragment}");}
foreach(array('received','processing','processed','ignored','retryable_failed','attention') as $status){check(PaymentEventStatuses::is_valid($status),"event status {$status}");}check(!PaymentEventStatuses::is_valid('completed'),'unknown event status rejected');
$first=$events->insert_receipt('stripe_checkout','evt_1','checkout.session.completed',null,false,$now,$hash,'{"object":"checkout.session"}');check($first->is_inserted()&&$first->event() instanceof PaymentEventRecord,'first durable event receipt inserted');
$duplicate=$events->insert_receipt('stripe_checkout','evt_1','checkout.session.completed',null,false,$now,$hash);check($duplicate->is_duplicate()&&1===count($db->events),'duplicate detected by database uniqueness after insert attempt');
check($events->find(1) instanceof PaymentEventRecord&&$events->find_by_provider_event_id('stripe_checkout','evt_1') instanceof PaymentEventRecord,'event lookups');
check($events->find_for_update(1) instanceof PaymentEventRecord&&$events->find_by_provider_event_id_for_update('stripe_checkout','evt_1') instanceof PaymentEventRecord,'locking event lookups');
check(!$events->bind_mapping(1,9,'cs_test_1',str_repeat('a',32),'pi_1','EUR',1200,'paid','complete')&&null===$events->find(1)->payment_session_id(),'legacy receipt-identity overwrite is disabled');
$db->events[1]['epoch_id']=1;$db->events[1]['ownership_state']='bound';
check($events->mark_processing(1,PaymentEventStatuses::RECEIVED,'2026-09-02 12:00:01'),'received event enters processing');check(1===$events->find(1)->attempt_count(),'processing atomically increments attempt count');
check($events->mark_retryable_failed(1,'database_error','sk_test_secret '.str_repeat('x',600),'2026-09-02 12:00:02'),'processing event enters retryable failure');$failed=$events->find(1);check(strlen((string)$failed->error_detail())<=500&&!str_contains((string)$failed->error_detail(),'sk_test_secret'),'error detail bounded and redacted');
check($events->mark_processing(1,PaymentEventStatuses::RETRYABLE_FAILED,'2026-09-02 12:00:03')&&2===$events->find(1)->attempt_count(),'retry increments attempt count');check($events->mark_terminal(1,PaymentEventStatuses::PROCESSING,PaymentEventStatuses::PROCESSED,'payment_confirmed_and_converted','2026-09-02 12:00:04'),'processed terminal transition');check(!$events->mark_processing(1,PaymentEventStatuses::RECEIVED,$now),'terminal event cannot return to processing');
foreach(array(PaymentEventStatuses::IGNORED,PaymentEventStatuses::ATTENTION) as $terminal){$id=count($db->events)+1;$result=$events->insert_receipt('stripe_checkout','evt_'.$id,'checkout.session.expired',null,false,$now,hash('sha256',(string)$id));$db->events[$id]['epoch_id']=1;$db->events[$id]['ownership_state']='bound';check($result->is_inserted()&&$events->mark_terminal($id,PaymentEventStatuses::RECEIVED,$terminal,'checkout_session_'.$terminal,$now),"{$terminal} terminal transition");if(PaymentEventStatuses::ATTENTION===$terminal){check($now===$events->find($id)->attention_at(),'attention timestamp persisted');}}

$base=array('epoch_id'=>1,'id'=>1,'reference'=>str_repeat('b',32),'order_id'=>5,'provider'=>'stripe_checkout','provider_session_id'=>null,'creation_key_hash'=>hash('sha256','key'),'status'=>'creating','active_slot'=>1,'currency'=>'EUR','amount_minor'=>1200,'redirect_url'=>null,'created_at'=>$now,'updated_at'=>$now,'expires_at'=>null,'completed_at'=>null,'cancelled_at'=>null,'failed_at'=>null,'provider_payment_id'=>null,'provider_event_id'=>null,'payment_confirmed_at'=>null,'confirmed_currency'=>null,'confirmed_amount_minor'=>null,'expired_at'=>null,'reconciliation_required_at'=>null,'reconciliation_code'=>null,'reconciliation_event_id'=>null);$db->sessions[1]=$base;$db->sessions[2]=array_merge($base,array('id'=>2,'reference'=>str_repeat('c',32),'order_id'=>6,'provider_session_id'=>'cs_owned'));
$sessions=new PaymentSessionRepository($db,$tables);check($sessions->bind_provider_session_id_if_missing(1,'stripe_checkout','cs_bound','2026-09-02 12:01:00'),'missing provider session id conditionally bound');check(!$sessions->bind_provider_session_id_if_missing(1,'stripe_checkout','cs_other',$now),'existing provider session id not replaced');check($sessions->find_by_provider_session_id('stripe_checkout','cs_bound') instanceof PaymentSessionRecord&&$sessions->find_by_provider_session_id_for_update('stripe_checkout','cs_bound') instanceof PaymentSessionRecord,'provider-session lookups');check($sessions->find_by_reference_for_update(str_repeat('b',32)) instanceof PaymentSessionRecord,'locking local-reference lookup');
$db->sessions[3]=array_merge($base,array('id'=>3,'reference'=>str_repeat('d',32),'order_id'=>7));check(!$sessions->bind_provider_session_id_if_missing(3,'stripe_checkout','cs_owned',$now),'provider-session uniqueness respected');
check($sessions->mark_reconciliation_required(1,'2026-09-02 12:02:00','payment_stock_conflict',3),'reconciliation metadata persisted');$session=$sessions->find(1);check('creating'===$session->status()&&'payment_stock_conflict'===$session->reconciliation_code()&&3===$session->reconciliation_event_id(),'reconciliation does not mutate lifecycle status');check($sessions->clear_reconciliation_required(1,'2026-09-02 12:03:00')&&null===$sessions->find(1)->reconciliation_code(),'reconciliation metadata cleared explicitly');
$db->sessions[4]=array_merge($base,array('id'=>4,'reference'=>str_repeat('e',32),'order_id'=>8,'provider_session_id'=>'cs_exp','status'=>'active'));check($sessions->transition_terminal(4,'active','expired','2026-09-02 12:04:00'),'expired terminal transition supported');check('2026-09-02 12:04:00'===$sessions->find(4)->expired_at(),'payment-session expired_at persisted');
check(!$sessions->transition_terminal(1,'creating','completed',$now),'generic terminal transition cannot create financial completion');
$db->sessions[5]=array_merge($base,array('id'=>5,'reference'=>str_repeat('f',32),'order_id'=>9,'provider_session_id'=>'cs_complete','status'=>'active'));
check($sessions->complete_verified_payment(5,'active','pi_complete','evt_complete','2026-09-02 12:05:00','EUR',1200),'dedicated verified-payment completion accepts active source');
$completed=$sessions->find(5);check('completed'===$completed->status()&&'pi_complete'===$completed->provider_payment_id()&&'evt_complete'===$completed->provider_event_id()&&'2026-09-02 12:05:00'===$completed->completed_at(),'verified-payment identity and completion timestamps persist atomically');
check(5===$sessions->find_by_provider_payment_id('stripe_checkout','pi_complete')->id(),'provider payment owner lookup resolves financial identity');
check(!$sessions->complete_verified_payment(1,'creating','pi_bad','evt_bad',$now,'EUR',1200),'verified-payment completion rejects creating source');
$transactions=new TransactionManager($db,$tables);check($transactions->supports_sale_conversion(),'future sale-conversion transaction tables support InnoDB');$capability=end($db->queries);foreach(array('wp_posts','wp_postmeta','wp_c2c_orders','wp_c2c_order_items','wp_c2c_reservations','wp_c2c_payment_sessions','wp_c2c_payment_events') as $table){check(str_contains((string)$capability,"'{$table}'"),"transaction capability includes {$table}");}
echo "All Milestone 7F-A payment persistence checks passed.\n";
