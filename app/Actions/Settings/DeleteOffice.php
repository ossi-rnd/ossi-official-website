<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\Office;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteOffice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Office $office, User $actor): void
    {
        $assigned = $office->employeeProfiles()->count();

        if ($assigned > 0) {
            throw ValidationException::withMessages(['office' => "{$assigned} staff work at {$office->name}. Move them to another office first."]);
        }

        DB::transaction(function () use ($office, $actor) {
            $this->audit->record(AuditArea::Settings, 'office.deleted', "Deleted office {$office->name} ({$office->locationLabel()})", $office, [
                'old' => [...$office->only(['province', 'name']), 'region' => $office->region->value],
            ], causer: $actor);

            $office->delete();
        });
    }
}
