<?php
/** Pure canonical completed-history predicate shared by inspection and A0. No admission. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class CommerceResetHistory {
	public static function completed(CommerceResetRecord $record,string $installation,int $current,?object $source,?object $target):bool {
		if(!self::completed_record($record,$installation,$current))return false;
		return $source!==null && $target!==null && ProviderClaimRecord::valid_date($source->created_at) && $source->created_at <= $record->started_at() &&
			($target->retired_at===null || ProviderClaimRecord::valid_date($target->retired_at) && $target->retired_at >= $record->completed_at()) &&
			(int)$source->id === $record->source_epoch_id() && $source->identity === $record->source_epoch_identity() && $source->retired_at === $record->completed_at() &&
			$target->identity === $record->target_epoch_identity() && $target->created_at === $record->completed_at() &&
			($current === $record->target_epoch_id() ? $target->retired_at===null : $target->retired_at!==null);
	}
	public static function completed_record(CommerceResetRecord $record,string $installation,int $current):bool {
		if ('z1_n_0_done' !== $record->checkpoint() || 0 !== $record->cursor() || $record->validation_generation() !== $record->evidence_generation() || $record->installation_id() !== $installation || 'completed' !== $record->status() || 'completed' !== $record->phase() || null !== $record->active_slot() || null === $record->target_epoch_id() || $record->target_epoch_id() <= $record->source_epoch_id() || null === $record->completed_at() || null !== $record->error_code() || $record->completed_at() < $record->started_at() || $record->updated_at() !== $record->completed_at() || $current < $record->target_epoch_id()) return false;
		return true;
	}

}
