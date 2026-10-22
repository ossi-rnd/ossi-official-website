<?php

namespace App\Livewire\Admin;

use App\Actions\Overtime\RecordOvertime;
use App\Actions\Overtime\ReviewOvertimeRequest;
use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Enums\RequestStatus;
use App\Livewire\Concerns\GroupsByStaffKind;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Overtime to decide, and extra time Operations adds for a guard themselves.
 *
 * @property-read Collection<int, User> $guards
 */
#[Title('Overtime Requests')]
class OvertimeRequests extends Component
{
    use GroupsByStaffKind, InteractsWithUser, WithPagination;

    protected function requestModel(): string
    {
        return OvertimeRequest::class;
    }

    #[Url(as: 'status', except: 'pending')]
    public string $statusFilter = 'pending';

    public ?int $reviewingId = null;

    public string $approvedHours = '';

    public string $reviewNote = '';

    /** "Add extra time": Operations records a guard's extra time themselves (RecordOvertime). */
    public bool $addingExtra = false;

    public string $extraGuardId = '';

    public string $extraDate = '';

    public string $extraHours = '';

    public string $extraReason = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /** Those who decide guards' overtime can also add it. */
    public function canAddExtra(): bool
    {
        return $this->currentUser()->can(Ability::ReviewOvertime->value);
    }

    public function startExtra(): void
    {
        abort_unless($this->canAddExtra(), 403);

        $this->reset(['extraGuardId', 'extraHours', 'extraReason']);
        $this->extraDate = today()->toDateString();
        $this->resetErrorBag();
        $this->addingExtra = true;
    }

    public function cancelExtra(): void
    {
        $this->addingExtra = false;
        $this->resetErrorBag();
    }

    public function recordExtra(RecordOvertime $record): void
    {
        abort_unless($this->canAddExtra(), 403);

        $validated = $this->validate([
            'extraGuardId' => ['required', 'integer'],
            'extraDate' => ['required', 'date', 'before_or_equal:today'],
            'extraHours' => ['required', 'numeric', 'min:0.25', 'max:12'],
            'extraReason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['extraGuardId' => 'guard', 'extraDate' => 'date', 'extraHours' => 'extra hours', 'extraReason' => 'reason']);

        // Only a guard this person may see (their region, for a Satellite Operation).
        $guard = $this->guards->firstWhere('id', (int) $validated['extraGuardId']);

        if ($guard === null) {
            $this->addError('extraGuardId', 'Choose a guard from the list.');

            return;
        }

        $overtime = $record->handle($this->currentUser(), $guard, CarbonImmutable::parse($validated['extraDate']), (int) round((float) $validated['extraHours'] * 60), trim($validated['extraReason']));

        $this->addingExtra = false;
        unset($this->requests);
        $this->dispatch('notify', message: "Added {$guard->name}'s extra time on {$overtime->date->format('M j')}. They've been told.");
    }

    /**
     * Active guards this person may add extra time for, by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function guards(): Collection
    {
        return User::query()
            ->visibleTo($this->currentUser())
            ->whereKeyNot($this->currentUser()->id)
            ->whereHas('employeeProfile', fn ($profile) => $profile->where('is_office_staff', false)->whereIn('employment_status', EmploymentStatus::active()))
            ->with('employeeProfile')
            ->orderBy('name')
            ->get();
    }

    public function startReview(int $id): void
    {
        $this->authorize(Ability::MonitorRequests->value);

        $request = OvertimeRequest::query()->visibleTo($this->currentUser())->findOrFail($id);

        if (($reason = ReviewOvertimeRequest::blockedReason($request, $this->currentUser())) !== null) {
            $this->addError('list', $reason);

            return;
        }

        $this->reviewingId = $request->id;
        $this->approvedHours = (string) round($request->requested_minutes / 60, 2);
        $this->reviewNote = '';
        $this->resetErrorBag();
    }

    public function cancelReview(): void
    {
        $this->reset(['reviewingId', 'approvedHours', 'reviewNote']);
        $this->resetErrorBag();
    }

    public function approve(ReviewOvertimeRequest $review): void
    {
        $this->authorize(Ability::MonitorRequests->value);

        $validated = $this->validate([
            'approvedHours' => ['required', 'numeric', 'min:0.25', 'max:12'],
            'reviewNote' => ['nullable', 'string', 'max:1000'],
        ], [], ['approvedHours' => 'approved hours']);

        $this->decide($review, RequestStatus::Approved, (int) round((float) $validated['approvedHours'] * 60));
    }

    public function reject(ReviewOvertimeRequest $review): void
    {
        $this->authorize(Ability::MonitorRequests->value);
        $this->validate(['reviewNote' => ['nullable', 'string', 'max:1000']]);

        $this->decide($review, RequestStatus::Rejected, null);
    }

    private function decide(ReviewOvertimeRequest $review, RequestStatus $decision, ?int $approvedMinutes): void
    {
        $review->handle(OvertimeRequest::query()->visibleTo($this->currentUser())->findOrFail($this->reviewingId), $this->currentUser(), $decision, $this->reviewNote !== '' ? $this->reviewNote : null, $approvedMinutes);

        $this->cancelReview();
        unset($this->requests, $this->pendingCount);
        $this->dispatch('notify', message: 'Overtime request '.$decision->value.'.');
    }

    /**
     * @return LengthAwarePaginator<int, OvertimeRequest>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return OvertimeRequest::query()
            ->with(['user.employeeProfile', 'supervisor', 'reviewer', 'filer'])
            ->visibleTo($this->currentUser())
            ->tap(fn ($query) => $this->inShownGroup($query))
            ->when(RequestStatus::tryFrom($this->statusFilter), fn ($query, RequestStatus $status) => $query->where('status', $status))
            ->latest('date')
            ->paginate(20);
    }

    #[Computed]
    public function reviewing(): ?OvertimeRequest
    {
        return $this->reviewingId !== null ? OvertimeRequest::query()->with(['user.employeeProfile', 'supervisor'])->find($this->reviewingId) : null;
    }

    #[Computed]
    public function pendingCount(): int
    {
        return OvertimeRequest::query()->pending()->visibleTo($this->currentUser())->count();
    }

    public function render(): View
    {
        return view('livewire.admin.overtime-requests');
    }
}
