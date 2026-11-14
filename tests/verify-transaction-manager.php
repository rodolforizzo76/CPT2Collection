<?php
/** Executable transaction state and uncertain-mutation guard verification. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );
class wpdb {
	public string $prefix='wp_'; public string $posts='wp_posts'; public string $postmeta='wp_postmeta'; public string $last_error=''; public int $queries=0; public bool $fail_begin=false; public bool $fail_commit=false; public bool $fail_rollback=false;
	public function prepare(string $query,mixed ...$args):string{return $query;}
	public function query(string $query):int|false{++$this->queries;if('START TRANSACTION'===$query&&$this->fail_begin)return false;if('COMMIT'===$query&&$this->fail_commit)return false;if('ROLLBACK'===$query&&$this->fail_rollback)return false;return 0;}
	public function get_results(string $query):array{return array();}
}
foreach(array('table-names','transaction-manager','reservation-repository','locked-catalog-reader','conversion-reservation-validation-result','conversion-reservation-set-validator','stock-conversion-result','stock-conversion-processor')as$class)require_once dirname(__DIR__).'/includes/class-'.$class.'.php';
use RodolfoRizzo\Cpt2Collection\{LockedCatalogReader,ReservationRepository,StockConversionProcessor,TableNames,TransactionManager};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: {$label}\n";}
$db=new wpdb();$tx=new TransactionManager($db,new TableNames($db));$db->fail_begin=true;check(!$tx->begin()&&$tx->is_active()&&$tx->is_outcome_uncertain(),'failed BEGIN becomes uncertain');$starts=$db->queries;check(!$tx->begin()&&$starts===$db->queries,'BEGIN is rejected while uncertain');$catalog=new LockedCatalogReader($db,$tx);check(!$catalog->decrement_stock(1,1,1)&&$starts===$db->queries,'locked catalog rejects uncertain stock mutation');$repo=new ReservationRepository($db,new TableNames($db));$processor=new StockConversionProcessor($repo,$catalog,$tx);check('transaction_required'===$processor->decrement(1,array(),'collection_item','2026-01-01 00:00:00')->code(),'stock processor rejects uncertain transaction');
$db=new wpdb();$tx=new TransactionManager($db,new TableNames($db));check($tx->begin(),'BEGIN succeeds');$db->fail_commit=true;check(!$tx->commit()&&$tx->is_outcome_uncertain(),'failed COMMIT becomes uncertain');
$db=new wpdb();$tx=new TransactionManager($db,new TableNames($db));check($tx->begin(),'second BEGIN succeeds');$db->fail_rollback=true;check(!$tx->rollback()&&$tx->is_outcome_uncertain(),'failed ROLLBACK becomes uncertain');
echo "All transaction manager state checks passed.\n";
