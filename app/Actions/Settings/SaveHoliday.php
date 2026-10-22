<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\Holiday;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveHoliday
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{date: string, name: string, type: string}  $data
     */
    public function handle(?Holiday $holiday, array $data, User $actor): Holiday
    {
        // whereDate, not Rule::unique: SQLite stores the date cast as "Y-m-d 00:00:00".
        $taken = Holiday::query()
            ->whereDate('date', $data['date'])
            ->when($holiday !== null, fn ($query) => $query->whereKeyNot($holiday?->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['date' => 'A holiday already exists on this date.']);
        }

        return DB::transaction(function () use ($holiday, $data, $actor) {
            $isNew = $holiday === null;
            $holiday ??= new Holiday;
            $before = $isNew ? [] : $holiday->only(['date', 'name', 'type']);

            $holiday->fill($data)->save();

            $this->audit->record(AuditArea::Settings, $isNew ? 'holiday.created' : 'holiday.updated', "Saved holiday {$holiday->name}", $holiday, AuditLogger::diff($before, $holiday->only(['date', 'name', 'type'])), causer: $actor);

            return $holiday;
        });
    }
}
