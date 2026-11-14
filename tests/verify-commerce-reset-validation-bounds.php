<?php
/** Analyze actual A1 range queries, not output LIMITs, and enforce its write boundary. */
declare(strict_types=1);
require __DIR__.'/support/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\TableNames;
class ValidationQueryProbeDb extends wpdb {
 public bool $observe=false; public array $reads=[],$writes=[];
 public function get_results(string $sql):?array {if($this->observe&&str_contains($sql,'FORCE INDEX('))$this->reads[]=$sql;return parent::get_results($sql);}
 public function query(string $sql):int|false {if($this->observe&&preg_match('/\A\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|TRUNCATE)\b/i',$sql))$this->writes[]=$sql;return parent::query($sql);}
}
function validation_examined(array $plan):float {$n=0;foreach($plan as $key=>$value){if($key==='table'&&is_array($value))$n+=($value['r_rows']??0)*($value['r_loops']??0);elseif(is_array($value))$n+=validation_examined($value);}return $n;}
$db=new ValidationQueryProbeDb('c2c_a1_bounds_'.bin2hex(random_bytes(5)).'_');$t=new TableNames($db);
try{
 validation_schema($db);for($i=0;$i<8;++$i)validation_order($db,5);$r=validation_start($db);$before=validation_preserved($db);$db->observe=true;$r=validation_pass($db,$r,1);$db->observe=false;
 check($r->phase()==='retiring_claims','size-one pass traverses eight orders and forty immutable items');
 $maximum=0;$ranges=0;$duplicate=0;
 foreach(array_unique($db->reads) as $sql){$plan=json_decode($db->get_var('ANALYZE FORMAT=JSON '.$sql),true,512,JSON_THROW_ON_ERROR);$examined=validation_examined($plan);$maximum=max($maximum,$examined);$limit=str_ends_with($sql,'LIMIT 2')?2:1;check($examined<=$limit,'actual A1 indexed query examines at most '.$limit.' rows');if(str_contains($sql,'id>'))++$ranges;else++$duplicate;}
 check($ranges>100&&$duplicate>20&&$maximum<=2,'physical scans, total/request child streams and duplicate probes have bounded real access');
 foreach($db->writes as $sql)check(str_starts_with($sql,'UPDATE `'.$t->commerce_resets().'` SET '),'every attempted validator write targets only its reset row');
 check($before===validation_preserved($db),'bounded pass preserves commerce/provider/stock/configuration witnesses');
 echo 'EVIDENCE: '.count(array_unique($db->reads)).' actual indexed query shapes/ranges analyzed; maximum rows examined '.$maximum.".\n";
}finally{baseline_cleanup($db);}
echo "All A1 actual-query bounds checks passed.\n";
