<?php
/** Explicit one-time purge. Private SQL only, under control -> source epoch locks.
 * No normal/reset/evidence authority is granted; no provider or stock code is called.
 * The entire installation-local domain is disposable while the exact reserved
 * boundary is unallocated and all runtime admissions are fenced. Epoch-less and
 * orphan item/receipt rows are consequently included, without timestamp heuristics.
 */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceBaselinePurgeService {
 private TransactionManager $tx;
 public function __construct(private \wpdb $db,private TableNames $tables,private UtcClock $clock){$this->tx=new TransactionManager($db,$tables);}
 /** Fresh-connection locked inspection; never starts deletion or invents progress. */
 public function inspect(array $baseline):CommerceBaselinePurgeResult{return $this->execute($baseline,null,0,false);}
 /** One bounded batch, stage transition, or atomic finalization. NULL expects not-started. */
 public function step(array $baseline,?CommerceBaselinePurgeRecord $expected=null,int $batch_size=100):CommerceBaselinePurgeResult{
  if($batch_size<1||$batch_size>1000)return new CommerceBaselinePurgeResult('invalid_request');
  return $this->execute($baseline,$expected,$batch_size,true);
 }
 private function execute(array $expected_baseline,?CommerceBaselinePurgeRecord $expected,int $size,bool $write):CommerceBaselinePurgeResult {
  if(array_keys($expected_baseline)!==['version','installation_id','identity','first_epoch_id','first_epoch_identity','status']||$expected_baseline['version']!==1||!is_int($expected_baseline['first_epoch_id'])||$expected_baseline['first_epoch_id']<1||!in_array($expected_baseline['status'],['prepared','completed'],true))return new CommerceBaselinePurgeResult('invalid_request');
  foreach(['installation_id','identity','first_epoch_identity'] as $key)if(!CommerceBaseline::identity($expected_baseline[$key]))return new CommerceBaselinePurgeResult('invalid_request');
  if(!$this->db instanceof CommerceConnection||!$this->db->transaction_connection_usable())return new CommerceBaselinePurgeResult('transaction_outcome_unknown');
  try{if(!CommerceBaselinePurgeMigration::verify_structure($this->db,$this->tables))return new CommerceBaselinePurgeResult($this->db->last_error!==''?'database_error':'inconsistent_state');if(!$this->storage_safe())return new CommerceBaselinePurgeResult('inconsistent_state');}
  catch(\Throwable $e){return new CommerceBaselinePurgeResult('database_error');}
  if(!$this->tx->begin())return new CommerceBaselinePurgeResult($this->tx->is_outcome_uncertain()?'transaction_outcome_unknown':'database_error');
  try {
   $baseline=(new CommerceBaseline($this->db,$this->tables))->read('FOR UPDATE');
   if($baseline===null)return $this->fail($this->db->last_error!==''?'database_error':'inconsistent_state');
   $same=$expected_baseline;$same['status']=$baseline['status'];
   if($same!==$baseline)return $this->fail('stale');
   $control=$this->one('SELECT * FROM '.$this->tables->commerce_control().' WHERE id=1 FOR UPDATE');
   // Epoch lock always precedes progress or downstream row locks.
   $source=$this->one($this->db->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' WHERE id=%d FOR UPDATE',$control->current_epoch_id));
   $record=$control->baseline_purge===null?null:CommerceBaselinePurgeRecord::from_json($control->baseline_purge);
   if($record!==null){
	$d=$record->data();
	foreach(['installation_id','first_epoch_id','first_epoch_identity'] as $k)if($d[$k]!==$baseline[$k])return $this->fail('inconsistent_state');
	if($d['baseline_identity']!==$baseline['identity']||$d['completed']!==($baseline['status']==='completed'))return $this->fail('inconsistent_state');
	if($d['completed']){
	 // Read-only historical proof under the control fence; no late lower-epoch locks.
	 $old=$this->one($this->db->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' WHERE id=%d',$d['source_epoch_id']));
	 if($old===null||$old->identity!==$d['source_epoch_identity']||!ProviderClaimRecord::valid_date($old->retired_at)||$old->retired_at<$old->created_at)return $this->fail('inconsistent_state');
	}
   }
   // A fresh completed installation has NULL progress. A completed purge is forever read-only.
   if($baseline['status']==='completed')return $this->finish('already_completed',$record,false);
   if($source===null||$source->retired_at!==null||!CommerceBaseline::identity($source->identity)||!ProviderClaimRecord::valid_date($source->created_at))return $this->fail('inconsistent_state');
   if($record!==null&&($record->data()['source_epoch_id']!==(int)$source->id||$record->data()['source_epoch_identity']!==$source->identity))return $this->fail('inconsistent_state');
   if(!$write)return $this->finish($record===null?'prepared':($record->data()['stage']===count(CommerceBaselinePurgeRecord::STAGES)?'ready_for_finalization':'running'),$record,false);
   if(($expected===null)!==($record===null)||($expected!==null&&!$expected->equals($record)))return $this->fail('stale');
   if($expected_baseline['status']!=='prepared')return $this->fail('stale');
   if($record===null){$record=CommerceBaselinePurgeRecord::initial($baseline,$source);$this->persist($record);return $this->finish('progressed',$record);}
   $d=$record->data();$stage=$d['stage'];
   if($stage===count(CommerceBaselinePurgeRecord::STAGES)){
	// Semantic emptiness, not cursor exhaustion. Ten indexed first-key probes.
	foreach(CommerceBaselinePurgeRecord::STAGES as $method)if($this->many('SELECT id FROM '.$this->tables->$method().' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 1 FOR UPDATE'))return $this->fail('inconsistent_state');
	$now=$this->clock->now_mysql();if($now<$source->created_at)return $this->fail('inconsistent_state');
	$this->query($this->db->prepare('UPDATE '.$this->tables->commerce_epochs().' SET retired_at=%s WHERE id=%d AND retired_at IS NULL',$now,$d['source_epoch_id']),1);
	$this->query($this->db->prepare('INSERT INTO '.$this->tables->commerce_epochs().' (id,identity,created_at,retired_at) VALUES(%d,%s,%s,NULL)',$baseline['first_epoch_id'],$baseline['first_epoch_identity'],$now),1);
	$baseline['status']='completed';$record=$record->next($stage,0,true);
	$this->query($this->db->prepare('UPDATE '.$this->tables->commerce_control()." SET current_epoch_id=%d,state='ready',baseline_contract=%s,baseline_purge=%s,updated_at=%s WHERE id=1",$baseline['first_epoch_id'],json_encode($baseline,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$record->json(),$now),1);
	if((new CommerceBaseline($this->db,$this->tables))->read('FOR UPDATE')!==$baseline)throw new \LogicException('Completion contradiction.');
	return $this->finish('completed',$record);
   }
   $method=CommerceBaselinePurgeRecord::STAGES[$stage];$table=$this->tables->$method();
   $rows=$this->many($this->db->prepare('SELECT * FROM '.$table.' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT %d FOR UPDATE',$d['cursor'],$size));
   if(!$rows){
	// Detect residuals behind the cursor, including externally introduced rows.
	if($this->many('SELECT id FROM '.$table.' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 1 FOR UPDATE'))return $this->fail('inconsistent_state');
	$record=$record->next($stage+1,0);$this->persist($record);return $this->finish('progressed',$record);
   }
   $ids=[];
   foreach($rows as $row){
	$id=filter_var($row->id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false||$id<=$d['cursor'])return $this->fail('inconsistent_state');
	// All allocated history is pre-boundary. Contradictory future ownership blocks.
	$epoch=$method==='provider_identities'?$row->observed_epoch_id:($row->epoch_id??null);
	if($epoch!==null&&(false===filter_var($epoch,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])||(int)$epoch>=$baseline['first_epoch_id']))return $this->fail('inconsistent_state');
	if($epoch!==null){
	 $owner=$this->one($this->db->prepare('SELECT id,identity FROM '.$this->tables->commerce_epochs().' WHERE id=%d',(int)$epoch));
	 if($owner===null||!CommerceBaseline::identity($owner->identity))return $this->fail('inconsistent_state');
	 if(in_array($method,['provider_claims','order_deletions'],true)&&($row->installation_id!==$baseline['installation_id']||$row->epoch_identity!==$owner->identity))return $this->fail('inconsistent_state');
	}
	if($method==='order_deletions'){
	 // Retaining AUTO_INCREMENT is necessary but not sufficient for corrupt tombstones.
	 // Prove the numeric non-reuse barrier before removing their final local evidence.
	 foreach(['orders'=>'original_order_id','carts'=>'cart_id'] as $parent=>$field)if($row->$field!==null){
	  $next=$this->one($this->db->prepare('SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$this->tables->$parent()));
	  if($next===null||$next->next_id===null||(int)$row->$field>=(int)$next->next_id)return $this->fail('inconsistent_state');
	 }
	}
	$ids[]=$id;
   }
   $this->query('DELETE FROM '.$table.' WHERE id IN ('.implode(',',$ids).')',count($ids));
   $record=$record->next($stage,max($ids));$this->persist($record);return $this->finish('progressed',$record);
  }catch(\LogicException|\InvalidArgumentException|\JsonException|\OverflowException $e){return $this->fail('inconsistent_state');}
  catch(\Throwable $e){return $this->fail('database_error');}
 }
 private function persist(CommerceBaselinePurgeRecord $record):void{$this->query($this->db->prepare('UPDATE '.$this->tables->commerce_control().' SET baseline_purge=%s WHERE id=1',$record->json()),1);}
 private function query(string $sql,int $affected):void{if($this->db->query($sql)!==$affected)throw new \RuntimeException('Purge database write failed.');}
 private function many(string $sql):array{$this->db->last_error='';$r=$this->db->get_results($sql);if($this->db->last_error!==''||!is_array($r))throw new \RuntimeException('Purge database read failed.');return $r;}
 private function one(string $sql):?object{$rows=$this->many($sql);if(count($rows)>1)throw new \LogicException('Ambiguous purge authority.');return $rows[0]??null;}
 private function fail(string $code):CommerceBaselinePurgeResult{return new CommerceBaselinePurgeResult($this->tx->rollback()?$code:'transaction_outcome_unknown');}
 private function finish(string $code,?CommerceBaselinePurgeRecord $r,bool $write=true):CommerceBaselinePurgeResult{return ($write?$this->tx->commit():$this->tx->rollback())?new CommerceBaselinePurgeResult($code,$r):new CommerceBaselinePurgeResult('transaction_outcome_unknown');}
 private function storage_safe():bool{
  if(!CommerceFoundation::verify_structure($this->db,$this->tables)||!CommercePhaseTwoMigration::verify_structure($this->db,$this->tables)||!CommercePhaseThreeCheckpointOneMigration::verify_structure($this->db,$this->tables)||!CommerceEvidenceGenerationMigration::verify_structure($this->db,$this->tables)){if($this->db->last_error!=='')throw new \RuntimeException('Purge structure probe failed.');return false;}
  $names=[$this->tables->commerce_control(),$this->tables->commerce_epochs(),$this->tables->commerce_resets()];
  foreach(CommerceBaselinePurgeRecord::STAGES as $m){
   $table=$this->tables->$m();$names[]=$table;
   $indexes=$this->many('SHOW INDEX FROM '.$table);$primary=[];
   foreach($indexes as $i)if($i->Key_name==='PRIMARY')$primary[]=$i;
   if(count($primary)!==1||$primary[0]->Column_name!=='id'||$primary[0]->Sub_part!==null)return false;
  }
  $marks=implode(',',array_fill(0,count($names),'%s'));
  $rows=$this->many($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$marks.')',...$names));
  if(count($rows)!==count($names))return false;foreach($rows as $r)if(strtoupper($r->ENGINE)!=='INNODB')return false;
  $disposable=array_slice($names,3);$marks=implode(',',array_fill(0,count($disposable),'%s'));
  // Repository schema has neither triggers nor FKs. Unexpected extensions cannot
  // silently cascade into preserved domains or restore stock on DELETE.
  if($this->many($this->db->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('.$marks.') LIMIT 1',...$disposable)))return false;
  if($this->many($this->db->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN ('.$marks.') OR REFERENCED_TABLE_NAME IN ('.$marks.')) LIMIT 1',...$disposable,...$disposable)))return false;
  return true;
 }
}
