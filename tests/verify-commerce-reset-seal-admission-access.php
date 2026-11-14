<?php
/**
 * Regression for bounded baseline discovery, not a strengthened-seal implementation.
 * Measures an implicit baseline read in actual reset-maintenance admission.
 * Synthetic completed-baseline fixtures exercise the accepted arbitrary positive
 * first_epoch_id contract; they do not execute a production purge/epoch switch.
 */
declare( strict_types=1 );
require __DIR__ . '/support/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\{TableNames, TransactionManager};

class SealAdmissionAccessDb extends wpdb {
	public array $baseline_queries = array();
	public ?string $fault = null;
	public function get_results( string $query ): ?array {
		if ( str_starts_with( $query, 'SELECT * FROM ' . (new TableNames($this))->commerce_epochs() ) && str_ends_with($query, ' LIMIT 1') ) {
			$this->baseline_queries[] = $query;
			if ($this->fault !== null && str_contains($query, ' WHERE ' . $this->fault . '=')) { $this->last_error='injected point read failure'; return null; }
		}
		return parent::get_results( $query );
	}
}

function seal_admission_examined( array $plan ): float {
	$total = 0;
	foreach ( $plan as $key => $value ) {
		if ( 'table' === $key && is_array( $value ) ) { $total += ( $value['r_rows'] ?? 0 ) * ( $value['r_loops'] ?? 0 ); }
		elseif ( is_array( $value ) ) { $total += seal_admission_examined( $value ); }
	}
	return $total;
}

