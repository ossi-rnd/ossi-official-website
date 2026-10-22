<?php

namespace App\Livewire\Payroll;

use App\Actions\Payroll\AddPayrollAdjustment;
use App\Actions\Payroll\RemovePayrollAdjustment;
use App\Actions\Payroll\ReviewPayrollAdjustment;
use App\Enums\Ability;
use App\Enums\AdjustmentCategory;
use App\Enums\AdjustmentStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Adjustments" panel inside the payroll payslip modal: its own component
 * so the large Payroll page doesn't grow another form. It tells the page to
 * re-read the payslip through the `adjustments-changed` event.
 *
 * @property-read PayrollRun $run
 * @property-read Collection<int, PayrollAdjustment> $adjustments
 * @property-read bool $isGenerated
 * @property-read bool $canAdd
 */
class PayslipAdjustments extends Component
{
    use InteractsWithUser;

    #[Locked]
    public int $runId;

    #[Locked]
    public int $userId;

    public string $adjustmentCategory = 'correction';

    /** add | deduct */
    public string $adjustmentDirection = 'add';

    public string $adjustmentAmount = '';

    public string $adjustmentReason = '';

    public ?int $rejectingId = null;

    public string $reviewNote = '';

    public function mount(int $runId, int $userId): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $this->runId = $runId;
        $this->userId = $userId;
    }

    #[Computed]
    public function run(): PayrollRun
    {
        return PayrollRun::query()->findOrFail($this->runId);
    }

    /**
     * @return Collection<int, PayrollAdjustment>
     */
    #[Computed]
    public function adjustments(): Collection
    {
        return PayrollAdjustment::query()
            ->where('payroll_run_id', $this->runId)
            ->where('user_id', $this->userId)
            ->with(['creator:id,name', 'reviewer:id,name'])
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function isGenerated(): bool
    {
        return PayslipRecord::query()->where('payroll_run_id', $this->runId)->where('user_id', $this->userId)->exists();
    }

    #[Computed]
    public function canAdd(): bool
    {
        return $this->run->status->canGeneratePayslips() && ! $this->isGenerated;
    }

    /**
     * A penalty can only be deducted, an allowance or refund only added:
     * flip the effect so the form never offers an impossible combination.
     */
    public function updatedAdjustmentCategory(): void
    {
        $category = AdjustmentCategory::tryFrom($this->adjustmentCategory);

        if ($category !== null && $this->adjustmentDirection === 'add' && ! $category->allowsAddition()) {
            $this->adjustmentDirection = 'deduct';
        } elseif ($category !== null && $this->adjustmentDirection === 'deduct' && ! $category->allowsDeduction()) {
            $this->adjustmentDirection = 'add';
        }
    }

    public function add(AddPayrollAdjustment $addAdjustment): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $validated = $this->validate([
            'adjustmentCategory' => ['required', Rule::enum(AdjustmentCategory::class)],
            'adjustmentDirection' => ['required', Rule::in(['add', 'deduct'])],
            'adjustmentAmount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'adjustmentReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['adjustmentAmount' => 'amount', 'adjustmentReason' => 'reason']);

        $amount = (float) $validated['adjustmentAmount'] * ($validated['adjustmentDirection'] === 'deduct' ? -1 : 1);

        $adjustment = $addAdjustment->handle(
            $this->run,
            User::query()->onPayroll()->findOrFail($this->userId),
            AdjustmentCategory::from($validated['adjustmentCategory']),
            $amount,
            $validated['adjustmentReason'],
            $this->currentUser(),
        );

        $this->reset(['adjustmentAmount', 'adjustmentReason']);
        $this->changed($adjustment->status === AdjustmentStatus::Pending
            ? 'Adjustment saved. It waits for a Super Admin to approve it before it reaches the payslip.'
            : 'Adjustment added to the payslip.');
    }

    public function approve(int $id, ReviewPayrollAdjustment $review): void
    {
        $review->handle($this->find($id), true, null, $this->currentUser());
        $this->changed('Adjustment approved.');
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $this->find($id)->id;
        $this->reviewNote = '';
        $this->resetErrorBag();
    }

    public function reject(ReviewPayrollAdjustment $review): void
    {
        $this->validate(['reviewNote' => ['required', 'string', 'max:500']], attributes: ['reviewNote' => 'reason']);

        $review->handle($this->find((int) $this->rejectingId), false, $this->reviewNote, $this->currentUser());
        $this->reset(['rejectingId', 'reviewNote']);
        $this->changed('Adjustment rejected.');
    }

    public function remove(int $id, RemovePayrollAdjustment $remove): void
    {
        $remove->handle($this->find($id), $this->currentUser());
        $this->changed('Adjustment removed.');
    }

    public function render(): View
    {
        return view('livewire.payroll.payslip-adjustments', [
            'categories' => AdjustmentCategory::cases(),
            // null = no second-person check (the default): no approval hint is shown.
            'threshold' => config('payroll.adjustments.approval_threshold') !== null ? (float) config('payroll.adjustments.approval_threshold') : null,
        ]);
    }

    /**
     * Looked up THROUGH this run and employee, so a forged id is simply not found.
     */
    private function find(int $id): PayrollAdjustment
    {
        return PayrollAdjustment::query()->where('payroll_run_id', $this->runId)->where('user_id', $this->userId)->findOrFail($id);
    }

    private function changed(string $message): void
    {
        unset($this->adjustments, $this->isGenerated, $this->canAdd);
        $this->resetErrorBag();
        $this->dispatch('adjustments-changed');
        $this->dispatch('notify', message: $message);
    }
}
