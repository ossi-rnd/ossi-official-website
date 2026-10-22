<?php

namespace App\Livewire;

use App\Actions\Content\DeletePolicy;
use App\Actions\Content\SavePolicy;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\CompanyPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The Company Policy page: every account reads it; Admin and HR
 * (manage-policies) write it.
 *
 * @property-read Collection<int, CompanyPolicy> $policies
 */
#[Title('Company Policy')]
class CompanyPolicies extends Component
{
    use InteractsWithUser, WithFileUploads;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public string $sortOrder = '10';

    /** @var UploadedFile|null */
    public $file = null;

    public bool $removeFile = false;

    private const FIELDS = ['editingId', 'title', 'body', 'sortOrder', 'file', 'removeFile'];

    public function create(): void
    {
        $this->authorize(Ability::ManagePolicies->value);

        $this->reset(self::FIELDS);
        $this->sortOrder = (string) (((int) CompanyPolicy::query()->max('sort_order')) + 10);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ManagePolicies->value);

        $policy = CompanyPolicy::query()->findOrFail($id);
        $this->reset(self::FIELDS);
        $this->editingId = $policy->id;
        $this->title = $policy->title;
        $this->body = $policy->body;
        $this->sortOrder = (string) $policy->sort_order;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', ...self::FIELDS]);
    }

    public function save(SavePolicy $save): void
    {
        $this->authorize(Ability::ManagePolicies->value);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:100000'],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('documents.max_kilobytes')],
            'removeFile' => ['boolean'],
        ], [], ['sortOrder' => 'order', 'file' => 'signed copy']);

        /** @var UploadedFile|null $file */
        $file = $this->file;

        $save->handle(
            $this->editingId !== null ? CompanyPolicy::query()->findOrFail($this->editingId) : null,
            ['title' => trim($validated['title']), 'body' => trim($validated['body']), 'sort_order' => (int) $validated['sortOrder']],
            $file,
            (bool) $validated['removeFile'],
            $this->currentUser(),
        );

        $this->cancel();
        unset($this->policies);
        $this->dispatch('notify', message: 'Policy saved.');
    }

    public function delete(int $id, DeletePolicy $delete): void
    {
        $this->authorize(Ability::ManagePolicies->value);

        $delete->handle(CompanyPolicy::query()->findOrFail($id), $this->currentUser());
        unset($this->policies);
        $this->dispatch('notify', message: 'Policy deleted.');
    }

    /**
     * @return Collection<int, CompanyPolicy>
     */
    #[Computed]
    public function policies(): Collection
    {
        return CompanyPolicy::query()->with('editor:id,name')->orderBy('sort_order')->orderBy('title')->get();
    }

    public function render(): View
    {
        return view('livewire.company-policies', [
            'editing' => $this->editingId !== null ? CompanyPolicy::query()->find($this->editingId) : null,
        ]);
    }
}
