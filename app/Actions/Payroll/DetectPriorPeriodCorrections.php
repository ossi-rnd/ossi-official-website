<?php

namespace App\Actions\Payroll;

use App\Data\PayslipInput;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\PriorPeriodCorrection;
use App\Models\User;
use App\Queries\Payroll\PayslipInputsQuery;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Pay that changed after a payslip was frozen: a day recorded by hand after the
 * cut-off, overtime or leave approved late, a back-dated rate. Without this the
 * difference is simply never paid.
 *
 * For everyone who'll get a payslip on the open run, each of their payslips from
 * cut-offs that ended in the last `payroll.prior_period.lookback_days` is
 * recomputed with today's data (PayslipCalculator::attendancePay, the same rules
 * as the payslip) and compared with what was frozen. What's left after earlier
 * decisions (accepted or dismissed) is suggested on the open run, one per
 * person per cut-off, for Accounting to accept or dismiss. A suggestion that's no
 * longer right (the change was undone) is updated or removed.
 *
 * Only attendance pay is compared. Contributions aren't recomputed, and the
 * correction is taxed as a Correction adjustment on the new payslip.
 */
final class DetectPriorPeriodCorrections
{
    public function __construct(
        private readonly PayslipInputsQuery $inputs,
        private readonly PayslipCalculator $calculator,
        private readonly RateScheduleResolver $rates,
    ) {}

    /**
     * @param  EloquentCollection<int, User>|null  $only  just these employees (one payslip); null = everyone in the run
     * @return int open suggestions on the run (for those checked)
     */
    public function handle(PayrollRun $run, ?EloquentCollection $only = null): int
    {
        if (! $run->status->canGeneratePayslips()) {
            return 0;
        }

        $employees = ($only ?? User::query()
            ->onPayroll()
            ->whereHas('timeLogs', fn ($query) => $query->between($run->start_date, $run->end_date))
            ->whereDoesntHave('payslipRecords', fn ($query) => $query->where('payroll_run_id', $run->id))
            ->get())
            ->loadMissing('employeeProfile')
            ->keyBy('id');

        if ($employees->isEmpty()) {
            return 0;
        }

        $since = CarbonImmutable::parse($run->start_date)->subDays((int) config('payroll.prior_period.lookback_days'))->toDateString();

        $earlier = PayslipRecord::query()
            ->whereIn('user_id', $employees->keys()->all())
            ->whereHas('payrollRun', fn ($query) => $query
                ->whereKeyNot($run->id)
                ->whereDate('end_date', '<', $run->start_date->toDateString())
                ->whereDate('end_date', '>=', $since))
            ->get();

        foreach (PayrollRun::query()->whereKey($earlier->pluck('payroll_run_id')->unique()->all())->orderBy('start_date')->get() as $fromRun) {
            $records = $earlier->where('payroll_run_id', $fromRun->id);
            $people = $employees->only($records->pluck('user_id')->all())->values();
            $inputs = $this->inputs->handle($fromRun, $people);
            $schedule = $this->rates->forDate($fromRun->start_date);

            foreach ($records as $record) {
                $now = $this->calculator->attendancePay($inputs[$record->user_id], $schedule);
                $this->reconcile($run, $fromRun, $record, $now, $this->changes($record, $inputs[$record->user_id], $now));
            }
        }

        return PriorPeriodCorrection::query()->where('payroll_run_id', $run->id)->whereIn('user_id', $employees->keys()->all())->suggested()->count();
    }

    /**
     * @param  array{daily_rate: float, basic: float, holiday: float, overtime: float, night: float, late: float, total: float}  $now
     * @param  array<string, array{0: int|float, 1: int|float}>  $changes
     */
    private function reconcile(PayrollRun $run, PayrollRun $fromRun, PayslipRecord $record, array $now, array $changes): void
    {
        DB::transaction(function () use ($run, $fromRun, $record, $now, $changes) {
            $decided = (float) PriorPeriodCorrection::query()
                ->where('user_id', $record->user_id)
                ->where('from_payroll_run_id', $fromRun->id)
                ->settled()
                ->sum('amount');

            $open = PriorPeriodCorrection::query()
                ->where('payroll_run_id', $run->id)
                ->where('user_id', $record->user_id)
                ->where('from_payroll_run_id', $fromRun->id)
                ->suggested()
                ->lockForUpdate()
                ->first();

            $owed = round($now['total'] - self::frozenTotal($record) - $decided, 2);

            if (abs($owed) < 0.01) {
                $open?->delete();

                return;
            }

            $correction = $open ?? new PriorPeriodCorrection;
            $correction->forceFill([
                'user_id' => $record->user_id,
                'payroll_run_id' => $run->id,
                'from_payroll_run_id' => $fromRun->id,
                'amount' => number_format($owed, 2, '.', ''),
                'differences' => $changes,
            ])->save();
        });
    }

    /**
     * The frozen payslip's attendance pay, measured the same way: the late deduction
     * DUE is what was taken plus any of it carried to the next payslip.
     */
    public static function frozenTotal(PayslipRecord $record): float
    {
        $lateCarried = array_sum(array_map(
            fn (array $line) => $line['label'] === 'Late deduction' ? (float) $line['amount'] : 0.0,
            $record->shortfall_lines ?? [],
        ));

        return round((float) $record->basic_pay + (float) $record->holiday_pay + (float) $record->overtime_pay + (float) $record->night_differential
            - (float) $record->late_deduction - $lateCarried, 2);
    }

    /**
     * What differs between the frozen payslip and today, for the reason line.
     *
     * @param  array{daily_rate: float, basic: float, holiday: float, overtime: float, night: float, late: float, total: float}  $now
     * @return array<string, array{0: int|float, 1: int|float}>
     */
    private function changes(PayslipRecord $record, PayslipInput $input, array $now): array
    {
        // Payslips from before night minutes were saved have 0 with a night differential: not a change.
        $nightUnknown = (int) $record->night_minutes === 0 && (float) $record->night_differential > 0;

        $pairs = [
            'days' => [$record->days_worked, $input->daysWorked],
            'overtime_minutes' => [$record->overtime_minutes, $input->overtimeMinutes],
            'night_minutes' => $nightUnknown ? [0, 0] : [(int) $record->night_minutes, $input->nightMinutes],
            'late_minutes' => [$record->late_minutes, $input->lateMinutes],
            'daily_rate' => [round((float) $record->daily_rate, 2), round($now['daily_rate'], 2)],
            'holiday_pay' => [round((float) $record->holiday_pay, 2), round($now['holiday'], 2)],
        ];

        return array_filter($pairs, fn (array $pair) => $pair[0] != $pair[1]);
    }
}
