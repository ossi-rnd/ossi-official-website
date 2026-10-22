<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\ThirteenthMonthStatus;
use App\Models\ThirteenthMonthRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a draft 13th month run. Approved and paid runs stay: they are
 * what employees were told and paid.
 */
final class DeleteThirteenthMonthRun
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ThirteenthMonthRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor) {
            $locked = ThirteenthMonthRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== ThirteenthMonthStatus::Draft) {
                throw ValidationException::withMessages(['run' => 'Only a draft can be deleted.']);
            }

            $this->audit->record(AuditArea::Payroll, 'thirteenth_month.deleted', "Deleted draft {$locked->label()}", $locked, [
                'total' => (float) $locked->payouts()->sum('amount'),
            ], causer: $actor);

            $locked->delete();
        });
    }
}
