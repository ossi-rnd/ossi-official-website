<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\Holiday;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class DeleteHoliday
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Holiday $holiday, User $actor): void
    {
        DB::transaction(function () use ($holiday, $actor) {
            $this->audit->record(AuditArea::Settings, 'holiday.deleted', "Deleted holiday {$holiday->name}", $holiday, ['old' => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]], causer: $actor);

            $holiday->delete();
        });
    }
}
