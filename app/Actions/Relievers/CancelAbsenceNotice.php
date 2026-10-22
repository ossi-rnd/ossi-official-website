<?php

namespace App\Actions\Relievers;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\AuditArea;
use App\Models\AbsenceNotice;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The guard can make it after all. Only while no reliever is assigned yet:
 * after that, Operations has already moved someone, so the guard calls them.
 */
final class CancelAbsenceNotice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(AbsenceNotice $notice, User $guard): void
    {
        DB::transaction(function () use ($notice, $guard) {
            $locked = AbsenceNotice::query()->lockForUpdate()->findOrFail($notice->id);

            if ($locked->user_id !== $guard->id) {
                throw ValidationException::withMessages(['notice' => 'You can only cancel your own notice.']);
            }

            if ($locked->isNoShow()) {
                throw ValidationException::withMessages(['notice' => 'Operations raised this when you didn\'t clock in. Clock in now, or call Operations.']);
            }

            if ($locked->status !== AbsenceNoticeStatus::Open) {
                throw ValidationException::withMessages(['notice' => 'A reliever is already assigned. Call Operations if you can report after all.']);
            }

            $locked->forceFill(['status' => AbsenceNoticeStatus::Cancelled])->save();

            $this->audit->record(AuditArea::Operations, 'absence.cancelled', "{$guard->name} cancelled the absence notice for {$locked->duty_date->format('M j, Y')}", $locked, causer: $guard);
        });
    }
}
