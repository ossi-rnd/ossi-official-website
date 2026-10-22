<?php

namespace App\Livewire\Admin;

use App\Actions\Load\ReviewLoadRequest;
use App\Enums\Ability;
use App\Enums\LoadRequestStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\LoadRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The Admin's load queue: send the load from the company phone, then
 * approve with a screenshot of the load confirmation (the employee's
 * receipt), which puts the amount on their next payslip. Or reject with a
 * reason.
 *
 * @property-read LengthAwarePaginator<int, LoadRequest> $requests
 * @property-read LoadRequest|null $rejecting
 * @property-read LoadRequest|null $approving
 */
#[Title('Load Requests')]
class LoadRequests extends Component
{
    use InteractsWithUser, WithFileUploads, WithPagination;

    #[Url]
    public string $status = 'open';

    public ?int $rejectingId = null;

    public ?int $approvingId = null;

    /** @var UploadedFile|null */
    public $proof = null;

    public string $reason = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, LoadRequest>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return LoadRequest::query()
            ->with(['user:id,name,email', 'user.employeeProfile:id,user_id'])
            ->when($this->status === 'open', fn ($query) => $query->open())
            ->when(LoadRequestStatus::tryFrom($this->status), fn ($query, LoadRequestStatus $status) => $query->where('status', $status))
            // Oldest first while working the queue; newest first in history.
            ->when($this->status === 'open', fn ($query) => $query->oldest(), fn ($query) => $query->latest())
            ->paginate(20);
    }

    #[Computed]
    public function rejecting(): ?LoadRequest
    {
        return $this->rejectingId !== null ? LoadRequest::query()->with('user:id,name')->find($this->rejectingId) : null;
    }

    #[Computed]
    public function approving(): ?LoadRequest
    {
        return $this->approvingId !== null ? LoadRequest::query()->with('user:id,name')->find($this->approvingId) : null;
    }

    public function startApprove(int $id): void
    {
        $this->authorize(Ability::ManageLoadRequests->value);

        $this->approvingId = LoadRequest::query()->findOrFail($id)->id;
        $this->proof = null;
        $this->resetErrorBag();
    }

    public function cancelApprove(): void
    {
        $this->reset(['approvingId', 'proof']);
    }

    public function approve(ReviewLoadRequest $review): void
    {
        $this->authorize(Ability::ManageLoadRequests->value);

        $this->validate(
            ['proof' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:'.(int) config('load.proof_max_kilobytes')]],
            ['proof.required' => 'Attach a screenshot of the load confirmation: it\'s the employee\'s receipt.'],
            ['proof' => 'screenshot'],
        );

        /** @var UploadedFile $proof */
        $proof = $this->proof;
        $loadRequest = $review->handle(LoadRequest::query()->findOrFail((int) $this->approvingId), LoadRequestStatus::Approved, null, $this->currentUser(), $proof);

        $this->cancelApprove();
        $this->refreshQueue("Approved. {$loadRequest->user->name} has the receipt, and ₱{$loadRequest->amount} will be deducted from their next payslip.");
    }

    public function startReject(int $id): void
    {
        $this->authorize(Ability::ManageLoadRequests->value);

        $this->rejectingId = LoadRequest::query()->findOrFail($id)->id;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancelReject(): void
    {
        $this->reset(['rejectingId', 'reason']);
    }

    public function reject(ReviewLoadRequest $review): void
    {
        $this->authorize(Ability::ManageLoadRequests->value);

        $this->validate(['reason' => ['required', 'string', 'max:500']]);

        $review->handle(LoadRequest::query()->findOrFail((int) $this->rejectingId), LoadRequestStatus::Rejected, $this->reason, $this->currentUser());
        $this->cancelReject();
        $this->refreshQueue('Request rejected. The employee has been told why.');
    }

    public function render(): View
    {
        return view('livewire.admin.load-requests', [
            'filters' => ['open' => 'To do', ...collect(LoadRequestStatus::cases())->mapWithKeys(fn (LoadRequestStatus $status) => [$status->value => $status->label()])->all(), 'all' => 'All'],
        ]);
    }

    private function refreshQueue(string $message): void
    {
        unset($this->requests, $this->approving);
        $this->dispatch('notify', message: $message);
    }
}
