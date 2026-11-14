<?php
/** Immutable exact baseline/progress snapshot. No generic reset or unblock machinery. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceBaselinePurgeRecord {
 // Children before parents; evidence has no FK/cascade and is removed explicitly.
 public const STAGES=['payment_events','payment_sessions','reservations','order_items','orders','cart_items','carts','provider_identities','provider_claims','order_deletions'];
 private const KEYS=['version','baseline_identity','installation_id','source_epoch_id','source_epoch_identity','first_epoch_id','first_epoch_identity','stage','cursor','revision','completed'];
 public function __construct(private array $data){
  if(array_keys($data)!==self::KEYS||$data['version']!==1||!is_bool($data['completed']))throw new \InvalidArgumentException('Invalid purge shape.');
  foreach(['baseline_identity','installation_id','source_epoch_identity','first_epoch_identity'] as $k)if(!CommerceBaseline::identity($data[$k]))throw new \InvalidArgumentException('Invalid purge identity.');
  foreach(['source_epoch_id','first_epoch_id','stage','cursor','revision'] as $k)if(!is_int($data[$k])||$data[$k]<0)throw new \InvalidArgumentException('Invalid purge integer.');
  if($data['source_epoch_id']<1||$data['first_epoch_id']<=$data['source_epoch_id']||$data['source_epoch_identity']===$data['first_epoch_identity']||$data['stage']>count(self::STAGES)||($data['stage']===count(self::STAGES)&&$data['cursor']!==0)||($data['completed']&&$data['stage']!==count(self::STAGES)))throw new \InvalidArgumentException('Invalid purge position.');
 }
 public static function from_json(string $json):self { $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \InvalidArgumentException('Invalid purge JSON.');return new self($data); }
 public static function initial(array $b,object $source):self { return new self(['version'=>1,'baseline_identity'=>$b['identity'],'installation_id'=>$b['installation_id'],'source_epoch_id'=>(int)$source->id,'source_epoch_identity'=>$source->identity,'first_epoch_id'=>$b['first_epoch_id'],'first_epoch_identity'=>$b['first_epoch_identity'],'stage'=>0,'cursor'=>0,'revision'=>0,'completed'=>false]); }
 public function data():array{return $this->data;}
 public function json():string{return json_encode($this->data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 public function equals(self $other):bool{return $this->data===$other->data;}
 public function next(int $stage,int $cursor,bool $completed=false):self{
  if($this->data['revision']===PHP_INT_MAX)throw new \OverflowException('Purge revision exhausted.');
  $d=$this->data;$d['stage']=$stage;$d['cursor']=$cursor;++$d['revision'];$d['completed']=$completed;return new self($d);
 }
}
