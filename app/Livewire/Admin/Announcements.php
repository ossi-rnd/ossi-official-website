<?php

namespace App\Livewire\Admin;

use App\Actions\Content\DeleteAnnouncement;
use App\Actions\Content\SaveAnnouncement;
use App\Enums\Ability;
use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin, HR and Super Admin publish advisories. Live ones show as a banner
 * to their audience and land in the bell; urgent ones are emailed as well.
 *
 * @property-read Collection<int, Announcement> $announcements
 */
#[Title('Announcements')]
class Announcements extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    /** @var list<string> */
    public array $audience = [];

    public bool $isUrgent = false;

    public string $startsAt = '';

    public string $endsAt = '';

    public function create(): void
    {
        $this->authorize(Ability::PublishContent->value);

        $this->resetForm();
        $this->startsAt = CarbonImmutable::now()->format('Y-m-d\TH:i');
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::PublishContent->value);

        $announcement = Announcement::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->body = $announcement->body;
        $this->audience = $announcement->audience ?? [];
        $this->isUrgent = $announcement->is_urgent;
        $this->startsAt = $announcement->starts_at->format('Y-m-d\TH:i');
        $this->endsAt = $announcement->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(SaveAnnouncement $saveAnnouncement): void
    {
        $this->authorize(Ability::PublishContent->value);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['array'],
            'audience.*' => [Rule::enum(Role::class)],
            'isUrgent' => ['boolean'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
        ], attributes: ['startsAt' => 'start', 'endsAt' => 'end']);

        $saveAnnouncement->handle(
            $this->editingId !== null ? Announcement::query()->findOrFail($this->editingId) : null,
            [
                'title' => $validated['title'],
                'body' => $validated['body'],
                'audience' => array_values($validated['audience']),
                'is_urgent' => $validated['isUrgent'],
                'starts_at' => $validated['startsAt'],
                'ends_at' => $validated['endsAt'] ?: null,
            ],
            $this->currentUser(),
        );

        $this->resetForm();
        unset($this->announcements);
        $this->dispatch('notify', message: 'Announcement saved.');
    }

    public function delete(int $id, DeleteAnnouncement $deleteAnnouncement): void
    {
        $this->authorize(Ability::PublishContent->value);

        $deleteAnnouncement->handle(Announcement::query()->findOrFail($id), $this->currentUser());
        unset($this->announcements);
        $this->dispatch('notify', message: 'Announcement deleted.');
    }

    /**
     * @return Collection<int, Announcement>
     */
    #[Computed]
    public function announcements(): Collection
    {
        return Announcement::query()->with('author:id,name')->latest('starts_at')->limit(50)->get();
    }

    public function render(): View
    {
        return view('livewire.admin.announcements', ['roles' => Role::cases()]);
    }

    private function resetForm(): void
    {
        $this->reset(['formOpen', 'editingId', 'title', 'body', 'audience', 'isUrgent', 'startsAt', 'endsAt']);
        $this->resetErrorBag();
    }
}
