<?php

namespace App\Livewire\Admin;

use App\Actions\Complaints\RequestComplaintProof;
use App\Actions\Complaints\SaveComplaintRules;
use App\Actions\Complaints\SaveComplaintSubject;
use App\Actions\Complaints\UpdateComplaint;
use App\Enums\Ability;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Complaint;
use App\Models\ComplaintSubject;
use App\Support\ComplaintRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Complaint cases, confidential. HR handles everyone's except HR staff's own,
 * which only the President or Vice President sees (Complaint::scopeHandledBy): one page, each
 * handler seeing their own cases.
 *
 * @property-read LengthAwarePaginator<int, Complaint> $complaints
 * @property-read Complaint|null $opened
 * @property-read Collection<int, ComplaintSubject> $subjectList
 */
#[Title('Complaints')]
class Complaints extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(as: 'status', except: 'active')]
    public string $statusFilter = 'active';

    /** The one opened, from a list click or a link (?open=, e.g. from a resignation). */
    #[Url(as: 'open', except: null)]
    public ?int $openedId = null;

    public string $status = '';

    public string $resolution = '';

    /** Asking the complainant for proof: the box is open, and what would help. */
    public bool $askingProof = false;

    public string $proofNote = '';

    // Subjects: HR's list of common subjects per category (SaveComplaintSubject).
    public bool $managingSubjects = false;

    public ?int $subjectEditingId = null;

    public string $subjectCategory = '';

    public string $subjectLabel = '';

    public function openSubjects(): void
    {
        abort_unless($this->canEditRules(), 403);

        $this->reset(['subjectEditingId', 'subjectCategory', 'subjectLabel']);
        $this->resetErrorBag();
        $this->managingSubjects = true;
    }

    public function closeSubjects(): void
    {
        $this->reset(['managingSubjects', 'subjectEditingId', 'subjectCategory', 'subjectLabel']);
    }

    public function editSubject(int $id): void
    {
        abort_unless($this->canEditRules(), 403);

        $subject = ComplaintSubject::query()->findOrFail($id);
        $this->subjectEditingId = $subject->id;
        $this->subjectCategory = $subject->category->value;
        $this->subjectLabel = $subject->label;
        $this->resetErrorBag();
    }

    public function saveSubject(SaveComplaintSubject $save): void
    {
        $validated = $this->validate([
            'subjectCategory' => ['required', Rule::enum(ComplaintCategory::class)],
            'subjectLabel' => ['required', 'string', 'min:3', 'max:150'],
        ], [], ['subjectCategory' => 'category', 'subjectLabel' => 'subject']);

        $existing = $this->subjectEditingId !== null ? ComplaintSubject::query()->findOrFail($this->subjectEditingId) : null;
        $save->handle($this->currentUser(), $existing, ComplaintCategory::from($validated['subjectCategory']), $validated['subjectLabel'], $existing->is_active ?? true);

        $this->reset(['subjectEditingId', 'subjectLabel']);
        unset($this->subjectList);
        $this->dispatch('notify', message: $existing !== null ? 'Subject renamed. Complaints already filed keep their words.' : 'Subject added. Employees can pick it now.');
    }

    /** Switched off: not offered any more; complaints that used it keep it. */
    public function toggleSubject(int $id, SaveComplaintSubject $save): void
    {
        abort_unless($this->canEditRules(), 403);

        $subject = ComplaintSubject::query()->findOrFail($id);
        $save->handle($this->currentUser(), $subject, $subject->category, $subject->label, ! $subject->is_active);
        unset($this->subjectList);
    }

    /**
     * Every subject, by category, with how often it came up in the last 90 days.
     *
     * @return Collection<int, ComplaintSubject>
     */
    #[Computed]
    public function subjectList(): Collection
    {
        return ComplaintSubject::query()
            // Only the cases this person handles: HR never counts HR staff's complaints.
            ->withCount(['complaints as recent_count' => fn ($query) => $query->handledBy($this->currentUser())->where('created_at', '>=', now()->subDays(90))])
            ->ordered()
            ->get()
            ->sortBy(fn (ComplaintSubject $subject) => array_search($subject->category, ComplaintCategory::cases(), true))
            ->values();
    }

    // Rules: HR sets the weekly limit (SaveComplaintRules); the President or Vice President sees it.
    public bool $editingRules = false;

    public string $perWeek = '';

    public function canEditRules(): bool
    {
        return $this->currentUser()->can(Ability::HandleComplaints->value);
    }

    public function editRules(): void
    {
        abort_unless($this->canEditRules(), 403);

        $this->perWeek = (string) ComplaintRules::perWeek();
        $this->resetErrorBag();
        $this->editingRules = true;
    }

    public function saveRules(SaveComplaintRules $save): void
    {
        $validated = $this->validate(['perWeek' => ['required', 'integer', 'min:0', 'max:10']], [], ['perWeek' => 'complaints per week']);

        $save->handle($this->currentUser(), (int) $validated['perWeek']);

        $this->editingRules = false;
        $this->dispatch('notify', message: 'Complaint rules saved. My Complaints uses them from now on.');
    }

    public function mount(): void
    {
        abort_unless(Complaint::handlesAny($this->currentUser()), 403);

        if ($this->openedId !== null) {
            $this->open($this->openedId);
        }
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function open(int $id): void
    {
        // Someone else's case is simply not found.
        $complaint = Complaint::query()->handledBy($this->currentUser())->findOrFail($id);
        $this->openedId = $complaint->id;
        $this->status = $complaint->status->value;
        $this->resolution = (string) $complaint->resolution;
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['openedId', 'status', 'resolution', 'askingProof', 'proofNote']);
    }

    public function askForProof(RequestComplaintProof $request): void
    {
        $validated = $this->validate(['proofNote' => ['required', 'string', 'min:5', 'max:1000']], ['proofNote.required' => 'Say what would help, so they know what to send.'], ['proofNote' => 'what would help']);

        $complaint = $request->handle($this->currentUser(), Complaint::query()->handledBy($this->currentUser())->findOrFail($this->openedId), $validated['proofNote']);

        $this->reset(['askingProof', 'proofNote']);
        unset($this->opened);
        $this->dispatch('notify', message: "Asked {$complaint->user->name} for proof. They've been told.");
    }

    public function save(UpdateComplaint $update): void
    {
        $validated = $this->validate([
            'status' => ['required', Rule::enum(ComplaintStatus::class)],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);

        $update->handle(Complaint::query()->handledBy($this->currentUser())->findOrFail($this->openedId), ComplaintStatus::from($validated['status']), $validated['resolution'] ?? null, $this->currentUser());

        $this->close();
        unset($this->complaints);
        $this->dispatch('notify', message: 'Complaint updated. The employee was notified.');
    }

    /**
     * @return LengthAwarePaginator<int, Complaint>
     */
    #[Computed]
    public function complaints(): LengthAwarePaginator
    {
        return Complaint::query()
            ->handledBy($this->currentUser())
            ->with(['user.employeeProfile', 'handler'])
            ->when($this->statusFilter === 'active', fn ($query) => $query->whereIn('status', [ComplaintStatus::Open, ComplaintStatus::Investigating]))
            ->when(ComplaintStatus::tryFrom($this->statusFilter), fn ($query, ComplaintStatus $status) => $query->where('status', $status))
            // Someone asking for help, on a case still open, first.
            ->orderByRaw('case when asks_for_help = ? and status in (?, ?) then 0 else 1 end', [true, ComplaintStatus::Open->value, ComplaintStatus::Investigating->value])
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function opened(): ?Complaint
    {
        return $this->openedId !== null ? Complaint::query()->handledBy($this->currentUser())->with(['user.employeeProfile', 'handler', 'files'])->find($this->openedId) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.complaints', ['statuses' => ComplaintStatus::cases()]);
    }
}
