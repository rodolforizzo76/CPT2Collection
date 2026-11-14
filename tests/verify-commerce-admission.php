<?php
/** Real InnoDB admission, validation and ownership checks. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager,CommerceContext};
$prefix = 'c2c_admit_' . bin2hex(random_bytes(5)) . '_'; $db = new wpdb($prefix); $t = new TableNames($db); $created = [];
try {
	baseline_schema($db); check(\RodolfoRizzo\Cpt2Collection\CommerceBaselineMigration::run($db),'admission fixture baselined');
	$contract=$db->get_var('SELECT baseline_contract FROM '.$t->commerce_control());
	$a = new TransactionManager($db,$t); $other = new wpdb($prefix); $b = new TransactionManager($other,new TableNames($other));
	check($a->begin_commerce_shared() && $b->begin_commerce_shared(),'independent shared admissions coexist');
	$context = $a->commerce_context();
	check($context instanceof CommerceContext && $context->epoch_id()===1,'validated immutable epoch-one context');
	check(!$a->begin_commerce_exclusive(),'shared to exclusive upgrade refused');
	$nested = new TransactionManager($db,$t); check(!$nested->begin() && !$nested->begin_commerce_shared(),'second manager cannot start nested transaction');
	check($a->commit() && $b->rollback() && !$a->is_commerce_admitted() && $a->commerce_context()===null,'confirmed termination releases admission context');
	check($a->begin_commerce_exclusive($context) && $a->rollback(),'exclusive admission succeeds after shared release');
	check(!$a->begin_commerce_shared(new CommerceContext(str_repeat('c',32),1,str_repeat('b',32))) && !$a->is_active() && $a->admission_error()==='commerce_context_mismatch','expected mismatch rolls back without rebasing');
	foreach (['state'=>"UPDATE {$t->commerce_control()} SET state='resetting'",'epoch'=>"UPDATE {$t->commerce_control()} SET current_epoch_id=0",'identity'=>"UPDATE {$t->commerce_control()} SET installation_id='invalid'",'retired'=>"UPDATE {$t->commerce_epochs()} SET retired_at=NOW()",'extra'=>"INSERT INTO {$t->commerce_control()} SELECT 2,installation_id,current_epoch_id,state,updated_at,baseline_contract FROM {$t->commerce_control()}",'missing'=>"DELETE FROM {$t->commerce_epochs()}"] as $name=>$change) {
		sql($db,$change); check(!$a->begin_commerce_shared() && !$a->is_active(),"corrupt $name refuses and rolls back");
		sql($db,"DELETE FROM {$t->commerce_control()}"); sql($db,"DELETE FROM {$t->commerce_epochs()}"); seed_commerce_foundation($db); sql($db,$db->prepare('UPDATE '.$t->commerce_control().' SET baseline_contract=%s',$contract));
	}
	sql($db,"ALTER TABLE {$t->commerce_epochs()} ENGINE=MyISAM"); check(!$a->begin_commerce_shared() && $a->admission_error()==='transactions_not_supported','nontransactional foundation refuses before BEGIN'); sql($db,"ALTER TABLE {$t->commerce_epochs()} ENGINE=InnoDB");
	check($a->begin(),'generic begin remains explicit and unadmitted'); check(!$a->is_commerce_admitted(),'generic transaction is not commerce admission'); $a->rollback();
	check($a->begin_commerce_shared(),'shared holder for timeout');
	sql($other,'SET SESSION innodb_lock_wait_timeout=1');
	check(!$b->begin_commerce_exclusive() && !$b->is_active() && $b->last_error_number()===1205,'lock timeout rolls back and preserves original error number');
	check(stripos($other->last_error,'lock wait timeout')!==false,'rollback preserves original admission database failure');
	$a->rollback();
	check($b->begin_commerce_exclusive()&&$b->commit(),'confirmed timeout rollback permits explicit later admission');
	sql($db,'DROP TABLE '.$t->commerce_epochs());
	check(!$a->begin_commerce_shared()&&!$a->is_active(),'missing foundation storage refuses before begin');
	$other->close();
} finally { baseline_cleanup($db); }
