<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\DeleteMinimumWage;
use App\Actions\Payroll\DeleteStatutoryRuleSet;
use App\Actions\Payroll\SaveMinimumWage;
use App\Actions\Payroll\SaveStatutoryRuleSet;
use App\Data\AdjustmentLine;
use App\Data\MonthToDate;
use App\Data\PayslipBreakdown;
use App\Data\PayslipInput;
use App\Data\RateSchedule;
use App\Data\StatutoryRules;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\MinimumWage;
use App\Models\StatutoryRuleSet;
use App\Queries\Payroll\FrozenPayrollUntilQuery;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tax & Contributions: Accounting sees the rules in force and maintains them
 * as dated versions. Rates are typed as percentages (5) and stored as
 * fractions (0.05), the way the formulas use them.
 *
 * @property-read StatutoryRules|null $current
 * @property-read StatutoryRuleSet|null $currentVersion
 * @property-read Collection<int, StatutoryRuleSet> $versions
 * @property-read array<string, array{amount: float, wage_order: string|null, saved: bool}> $minimumWages
 * @property-read CarbonImmutable|null $frozenUntil
 * @property-read array{first: PayslipBreakdown, second: PayslipBreakdown, rates: RateSchedule}|null $calculation
 */
#[Title('Tax & Contributions')]
class TaxSettings extends Component
{
    use InteractsWithUser;

    /** Fields that are typed as percentages. */
    private const PERCENT = [
        'sss' => ['employee_rate', 'employer_rate'],
        'philhealth' => ['rate'],
        'pagibig' => ['employee_rate', 'employee_rate_low', 'employer_rate'],
    ];

    public bool $editing = false;

    public string $effectiveFrom = '';

    public string $note = '';

    /** @var array{sss: array<string, string>, philhealth: array<string, string>, pagibig: array<string, string>, withholding_semi_monthly: list<array{over: string, base: string, rate: string}>} */
    public array $rules = ['sss' => [], 'philhealth' => [], 'pagibig' => [], 'withholding_semi_monthly' => []];

    /** rules | law: the configuration, or how Philippine law turns it into deductions. */
    #[Url]
    public string $tab = 'rules';

    // ---- "How it's computed" calculator ------------------------------------

    public string $calcDailyRate = '755';

    public string $calcDays = '26';

    public string $calcOtherPay = '0';

    public string $calcDate = '';

    public string $wageFrom = '';

    public string $wageAmount = '';

    public string $wageOrder = '';

    public function mount(): void
    {
        $this->authorize(Ability::ManagePayroll->value);
        $this->calcDate = CarbonImmutable::today()->toDateString();
    }

    /**
     * One month worked at a daily rate, as the two semi-monthly payslips
     * payroll would make. Runs the REAL PayslipCalculator (first cutoff, then
     * the last cutoff balancing against it), so this can never disagree with
     * an actual payslip. Other taxable pay (overtime, holiday premium) is
     * split evenly and treated like a taxable correction.
     *
     * @return array{first: PayslipBreakdown, second: PayslipBreakdown, rates: RateSchedule}|null
     */
    #[Computed]
    public function calculation(): ?array
    {
        $rate = (float) $this->calcDailyRate;
        $days = (int) $this->calcDays;

        if ($rate <= 0 || $rate > 100000 || $days < 1 || $days > 31 || strtotime($this->calcDate) === false) {
            return null;
        }

        $rates = app(RateScheduleResolver::class)->forDate(CarbonImmutable::parse($this->calcDate));

        if (! $rates->usesStatutoryFormulas()) {
            return null;
        }

        $calculator = app(PayslipCalculator::class);
        $otherHalf = round(max(0.0, (float) $this->calcOtherPay) / 2, 2);
        $input = fn (int $cutoffDays, MonthToDate $month) => new PayslipInput(
            isOfficeStaff: false,
            nightMinutes: 0,
            daysWorked: $cutoffDays,
            lateMinutes: 0,
            overtimeMinutes: 0,
            adjustments: $otherHalf > 0 ? [new AdjustmentLine(0, 'Other taxable pay (overtime, holiday premium)', $otherHalf, true)] : [],
            monthToDate: $month,
            dailyRate: $rate,
        );

        $first = $calculator->calculate($input((int) ceil($days / 2), new MonthToDate(isFinalCutoff: false)), $rates);
        $c = $first->contributions;
        $second = $calculator->calculate($input(intdiv($days, 2), $c === [] ? MonthToDate::wholeMonth() : new MonthToDate(
            isFinalCutoff: true,
            sss: ['base' => $c['sss']['base'], 'employee' => $c['sss']['employee'], 'employer' => $c['sss']['employer'], 'ec' => $c['sss']['ec']],
            philhealth: ['base' => $c['philhealth']['base'], 'employee' => $c['philhealth']['employee'], 'employer' => $c['philhealth']['employer'], 'ec' => 0.0],
            pagibig: ['base' => $c['pagibig']['base'], 'employee' => $c['pagibig']['employee'], 'employer' => $c['pagibig']['employer'], 'ec' => 0.0],
        )), $rates);

        return ['first' => $first, 'second' => $second, 'rates' => $rates];
    }

