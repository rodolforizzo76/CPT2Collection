<?php
/** Immutable before/after durable postconditions. DATA, never authority or a replay command. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class ProviderEvidenceRecovery {
 public readonly array $before;
 public readonly array $after;
 public function __construct(
  public readonly ProviderDiscoveryCandidate $candidate,
  array $before,
  array $after,
  public readonly ?CommerceResetRecord $domain,
  public readonly int $charges
 ) {
  $this->before=self::snapshot($before);$this->after=self::snapshot($after);
  $keys=array_keys($candidate->keys);sort($keys,SORT_STRING);
  if(array_keys($this->before)!==$keys||array_keys($this->after)!==$keys||$charges<0||$charges>count($keys))throw new \InvalidArgumentException('Invalid recovery envelope.');
 }
 /** Copy object rows into scalar arrays; readonly arrays must not hide mutable objects. */
 private static function snapshot(array $rows):array {
  ksort($rows,SORT_STRING);
  foreach($rows as &$row){
   if($row===null)continue;
   $row=is_object($row)?get_object_vars($row):$row;
   if(!is_array($row))throw new \InvalidArgumentException('Invalid recovery row.');
   foreach($row as &$value){if($value!==null&&!is_string($value)&&!is_int($value))throw new \InvalidArgumentException('Non-scalar recovery fact.');if($value!==null)$value=(string)$value;}unset($value);
   ksort($row,SORT_STRING);
  }unset($row);
  return $rows;
 }
 /** New INSERT IDs are server-assigned: compare its unique key and every semantic field.
  * Existing rows retain their exact ID. No missing-field wildcard besides a new INSERT ID.
  */
 public function matches(array $rows,bool $after):bool {
  $actual=self::snapshot($rows);$expected=$after?$this->after:$this->before;
  if(array_keys($actual)!==array_keys($expected))return false;
  foreach($expected as $kind=>$row){
   if($row!==null&&!array_key_exists('id',$row)){
	if(!$after||$this->before[$kind]!==null||!is_array($actual[$kind])||filter_var($actual[$kind]['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)return false;
	unset($actual[$kind]['id']);
   }
   if($actual[$kind]!==$row)return false;
  }
  return true;
 }
}