foreach ( array( 1000, 10000 ) as $filler ) {
	$db = new SealAdmissionAccessDb( 'c2c_seal_admit_' . bin2hex( random_bytes( 5 ) ) . '_' );
	$tables = new TableNames( $db );
	try {
		validation_schema( $db );
		$baseline = json_decode( $db->get_var( 'SELECT baseline_contract FROM ' . $tables->commerce_control() . ' WHERE id=1' ), true, 32, JSON_THROW_ON_ERROR );
		sql( $db, 'UPDATE ' . $tables->commerce_epochs() . ' SET retired_at=NOW()' );
		// Setup itself uses finite insert batches. No new index or production code.
		for ( $start = 2; $start <= $filler; $start += 500 ) {
			$values = array();
			for ( $id = $start; $id < min( $start + 500, $filler + 1 ); ++$id ) {
				$values[] = '(' . $id . ",'" . str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ) . "',NOW(),NOW())";
			}
			sql( $db, 'INSERT INTO ' . $tables->commerce_epochs() . ' VALUES ' . implode( ',', $values ) );
		}
		$first = $filler + 1;
		$identity = bin2hex( random_bytes( 16 ) );
		sql( $db, $db->prepare( 'INSERT INTO ' . $tables->commerce_epochs() . ' VALUES(%d,%s,NOW(),NULL)', $first, $identity ) );
		$baseline['first_epoch_id'] = $first;
		$baseline['first_epoch_identity'] = $identity;
		sql( $db, $db->prepare( 'UPDATE ' . $tables->commerce_control() . ' SET current_epoch_id=%d,baseline_contract=%s WHERE id=1', $first, json_encode( $baseline, JSON_THROW_ON_ERROR ) ) );
		sql( $db, 'ANALYZE TABLE ' . $tables->commerce_epochs() );
		$operation = validation_start( $db );
		$transactions = new TransactionManager( $db, $tables );
		// Add filler AFTER the match too; baseline's first remains retired and
		// current points at the last valid modern epoch. Setup is test-only.
		sql($db,'UPDATE '.$tables->commerce_epochs().' SET retired_at=NOW() WHERE id='.$first);
		for($start=$first+1;$start<=$first+$filler;$start+=500){$values=array();for($id=$start;$id<min($start+500,$first+$filler+1);++$id)$values[]="($id,'".str_pad(dechex($id),32,'0',STR_PAD_LEFT)."',NOW(),".($id===$first+$filler?'NULL':'NOW()').")";sql($db,'INSERT INTO '.$tables->commerce_epochs().' VALUES '.implode(',',$values));}
		// Existing reset references the original source; exercise this second
		// distribution through baseline reads, then restore for real admission.
		$method=new ReflectionMethod(\RodolfoRizzo\Cpt2Collection\CommerceBaseline::class,'first_epoch');
		$reader=new \RodolfoRizzo\Cpt2Collection\CommerceBaseline($db,$tables);
		$cases=array(
			'id_only'=>array($first,str_repeat('f',32),$first),
			'identity_only'=>array($first+$filler+1,$identity,$first),
			'neither'=>array($first+$filler+1,str_repeat('f',32),null),
			'same'=>array($first,$identity,$first),
			'identity_lower'=>array($first,str_pad('2',32,'0',STR_PAD_LEFT),2),
			'id_lower'=>array(2,$identity,2),
		);
		foreach(array('on','off') as $merge){
			sql($db,"SET SESSION optimizer_switch='index_merge=".$merge."'");
			foreach($cases as $name=>[$id,$key,$want]){for($repeat=0;$repeat<2;++$repeat){
				$db->baseline_queries=array();$db->last_error='';$row=$method->invoke($reader,$id,$key);
				check(($row===null?null:(int)$row->id)===$want,$name.': minimum-ID union selection preserved');
				check(count($db->baseline_queries)===2,'both unique selectors probed, including duplicate/absence');
				foreach($db->baseline_queries as $q){
					check(!preg_match('/\b(OR|UNION|OFFSET|FORCE|ORDER)\b/',$q),'production shape is an unhinted equality point');
					$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);
					check(seal_admission_examined($plan)<=1,'actual production point examines at most one row');
					check(!str_contains(json_encode($plan),'filesort')&&!str_contains(json_encode($plan),'temporary_table'),'point has no filesort or temporary table');
				}
			}}
			echo 'EVIDENCE: filler_before='.$filler.' filler_after='.$filler.' index_merge='.$merge.' ID/identity max examined=1/1; no filesort/temp.'."\n";
		}
		// Error from either point cannot be turned into a usable candidate.
		foreach(array('id','identity') as $fault){$db->fault=$fault;$db->last_error='';check($method->invoke($reader,$first,$identity)===null&&$db->last_error!=='',$fault.': read failure preserved');}$db->fault=null;
		// Restore the admitted unretired source. Later filler is removed only
		// from this disposable fixture; no production deletion protocol exists.
		sql($db,'DELETE FROM '.$tables->commerce_epochs().' WHERE id>'.$first);
		sql($db,'UPDATE '.$tables->commerce_epochs().' SET retired_at=NULL WHERE id='.$first);
		$original=$baseline;
		foreach($cases as $name=>[$id,$key,$want]){$candidate=$original;$candidate['first_epoch_id']=$id;$candidate['first_epoch_identity']=$key;sql($db,$db->prepare('UPDATE '.$tables->commerce_control().' SET baseline_contract=%s WHERE id=1',json_encode($candidate)));check(($reader->read()!==null)===($name==='same'),'completed baseline '.$name.' preserves acceptance/rejection');}
		foreach(array(['first_epoch_id','10001'],['first_epoch_id',0],['first_epoch_identity','invalid']) as [$field,$bad]){$candidate=$original;$candidate[$field]=$bad;sql($db,$db->prepare('UPDATE '.$tables->commerce_control().' SET baseline_contract=%s WHERE id=1',json_encode($candidate)));$db->baseline_queries=array();check($reader->read()===null&&$db->baseline_queries===array(),'invalid selector rejected before probes');}
		$prepared=$original;$prepared['status']='prepared';$prepared['first_epoch_id']=$first+1;$prepared['first_epoch_identity']=str_repeat('f',32);sql($db,$db->prepare("UPDATE ".$tables->commerce_control()." SET state='baseline_pending',baseline_contract=%s WHERE id=1",json_encode($prepared)));check($reader->read()===$prepared,'prepared reserved absent next epoch accepted');
		$prepared['first_epoch_identity']=$identity;sql($db,$db->prepare('UPDATE '.$tables->commerce_control().' SET baseline_contract=%s WHERE id=1',json_encode($prepared)));check($reader->read()===null,'prepared identity-only hit rejects allocation/reuse');
		sql($db,$db->prepare("UPDATE ".$tables->commerce_control()." SET state='resetting',baseline_contract=%s WHERE id=1",json_encode($original)));
		foreach(array('id','identity') as $fault){$db->fault=$fault;check($reader->read()===null&&$db->last_error!=='','public baseline rejects point DB failure');}$db->fault=null;
		foreach(array('on','off') as $merge){sql($db,"SET SESSION optimizer_switch='index_merge=".$merge."'");$db->baseline_queries=array();check($transactions->begin_reset_maintenance($operation),'actual maintenance admits bounded baseline');check(count($db->baseline_queries)===2,'actual admission uses both production point shapes');check($transactions->rollback(),'read-only maintenance outcome known');}
		check( validation_service( $db )->resume( $operation->operation_identity() )->operation()->equals( $operation ), 'diagnostic does not advance or certify reset' );
	} finally { baseline_cleanup( $db ); }
}
echo "Bounded baseline admission regression passed.\n";