    #[Computed]
    public function current(): ?StatutoryRules
    {
        return app(RateScheduleResolver::class)->forDate(CarbonImmutable::today())->statutory;
    }

    #[Computed]
    public function currentVersion(): ?StatutoryRuleSet
    {
        return app(RateScheduleResolver::class)->versionFor(CarbonImmutable::today()->toDateString());
    }

    /**
     * @return Collection<int, StatutoryRuleSet>
     */
    #[Computed]
    public function versions(): Collection
    {
        return StatutoryRuleSet::query()->with('creator:id,name')->orderByDesc('effective_from')->get();
    }

    /**
     * @return array<string, array{amount: float, wage_order: string|null, saved: bool}>
     */
    #[Computed]
    public function minimumWages(): array
    {
        return array_reverse(app(RateScheduleResolver::class)->minimumWages(), true);
    }

    #[Computed]
    public function frozenUntil(): ?CarbonImmutable
    {
        return app(FrozenPayrollUntilQuery::class)->handle();
    }

    public function startVersion(): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $from = $this->frozenUntil?->addDay() ?? CarbonImmutable::today();
        $this->effectiveFrom = $from->max(CarbonImmutable::today())->toDateString();
        $this->note = '';
        $this->rules = $this->toForm($this->current ?? app(RateScheduleResolver::class)->forDate(CarbonImmutable::parse('2026-01-01'))->statutory);
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function addBracket(): void
    {
        $this->rules['withholding_semi_monthly'] = [...$this->rules['withholding_semi_monthly'], ['over' => '', 'base' => '', 'rate' => '']];
    }

