<?php

namespace App\Queries\Payroll;

use App\Data\PayslipBreakdown;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\User;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\RateScheduleResolver;
use Illuminate\Support\Collection;

/**
 * The payslip of each employee for a run: the FROZEN record once generated,
 * otherwise a live estimate from the calculator.
 *
 * Both the payroll screen and "My payslips" call this, which is how the spec's
 * "an employee's payslip page and the payroll screen show identical numbers"
 * is guaranteed rather than hoped for.
 */
final class ResolvePayslips
{
    public function __construct(
        private readonly PayslipInputsQuery $inputs,
        private readonly PayslipCalculator $calculator,
        private readonly RateScheduleResolver $rates,
    ) {}

    /**
     * @param  Collection<int, User>  $users  with employeeProfile loaded
     * @return array<int, PayslipBreakdown> keyed by user id
     */
    public function handle(PayrollRun $run, Collection $users): array
    {
        $records = PayslipRecord::query()
            ->where('payroll_run_id', $run->id)
            ->whereIn('user_id', $users->pluck('id')->all())
            ->get()
            ->keyBy('user_id');

        $pending = $users->reject(fn (User $user) => $records->has($user->id));
        $inputs = $this->inputs->handle($run, $pending);
        $rates = $this->rates->forDate($run->start_date);

        $payslips = [];

        foreach ($users as $user) {
            /** @var PayslipRecord|null $record */
            $record = $records->get($user->id);

            $payslips[$user->id] = $record !== null
                ? PayslipBreakdown::fromRecord($record)
                : $this->calculator->calculate($inputs[$user->id], $rates);
        }

        return $payslips;
    }

    public function forUser(PayrollRun $run, User $user): PayslipBreakdown
    {
        $user->loadMissing('employeeProfile');

        return $this->handle($run, collect([$user]))[$user->id];
    }
}
