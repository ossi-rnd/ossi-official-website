<?php

namespace App\Livewire\People;

use App\Actions\People\PromiseRequirement;
use App\Actions\People\ReviewRequirement;
use App\Actions\People\SubmitRequirementDocument;
use App\Data\RequirementItem;
use App\Enums\Requirement;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Queries\People\RequirementChecklistQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The 201 checklist, on both the employee record (HR) and My Profile (the
 * employee). One component, two audiences: the policy decides which buttons
 * appear, and every action re-checks it, so hiding a button is never the
 * only protection.
 *
 * @property-read EmployeeProfile $profile
 * @property-read list<RequirementItem> $items
 * @property-read list<RequirementItem> $visibleItems
 * @property-read int $openCount
 * @property-read array{total: int, on_file: int, submitted: int, expired: int} $summary
 * @property-read bool $canReview
 * @property-read RequirementItem|null $activeItem
 */
class RequirementsChecklist extends Component
{
    use InteractsWithUser, WithFileUploads;

    #[Locked]
    public int $profileId;

    public bool $onlyOpen = false;

    /** The requirement value the modal is about. */
    public ?string $active = null;

    /** upload | review | promise */
    public string $mode = '';

    /** @var UploadedFile|null */
    public $document = null;

    public string $expiresOn = '';

    public string $promisedOn = '';

    public string $decision = ReviewRequirement::FILE;

    public string $note = '';

    public function mount(int $profileId): void
    {
        $this->profileId = $profileId;
        $this->authorize('viewRequirements', $this->profile);
    }

    #[Computed]
    public function profile(): EmployeeProfile
    {
        return EmployeeProfile::query()->findOrFail($this->profileId);
    }

    /**
     * @return list<RequirementItem>
     */
    #[Computed]
    public function items(): array
    {
        return app(RequirementChecklistQuery::class)->handle($this->profile);
    }

    /**
     * @return list<RequirementItem>
     */
    #[Computed]
    public function visibleItems(): array
    {
        return $this->onlyOpen
            ? array_values(array_filter($this->items, fn (RequirementItem $item) => $item->needsAction()))
            : $this->items;
    }

    /** Documents still missing, waiting for HR's check, or expired. */
    #[Computed]
    public function openCount(): int
    {
        return count(array_filter($this->items, fn (RequirementItem $item) => $item->needsAction()));
    }

    /**
     * The "Only what needs action" filter is offered only when it would change the list: some
     * documents are done and some aren't (or it's already on, so it can be turned off).
     */
    public function offersFilter(): bool
    {
        return $this->onlyOpen || ($this->openCount > 0 && $this->openCount < count($this->items));
    }

    /**
     * @return array{total: int, on_file: int, submitted: int, expired: int}
     */
    #[Computed]
    public function summary(): array
    {
        return RequirementChecklistQuery::summarize($this->items);
    }

    #[Computed]
    public function canReview(): bool
    {
        return $this->currentUser()->can('reviewRequirements', $this->profile);
    }

    #[Computed]
    public function activeItem(): ?RequirementItem
    {
        foreach ($this->items as $item) {
            if ($item->requirement->value === $this->active) {
                return $item;
            }
        }

        return null;
    }

    // ---- Opening the modal ------------------------------------------------

    public function startUpload(string $requirement): void
    {
        $this->authorize('submitRequirements', $this->profile);
        $this->open($requirement, 'upload');
    }

    public function startReview(string $requirement): void
    {
        $this->authorize('reviewRequirements', $this->profile);
        $this->open($requirement, 'review');
        $this->note = $this->activeItem->record->note ?? '';
    }

    public function startPromise(string $requirement): void
    {
        $this->authorize('submitRequirements', $this->profile);
        $this->open($requirement, 'promise');
        $this->promisedOn = $this->activeItem?->record?->promised_on?->toDateString() ?? '';
    }

    public function cancel(): void
    {
        $this->reset(['active', 'mode', 'document', 'expiresOn', 'promisedOn', 'decision', 'note']);
        $this->resetErrorBag();
    }

    // ---- Saving -----------------------------------------------------------

    /**
     * Not called "upload": $wire.upload() is Livewire's own JavaScript file-upload
     * method, so wire:submit="upload" would call that instead of this.
     */
    public function submitDocument(SubmitRequirementDocument $submit): void
    {
        $this->authorize('submitRequirements', $this->profile);

        $this->validate([
            'document' => ['required', 'file', 'mimes:'.implode(',', (array) config('hr.documents.mimes')), 'max:'.(int) config('hr.documents.max_kilobytes')],
            'expiresOn' => ['nullable', 'date'],
        ], attributes: ['expiresOn' => 'expiry date']);

        /** @var UploadedFile $document */
        $document = $this->document;
        $record = $submit->handle($this->profile, $this->activeRequirement(), $document, $this->expiresOn ?: null, $this->currentUser());

        $this->finish($record->requirement->label().($this->canReview ? ' filed.' : ' sent to HR for checking.'));
    }

    public function review(ReviewRequirement $review): void
    {
        $this->authorize('reviewRequirements', $this->profile);

        $this->validate([
            'decision' => ['required', Rule::in([ReviewRequirement::FILE, ReviewRequirement::RETURN])],
            'expiresOn' => ['nullable', 'date'],
            'note' => [Rule::requiredIf($this->decision === ReviewRequirement::RETURN), 'nullable', 'string', 'max:500'],
        ], attributes: ['expiresOn' => 'expiry date', 'note' => 'reason']);

        $record = $review->handle($this->profile, $this->activeRequirement(), $this->decision, $this->expiresOn ?: null, $this->note ?: null, $this->currentUser());

        $this->finish($record->requirement->label().($this->decision === ReviewRequirement::RETURN ? ' returned to the employee.' : ' marked on file.'));
    }

    public function promise(PromiseRequirement $promise): void
    {
        $this->authorize('submitRequirements', $this->profile);

        $this->validate(['promisedOn' => ['required', 'date', 'after_or_equal:today']], attributes: ['promisedOn' => 'date']);

        $record = $promise->handle($this->profile, $this->activeRequirement(), $this->promisedOn, $this->currentUser());

        $this->finish("Noted: {$record->requirement->label()} by {$record->promised_on?->format('M j, Y')}.");
    }

    public function render(): View
    {
        return view('livewire.people.requirements-checklist', [
            'isOwner' => $this->profile->user_id === $this->currentUser()->id,
        ]);
    }

    // ---- Helpers ----------------------------------------------------------

    private function open(string $requirement, string $mode): void
    {
        $this->cancel();
        $this->active = Requirement::tryFrom($requirement)->value ?? abort(404);
        $this->mode = $mode;
        $this->expiresOn = $this->activeItem?->record?->expires_on?->toDateString() ?? '';
    }

    private function activeRequirement(): Requirement
    {
        return Requirement::tryFrom((string) $this->active) ?? abort(404);
    }

    private function finish(string $message): void
    {
        $this->cancel();
        unset($this->items, $this->visibleItems, $this->summary, $this->activeItem);
        $this->dispatch('notify', message: $message);
    }
}
