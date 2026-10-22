<?php

namespace App\Livewire\Payroll;

use App\Actions\Payroll\FilePayrollConcern;
use App\Enums\PayrollConcernCategory;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PayrollConcern;
use App\Models\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Under the employee's own payslip: "Something wrong? Report a problem", and
 * what they reported on it with Accounting's answer. Its own component so My
 * Payslips stays a reader.
 *
 * @property-read Collection<int, PayrollConcern> $concerns
 */
class PayslipConcerns extends Component
{
    use InteractsWithUser, WithFileUploads;

    #[Locked]
    public int $runId;

    public bool $reporting = false;

    public string $category = 'missing_days';

    public string $details = '';

    /** @var UploadedFile|null */
    public $attachment = null;

    public function mount(int $runId): void
    {
        // Their own visible payslips only: drafts never show on My Payslips.
        $this->runId = PayrollRun::query()->visibleToEmployees()->findOrFail($runId)->id;
    }

    /**
     * @return Collection<int, PayrollConcern>
     */
    #[Computed]
    public function concerns(): Collection
    {
        return PayrollConcern::query()
            ->where('user_id', $this->currentUser()->id)
            ->where('payroll_run_id', $this->runId)
            ->with('adjustment.run')
            ->latest('id')
            ->get();
    }

    public function report(FilePayrollConcern $file): void
    {
        $validated = $this->validate([
            'category' => ['required', Rule::enum(PayrollConcernCategory::class)],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [
            'details.required' => 'Say what\'s wrong, with the dates.',
            'details.min' => 'Say what\'s wrong, with the dates (a sentence or two).',
            'attachment.mimes' => 'Attach a photo (JPG, PNG) or a PDF.',
            'attachment.max' => 'The file is too big (5 MB at most).',
        ]);

        $file->handle(
            $this->currentUser(),
            PayrollRun::query()->findOrFail($this->runId),
            PayrollConcernCategory::from($validated['category']),
            $validated['details'],
            $this->attachment instanceof UploadedFile ? $this->attachment : null,
        );

        $this->reset(['reporting', 'details', 'attachment']);
        unset($this->concerns);
        $this->dispatch('notify', message: 'Sent to Accounting. You\'ll be told here and by email when it\'s answered.');
    }

    public function render(): View
    {
        return view('livewire.payroll.payslip-concerns', ['categories' => PayrollConcernCategory::cases()]);
    }
}
