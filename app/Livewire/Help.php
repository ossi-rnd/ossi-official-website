<?php

namespace App\Livewire;

use App\Actions\Content\DeleteFaq;
use App\Actions\Content\SaveFaq;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Faq;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Help & FAQs. Everyone reads the published questions; people who can
 * publish content also see drafts and can edit in place, so there is one
 * page to maintain instead of a reader page plus an admin copy.
 *
 * @property-read bool $canEdit
 * @property-read Collection<int, Faq> $faqs
 */
#[Title('Help & FAQs')]
class Help extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $question = '';

    public string $answer = '';

    public int $sortOrder = 0;

    public bool $isPublished = true;

    #[Computed]
    public function canEdit(): bool
    {
        return $this->currentUser()->can(Ability::PublishContent->value);
    }

    /**
     * @return Collection<int, Faq>
     */
    #[Computed]
    public function faqs(): Collection
    {
        return Faq::query()
            ->unless($this->canEdit, fn ($query) => $query->where('is_published', true))
            ->ordered()
            ->get();
    }

    public function create(): void
    {
        $this->authorize(Ability::PublishContent->value);

        $this->resetForm();
        $this->sortOrder = ((int) Faq::query()->max('sort_order')) + 10;
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::PublishContent->value);

        $faq = Faq::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $faq->id;
        $this->question = $faq->question;
        $this->answer = $faq->answer;
        $this->sortOrder = $faq->sort_order;
        $this->isPublished = $faq->is_published;
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(SaveFaq $saveFaq): void
    {
        $this->authorize(Ability::PublishContent->value);

        $validated = $this->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:100000'],
            'isPublished' => ['boolean'],
        ]);

        $saveFaq->handle(
            $this->editingId !== null ? Faq::query()->findOrFail($this->editingId) : null,
            ['question' => $validated['question'], 'answer' => $validated['answer'], 'sort_order' => (int) $validated['sortOrder'], 'is_published' => $validated['isPublished']],
            $this->currentUser(),
        );

        $this->resetForm();
        unset($this->faqs);
        $this->dispatch('notify', message: 'Question saved.');
    }

    public function delete(int $id, DeleteFaq $deleteFaq): void
    {
        $this->authorize(Ability::PublishContent->value);

        $deleteFaq->handle(Faq::query()->findOrFail($id), $this->currentUser());
        unset($this->faqs);
        $this->dispatch('notify', message: 'Question deleted.');
    }

    public function render(): View
    {
        return view('livewire.help');
    }

    private function resetForm(): void
    {
        $this->reset(['formOpen', 'editingId', 'question', 'answer', 'sortOrder', 'isPublished']);
        $this->resetErrorBag();
    }
}
