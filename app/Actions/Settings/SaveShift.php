<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class SaveShift
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, start_time: string, end_time: string, grace_minutes: int, is_default: bool}  $data
     */
    public function handle(?Shift $shift, array $data, User $actor): Shift
    {
        return DB::transaction(function () use ($shift, $data, $actor) {
            $isNew = $shift === null;
            $shift ??= new Shift;
            $before = $isNew ? [] : $shift->only(array_keys($data));

            // Exactly one default shift: clear the others in the same transaction.
            if ($data['is_default']) {
                Shift::query()->where('is_default', true)->when(! $isNew, fn ($query) => $query->whereKeyNot($shift->id))->update(['is_default' => false]);
            }

            $shift->fill($data)->save();

            $this->audit->record(AuditArea::Settings, $isNew ? 'shift.created' : 'shift.updated', "Saved shift {$shift->name}", $shift, AuditLogger::diff($before, $shift->only(array_keys($data))), causer: $actor);

            return $shift;
        });
    }
}
