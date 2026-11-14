<?php
declare(strict_types=1);
require_once __DIR__.'/reset-validation.php';
use RodolfoRizzo\Cpt2Collection\{CommerceResetRecord,TableNames};
function retirement_pass(wpdb $db,CommerceResetRecord $r,int $size=1,?string $stop=null):CommerceResetRecord {
 for($n=0;$n<20000;++$n){if($r->status()==='blocked'||$r->phase()==='deleting'||($stop!==null&&$r->checkpoint()===$stop))return $r;$out=validation_service($db)->retire($r,$size);if(!in_array($out->code(),['progressed','blocked'],true))throw new RuntimeException('B '.$out->code().' at '.($r->checkpoint()??$r->phase()));$r=$out->operation();}throw new RuntimeException('B did not converge');
}
function retirement_preserved(wpdb $db):string {
 $rows=unserialize(validation_preserved($db));unset($rows['provider_claims'],$rows['provider_identities']);return serialize($rows);
}
