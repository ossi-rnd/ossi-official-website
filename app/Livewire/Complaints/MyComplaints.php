<?php

namespace App\Livewire\Complaints;

use App\Actions\Complaints\AttachComplaintFile;
use App\Actions\Complaints\FileComplaint;
use App\Enums\ComplaintCategory;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Complaint;
use App\Models\ComplaintSubject;
use App\Support\ComplaintRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * File a complaint with HR and follow it. Only you and HR can see it.
 *
 * @property-read Collection<int, Complaint> $complaints
 * @property-read Collection<int, ComplaintSubject> $subjects
 */
#[Title('My Complaints')]
class MyComplaints extends Component
{
    use ChoosesAcknowledgementLanguage, InteractsWithUser, WithFileUploads;

    public string $category = 'conduct';

    /** A common subject from HR's list for the category, or OTHER to type one. */
    public string $subjectId = '';

    public const OTHER = 'other';

    /** Typed, when "Other" is picked or the category has no list. */
    public string $subject = '';

    public string $details = '';

    public string $against = '';

    public string $incidentOn = '';

    /** "I've read these": required; the lines are saved with the complaint. */
    public bool $acknowledges = false;

    /** "I need help now": the handler is alerted at once; never counted against the weekly limit. */
    public bool $asksForHelp = false;

    /** Who saw it (optional): for when there's nothing on paper. */
    public string $witnesses = '';

    /**
     * Proof (optional): photos, screenshots, a PDF.
     *
     * @var array<int, UploadedFile>
     */
    public array $proof = [];

    /** Adding proof to a complaint already filed. */
    public ?int $addingProofTo = null;

    /** @var UploadedFile|null */
    public $extraProof = null;

    /** A new category has its own list: start over on the subject. */
    public function updatedCategory(): void
    {
        $this->reset(['subjectId', 'subject']);
    }

    /** Typing is needed for "Other", and for a category with no list. */
    public function typesSubject(): bool
    {
        return $this->subjectId === self::OTHER || $this->subjects->isEmpty();
    }

    /**
     * HR's common subjects for the chosen category.
     *
     * @return Collection<int, ComplaintSubject>
     */
    #[Computed]
    public function subjects(): Collection
    {
        return ComplaintSubject::query()->active()->where('category', $this->category)->ordered()->get();
    }

    public function submit(FileComplaint $file): void
    {
        $typed = $this->typesSubject();
        $picked = $typed ? null : $this->subjects->firstWhere('id', (int) $this->subjectId);

        if (! $typed && $picked === null) {
            $this->addError('subjectId', 'Choose a subject, or "Other" to type your own.');

            return;
        }

        $validated = $this->validate([
            'category' => ['required', Rule::enum(ComplaintCategory::class)],
            'subject' => $typed ? ['required', 'string', 'max:150'] : ['nullable'],
            'details' => ['required', 'string', 'min:20', 'max:5000'],
            'against' => ['nullable', 'string', 'max:255'],
            'witnesses' => ['nullable', 'string', 'max:1000'],
            'incidentOn' => ['nullable', 'date', 'before_or_equal:today'],
            'proof' => ['array', 'max:'.config('complaints.max_files')],
            'proof.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'acknowledges' => ['accepted'],
        ], ['details.min' => 'Tell '.$this->handler().' what happened in a few sentences (at least 20 characters).', 'acknowledges.accepted' => 'Read the points above and tick the box to send.', 'proof.*.mimes' => 'Attach a photo or screenshot (JPG, PNG) or a PDF.', 'proof.max' => 'Up to '.config('complaints.max_files').' files. Put several pages in one PDF.'], ['incidentOn' => 'date it happened']);

        $complaint = $file->handle(
            $this->currentUser(),
            ComplaintCategory::from($validated['category']),
            (string) ($validated['subject'] ?? ''),
            $validated['details'],
            $validated['against'] ?? null,
            filled($validated['incidentOn'] ?? null) ? CarbonImmutable::parse($validated['incidentOn']) : null,
            acknowledged: true,
            locale: $this->ackLocale,
            witnesses: $validated['witnesses'] ?? null,
            proof: array_values($this->proof),
            asksForHelp: $this->asksForHelp,
            picked: $picked,
        );

        $this->reset(['category', 'subjectId', 'subject', 'details', 'against', 'witnesses', 'incidentOn', 'proof', 'acknowledges', 'asksForHelp']);
        unset($this->complaints);
        $this->dispatch('notify', message: $complaint->asks_for_help
            ? "Sent to {$complaint->handlerLabel()} as {$complaint->reference()}, marked urgent. They've been alerted by email too."
            : "Sent to {$complaint->handlerLabel()} as {$complaint->reference()}.");
    }

    public function startAddingProof(int $id): void
    {
        // Through the owner: someone else's complaint is simply not found.
        $this->addingProofTo = Complaint::query()->where('user_id', $this->currentUser()->id)->findOrFail($id)->id;
        $this->reset('extraProof');
        $this->resetErrorBag();
    }

    public function stopAddingProof(): void
    {
        $this->reset(['addingProofTo', 'extraProof']);
    }

    public function addProof(AttachComplaintFile $attach): void
    {
        $this->validate(['extraProof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']], ['extraProof.mimes' => 'Attach a photo or screenshot (JPG, PNG) or a PDF.', 'extraProof.required' => 'Choose a file.']);

        $complaint = Complaint::query()->where('user_id', $this->currentUser()->id)->findOrFail($this->addingProofTo);

        if (! $this->extraProof instanceof UploadedFile) {
            return;
        }

        $attach->handle($this->currentUser(), $complaint, $this->extraProof);

        $this->reset(['addingProofTo', 'extraProof']);
        unset($this->complaints);
        $this->dispatch('notify', message: "Added. {$complaint->handlerLabel()} has been told.");
    }

    /** "HR", or "the President or Vice President" for someone in HR (their complaints never go to HR). */
    private function handler(): string
    {
        return Complaint::goesToExecutive($this->currentUser()) ? 'the President or Vice President' : 'HR';
    }

    /**
     * @return Collection<int, Complaint>
     */
    #[Computed]
    public function complaints(): Collection
    {
        return Complaint::query()->where('user_id', $this->currentUser()->id)->with('files')->latest()->limit(50)->get();
    }

    public function render(): View
    {
        return view('livewire.complaints.my-complaints', ['categories' => ComplaintCategory::cases(), 'handler' => $this->handler(), 'allowance' => ComplaintRules::allowanceFor($this->currentUser())]);
    }
}
