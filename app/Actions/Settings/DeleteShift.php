<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteShift
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Shift $shift, User $actor): void
    {
        $assigned = $shift->employeeProfiles()->count();

        if ($assigned > 0) {
            throw ValidationException::withMessages(['shift' => "{$assigned} employee(s) are on this shift. Move them to another shift first."]);
        }

        DB::transaction(function () use ($shift, $actor) {
            $this->audit->record(AuditArea::Settings, 'shift.deleted', "Deleted shift {$shift->name}", $shift, ['old' => $shift->only(['name', 'start_time', 'end_time', 'grace_minutes'])], causer: $actor);

            $shift->delete();
        });
    }
}
