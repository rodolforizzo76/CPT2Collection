<?php
/** Typed purge outcome. Unknown transaction outcome always requires fresh inspection. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;
final class CommerceBaselinePurgeResult {
 public function __construct(private string $code,private ?CommerceBaselinePurgeRecord $operation=null){}
 public function code():string{return $this->code;}
 public function operation():?CommerceBaselinePurgeRecord{return $this->operation;}
 public function is_success():bool{return in_array($this->code,['prepared','running','ready_for_finalization','progressed','completed','already_completed'],true);}
 public function requires_verification():bool{return $this->code==='transaction_outcome_unknown';}
}
