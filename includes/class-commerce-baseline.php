<?php
/**
 * Installation-bound provider eligibility, separate from claim/transaction authority.
 * One canonical control-row attestation carries version, installation, operation
 * identity, first modern epoch ID/identity, and prepared/completed status.
 * Prepared reserves an unallocated next epoch; completed accepts current AND
 * coherently retired modern epochs. There is no current-epoch equality gate.
 */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;

final class CommerceBaseline {
 private int $current_epoch_id=0;
 public const ELIGIBLE='eligible', PRE_BASELINE='pre_baseline', FOREIGN='foreign_installation', INVALID='invalid_scope', UNKNOWN='unknown_epoch', ERROR='baseline_unavailable';
 public function __construct(private \wpdb $db, private TableNames $tables) {}
 /** Caller owns a transaction. Control is always the first lock; no network work. */
 public function read(string $lock='LOCK IN SHARE MODE'): ?array {
  if(!in_array($lock,['LOCK IN SHARE MODE','FOR UPDATE'],true))return null;
  $this->db->last_error='';
  $rows=$this->db->get_results('SELECT * FROM '.$this->tables->commerce_control().' ORDER BY id LIMIT 2 '.$lock);
  if($this->db->last_error!==''||!is_array($rows)||count($rows)!==1||(string)$rows[0]->id!=='1')return null;
  $c=$rows[0];
  if(!ProviderClaimRecord::valid_date($c->updated_at)||false===filter_var($c->current_epoch_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))return null;
  $b=json_decode($c->baseline_contract??'',true);
  if(!is_array($b)||array_keys($b)!==['version','installation_id','identity','first_epoch_id','first_epoch_identity','status']||$b['version']!==1||!self::identity($b['installation_id'])||!self::identity($b['identity'])||!self::identity($b['first_epoch_identity'])||!is_int($b['first_epoch_id'])||$b['first_epoch_id']<1||$b['installation_id']!==$c->installation_id||!in_array($b['status'],['prepared','completed'],true))return null;
  $this->db->last_error='';
  $current=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' WHERE id=%d',$c->current_epoch_id));
  if($this->db->last_error!==''||!is_object($current)||$current->retired_at!==null||!self::identity($current->identity)||!ProviderClaimRecord::valid_date($current->created_at))return null;
  $this->db->last_error='';
  $first=$this->first_epoch($b['first_epoch_id'],$b['first_epoch_identity']);
  if($this->db->last_error!=='')return null;
  if($b['status']==='prepared'){
   if($c->state!=='baseline_pending'||$first!==null||(int)$current->id>=$b['first_epoch_id'])return null;
   $this->db->last_error='';$max=$this->db->get_var('SELECT MAX(id) FROM '.$this->tables->commerce_epochs());
   if($this->db->last_error!==''||(int)$max!==$b['first_epoch_id']-1)return null;
  }elseif(!in_array($c->state,['ready','resetting'],true)||!is_object($first)||(int)$first->id!==$b['first_epoch_id']||$first->identity!==$b['first_epoch_identity']||!ProviderClaimRecord::valid_date($first->created_at)||(null!==$first->retired_at&&!ProviderClaimRecord::valid_date($first->retired_at))||(int)$current->id<$b['first_epoch_id'])return null;
  if($b['status']==='completed'&&((int)$current->id>$b['first_epoch_id']&&$first->retired_at===null||$first->retired_at!==null&&$first->retired_at<$first->created_at))return null;
  $this->current_epoch_id=(int)$current->id;
  return $b;
 }
 /** Two unique points, preserving the former SQL minimum-ID union selection.
  * Discovery remains nonlocking under the caller's already-held control lock.
  * Compare unsigned database IDs as decimals, without float/signed overflow.
  */
 private function first_epoch(int $id,string $identity):?object {
  $by_id=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' WHERE id=%d LIMIT 1',$id));
  if($this->db->last_error!=='')return null;
  $by_identity=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' WHERE identity=%s LIMIT 1',$identity));
  if($this->db->last_error!=='')return null;
  if($by_id===null)return $by_identity;
  if($by_identity===null)return $by_id;
  $left=(string)$by_id->id;$right=(string)$by_identity->id;
  return strlen($left)<strlen($right)||(strlen($left)===strlen($right)&&strcmp($left,$right)<=0)?$by_id:$by_identity;
 }
 public function scope(mixed $installation,mixed $identity,?int $id=null):string {
  $b=$this->read();if($b===null)return self::ERROR;
  if(!self::identity($installation)||!self::identity($identity))return self::INVALID;
  if($installation!==$b['installation_id'])return self::FOREIGN;
  $this->db->last_error='';$e=$this->db->get_row($this->db->prepare('SELECT id, identity, created_at, retired_at FROM '.$this->tables->commerce_epochs().' WHERE identity=%s',$identity));
  if($this->db->last_error!=='')return self::ERROR;
  if(!is_object($e))return self::UNKNOWN;
  if(!ProviderClaimRecord::valid_date($e->created_at)||(null!==$e->retired_at&&!ProviderClaimRecord::valid_date($e->retired_at)))return self::ERROR;
  if((int)$e->id>$this->current_epoch_id)return self::ERROR;
  if((null===$e->retired_at&&(int)$e->id!==$this->current_epoch_id)||(null!==$e->retired_at&&$e->retired_at<$e->created_at))return self::ERROR;
  if($id!==null&&(int)$e->id!==$id)return self::INVALID;
  return (int)$e->id<$b['first_epoch_id']?self::PRE_BASELINE:self::ELIGIBLE;
 }
 public static function identity(mixed $v):bool{return is_string($v)&&preg_match('/\A[0-9a-f]{32}\z/',$v)===1;}
}