    public function removeBracket(int $index): void
    {
        $this->rules['withholding_semi_monthly'] = array_values(array_filter(
            $this->rules['withholding_semi_monthly'],
            fn (int $position) => $position !== $index,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function saveVersion(SaveStatutoryRuleSet $save): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $number = ['required', 'numeric', 'min:0', 'max:10000000'];
        $percent = ['required', 'numeric', 'min:0', 'max:100'];

        $this->validate([
            'effectiveFrom' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'rules.sss.*' => $number,
            'rules.philhealth.*' => $number,
            'rules.pagibig.*' => $number,
            'rules.withholding_semi_monthly' => ['required', 'array', 'min:1'],
            'rules.withholding_semi_monthly.*.over' => $number,
            'rules.withholding_semi_monthly.*.base' => $number,
            'rules.withholding_semi_monthly.*.rate' => $percent,
        ], [], [
            'rules.withholding_semi_monthly.*.over' => 'bracket start',
            'rules.withholding_semi_monthly.*.base' => 'bracket base tax',
            'rules.withholding_semi_monthly.*.rate' => 'bracket rate',
        ]);

        $rules = ['sss' => [], 'philhealth' => [], 'pagibig' => [], 'withholding_semi_monthly' => []];

        foreach (['sss', 'philhealth', 'pagibig'] as $agency) {
            foreach ($this->rules[$agency] as $key => $value) {
                $rules[$agency][$key] = in_array($key, self::PERCENT[$agency], true) ? round((float) $value / 100, 6) : (float) $value;
            }
        }

        foreach ($this->rules['withholding_semi_monthly'] as $bracket) {
            $rules['withholding_semi_monthly'][] = ['over' => (float) $bracket['over'], 'base' => (float) $bracket['base'], 'rate' => round((float) $bracket['rate'] / 100, 6)];
        }

        $version = $save->handle($rules, CarbonImmutable::parse($this->effectiveFrom), $this->note ?: null, $this->currentUser());

        $this->editing = false;
        $this->refreshComputed();
        $this->dispatch('notify', message: "Rules saved. Payroll periods from {$version->effective_from->format('M j, Y')} use them.");
    }

    public function deleteVersion(int $id, DeleteStatutoryRuleSet $delete): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $delete->handle(StatutoryRuleSet::query()->findOrFail($id), $this->currentUser());
        $this->refreshComputed();
        $this->dispatch('notify', message: 'Version deleted.');
    }

    public function saveWage(SaveMinimumWage $save): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $validated = $this->validate([
            'wageFrom' => ['required', 'date'],
            'wageAmount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'wageOrder' => ['nullable', 'string', 'max:60'],
        ], attributes: ['wageFrom' => 'effective date', 'wageAmount' => 'daily minimum wage', 'wageOrder' => 'wage order']);

        $save->handle(CarbonImmutable::parse($validated['wageFrom']), (float) $validated['wageAmount'], $validated['wageOrder'] ?: null, $this->currentUser());

        $this->reset(['wageFrom', 'wageAmount', 'wageOrder']);
        $this->refreshComputed();
        $this->dispatch('notify', message: 'Minimum wage saved.');
    }

    public function deleteWage(string $from, DeleteMinimumWage $delete): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $delete->handle(MinimumWage::query()->whereDate('effective_from', $from)->firstOrFail(), $this->currentUser());
        $this->refreshComputed();
        $this->dispatch('notify', message: 'Minimum wage deleted.');
    }

    public function render(): View
    {
        return view('livewire.admin.tax-settings');
    }

    /**
     * @return array{sss: array<string, string>, philhealth: array<string, string>, pagibig: array<string, string>, withholding_semi_monthly: list<array{over: string, base: string, rate: string}>}
     */
    private function toForm(?StatutoryRules $rules): array
    {
        $pct = fn (float $fraction): string => rtrim(rtrim(number_format($fraction * 100, 4, '.', ''), '0'), '.');
        $num = fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        if ($rules === null) {
            return ['sss' => [], 'philhealth' => [], 'pagibig' => [], 'withholding_semi_monthly' => []];
        }

        return [
            'sss' => [
                'employee_rate' => $pct($rules->sssEmployeeRate), 'employer_rate' => $pct($rules->sssEmployerRate),
                'msc_min' => $num($rules->sssMscMin), 'msc_max' => $num($rules->sssMscMax), 'msc_step' => $num($rules->sssMscStep),
                'ec_low' => $num($rules->sssEcLow), 'ec_high' => $num($rules->sssEcHigh), 'ec_high_from_msc' => $num($rules->sssEcHighFromMsc),
            ],
            'philhealth' => ['rate' => $pct($rules->philhealthRate), 'floor' => $num($rules->philhealthFloor), 'ceiling' => $num($rules->philhealthCeiling)],
            'pagibig' => [
                'employee_rate' => $pct($rules->pagibigEmployeeRate), 'employee_rate_low' => $pct($rules->pagibigEmployeeRateLow),
                'low_threshold' => $num($rules->pagibigLowThreshold), 'employer_rate' => $pct($rules->pagibigEmployerRate), 'max_fund_salary' => $num($rules->pagibigMaxFundSalary),
            ],
            'withholding_semi_monthly' => array_map(fn (array $bracket) => ['over' => $num($bracket['over']), 'base' => $num($bracket['base']), 'rate' => $pct($bracket['rate'])], $rules->withholdingSemiMonthly),
        ];
    }

    private function refreshComputed(): void
    {
        unset($this->current, $this->currentVersion, $this->versions, $this->minimumWages, $this->frozenUntil);
    }
}
