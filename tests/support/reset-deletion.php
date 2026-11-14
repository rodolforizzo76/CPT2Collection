<?php
/** Real production seal and erasure helpers; fault adapter observes attempted SQL. */
declare(strict_types=1);
require_once __DIR__.'/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRecord,CommerceResetDeletion,TableNames};
class DeletionDb extends wpdb {
 public int $delete_calls=0; public string $fault=''; public bool $observe=false; public array $reads=[],$writes=[];
 public function get_results(string $q):?array {if($this->observe)$this->reads[]=$q;return parent::get_results($q);}
 public function query(string $q):int|false {
  if($this->observe&&preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|TRUNCATE)\b/i',$q))$this->writes[]=$q;
  if($q==='COMMIT'&&$this->fault==='before'){$this->last_error='lost before commit';return false;}
  if($q==='COMMIT'&&$this->fault==='after'){parent::query($q);$this->last_error='lost commit acknowledgment';return false;}
  if($this->fault==='progress'&&str_starts_with($q,'UPDATE `'.$this->prefix.'c2c_commerce_resets`')){$this->last_error='frontier write failed';return false;}
  if($this->fault==='partial_delete'&&str_starts_with($q,'DELETE FROM ')&&++$this->delete_calls===2){$this->last_error='second delete failed';return false;}
  if($this->fault==='delete'&&str_starts_with($q,'DELETE FROM ')){$this->last_error='delete failed';return false;}
  return parent::query($q);
 }
}
function deletion_retained(wpdb $db,CommerceResetRecord $r,int $size=1):CommerceResetRecord {
 for($n=0;$n<20000;++$n){$p=CommerceResetDeletion::position($r);if($r->status()==='blocked'||$p!==null&&$p[2]==='done'&&$r->validation_generation()===$r->evidence_generation())return $r;
 $out=validation_service($db)->certify_retained($r,$size);if(!in_array($out->code(),['progressed','blocked'],true))throw new RuntimeException('retained '.$out->code());$r=$out->operation();}throw new RuntimeException('retained convergence');
}
function deletion_pass(wpdb $db,CommerceResetRecord $r,int $size=1,?string $stage=null,bool $boundary=false):CommerceResetRecord {
 for($n=0;$n<20000;++$n){$p=CommerceResetDeletion::position($r);if($r->status()==='blocked'||CommerceResetDeletion::certified_empty($r)||$stage!==null&&$p[0]===$stage&&(!$boundary||$p[1]>0))return $r;
 $out=validation_service($db)->delete_operational($r,$size);if(!in_array($out->code(),['progressed','blocked'],true))throw new RuntimeException('erase '.$out->code().' at '.$r->checkpoint());$r=$out->operation();}throw new RuntimeException('erasure convergence');
}
function deletion_setup(wpdb $db,int $orders=3,bool $sparse=false,int $items=2):array {
 validation_schema($db);$t=new TableNames($db);$fixtures=[];
 for($i=0;$i<$orders;++$i){if($sparse){$key=[17,100001,9007199254740993][$i];foreach(CommerceResetDeletion::STAGES as $table)sql($db,'ALTER TABLE '.$t->$table().' AUTO_INCREMENT='.$key);}
 $f=validation_order($db,$items);validation_receipt($db,$f,'evt_erase_'.$i);$fixtures[]=$f;}
 $r=retirement_pass($db,validation_pass($db,validation_start($db)),100);
 return [deletion_retained($db,$r,100),$fixtures];
}
function deletion_stock(wpdb $db):string {return serialize($db->get_results('SELECT * FROM '.$db->postmeta.' ORDER BY meta_id'));}
function deletion_kept(wpdb $db):string {$t=new TableNames($db);$out=[];foreach(['commerce_control','commerce_epochs','provider_claims','order_deletions'] as $m)$out[$m]=$db->get_results('SELECT * FROM '.$t->$m().' ORDER BY id');$out['stock']=deletion_stock($db);$out['products']=$db->get_results('SELECT * FROM '.$db->posts.' ORDER BY ID');return serialize($out);}
