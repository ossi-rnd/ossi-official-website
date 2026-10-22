<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRate;
use App\Models\PayslipRecord;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sets an employee's daily rate from a date (HR only sees it). A raise is a new row; the
 * same date again replaces that row (a typo fix), unless a generated payslip
 * already used the period: then the change must start later.
 */
final class SetEmployeeRate
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EmployeeProfile $profile, float $dailyRate, CarbonImmutable $effectiveFrom, ?string $note, User $actor): EmployeeRate
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if ($dailyRate <= 0 || $dailyRate > 100000) {
            throw ValidationException::withMessages(['rateAmount' => 'Enter a daily rate between ₱1 and ₱100,000.']);
        }

        // A payslip already frozen with this employee's rate must not be contradicted.
        $frozenFrom = $profile->user_id === null ? null : PayslipRecord::query()
            ->where('user_id', $profile->user_id)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslip_records.payroll_run_id')
            ->whereDate('payroll_runs.end_date', '>=', $effectiveFrom->toDateString())
            ->max('payroll_runs.end_date');

        if ($frozenFrom !== null) {
            $after = CarbonImmutable::parse((string) $frozenFrom)->addDay();

            throw ValidationException::withMessages(['rateEffectiveFrom' => "A payslip up to {$after->subDay()->format('M j, Y')} is already generated with the old rate. Start the new rate on {$after->format('M j, Y')} or later."]);
        }

        return DB::transaction(function () use ($profile, $dailyRate, $effectiveFrom, $note, $actor) {
            $rate = EmployeeRate::query()
                ->where('employee_profile_id', $profile->id)
                ->whereDate('effective_from', $effectiveFrom->toDateString())
                ->lockForUpdate()
                ->first() ?? new EmployeeRate;

            $previous = $rate->exists ? (float) $rate->daily_rate : null;

            $rate->forceFill([
                'employee_profile_id' => $profile->id,
                'daily_rate' => number_format($dailyRate, 2, '.', ''),
                'effective_from' => $effectiveFrom->toDateString(),
                'note' => filled($note) ? $note : null,
                'set_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditArea::People, 'rate.set', "Daily rate of {$profile->name}: ₱".number_format($dailyRate, 2)." from {$effectiveFrom->format('M j, Y')}", $rate, [
                'old' => ['daily_rate' => $previous],
                'new' => ['daily_rate' => $dailyRate, 'effective_from' => $effectiveFrom->toDateString()],
                'note' => $rate->note,
            ], causer: $actor);

            UpdateNotice::tellProfile($profile, $actor, 'Your daily rate: ₱'.number_format($dailyRate, 2),
                "From {$effectiveFrom->format('M j, Y')}. Your payslips use it from the cut-off that starts then.", route('payslips'), 'banknotes', email: true);

            return $rate;
        });
    }
}
