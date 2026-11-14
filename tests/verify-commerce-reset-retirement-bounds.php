<?php
/** Real MariaDB access bounds and attempted-write boundary for B and later-phase validation. */
declare(strict_types=1);
require __DIR__.'/support/reset-retirement.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
class RetirementQueryDb extends wpdb {
 public bool $observe=false;public array $reads=[],$writes=[];
 public function get_results(string $q):?array{if($this->observe&&str_contains($q,'FORCE INDEX('))$this->reads[]=$q;return parent::get_results($q);}
 public function query(string $q):int|false{if($this->observe&&preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|TRUNCATE)\b/i',$q))$this->writes[]=$q;return parent::query($q);}
}
function retirement_examined(array $p):float{$n=0;foreach($p as $k=>$v){if($k==='table'&&is_array($v))$n+=($v['r_rows']??0)*($v['r_loops']??0);elseif(is_array($v))$n+=retirement_examined($v);}return $n;}
$db=new RetirementQueryDb('c2c_b_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);try{
 validation_schema($db);for($i=0;$i<8;++$i){$f=validation_order($db,3);validation_receipt($db,$f,'evt_bounds_'.$i);}$r=validation_pass($db,validation_start($db));$r=validation_service($db)->retire($r,1)->operation();$r=validation_bind($db,$r,$f['claim'],'event','evt_generation_bounds',hash('sha256','bounds'),'checkout.session.completed');$before=retirement_preserved($db);$db->observe=true;$r=retirement_pass($db,$r,1);$db->observe=false;
 check($r->phase()==='deleting','size-one B with real generation invalidation terminates');$maximum=0;
 foreach(array_unique($db->reads)as$q){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$q),true,512,JSON_THROW_ON_ERROR);$n=retirement_examined($plan);$maximum=max($maximum,$n);$bound=str_ends_with($q,'LIMIT 2')?2:1;check($n<=$bound,'indexed B/revalidation range examines at most '.$bound.' rows');}
 foreach($db->writes as$q){check(!preg_match('/\A\s*(DELETE|REPLACE|ALTER|TRUNCATE)\b/i',$q),'B attempts no operational deletion or DDL');check(str_contains($q,$t->commerce_resets())||str_contains($q,$t->provider_claims())||str_contains($q,$t->provider_identities()),'every attempted B write belongs to reset or durable provider evidence');}
 check($before===retirement_preserved($db),'query-bound witness retains commerce, stock, products and epoch');echo 'EVIDENCE: '.count(array_unique($db->reads)).' B and revalidation range queries; max examined '.$maximum.".\n";
}finally{baseline_cleanup($db);}
echo "All B query/write bounds checks passed.\n";
