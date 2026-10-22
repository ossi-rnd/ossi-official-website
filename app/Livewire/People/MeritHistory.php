<?php

namespace App\Livewire\People;

use App\Actions\People\ReplyToDemerit;
use App\Actions\People\VoidMerit;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\MeritRecord;
use App\Models\User;
use App\Queries\People\MeritTotalsQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One person's merits and demerits, with the 12-month total: on their 201 file
 * (staff can give one, or take one back) and on My Profile (they can reply to a
 * demerit). Who sees it: MeritRecord::canView.
 *
 * @property-read User $employee
 * @property-read Collection<int, MeritRecord> $records
 * @property-read array{merits: int, demerits: int, net: int} $totals
 */
class MeritHistory extends Component
{
    use InteractsWithUser;

    #[Locked]
    public int $userId;

    public ?int $replyingId = null;

    public string $reply = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        abort_unless(MeritRecord::canView($this->currentUser(), $this->employee), 403);
    }

    #[Computed]
    public function employee(): User
    {
        return User::query()->with('employeeProfile')->findOrFail($this->userId);
    }

    /**
     * @return Collection<int, MeritRecord>
     */
    #[Computed]
    public function records(): Collection
    {
        return MeritRecord::query()->where('user_id', $this->userId)->with(['giver:id,name', 'voider:id,name'])->latest('occurred_on')->latest('id')->limit(50)->get();
    }

    /**
     * @return array{merits: int, demerits: int, net: int}
     */
    #[Computed]
    public function totals(): array
    {
        return app(MeritTotalsQuery::class)->handle([$this->userId])[$this->userId] ?? ['merits' => 0, 'demerits' => 0, 'net' => 0];
    }

    public function isOwn(): bool
    {
        return $this->currentUser()->id === $this->userId;
    }

    public function canGive(): bool
    {
        return MeritRecord::canGive($this->currentUser(), $this->employee);
    }

    public function startReply(int $id): void
    {
        $this->replyingId = $this->find($id)->id;
        $this->reply = '';
        $this->resetErrorBag();
    }

    public function sendReply(ReplyToDemerit $replyTo): void
    {
        $replyTo->handle($this->currentUser(), $this->find((int) $this->replyingId), $this->reply);
        $this->reset(['replyingId', 'reply']);
        unset($this->records);
        $this->dispatch('notify', message: 'Your side is saved next to the demerit, and whoever gave it is told.');
    }

    public function startVoid(int $id): void
    {
        $this->voidingId = $this->find($id)->id;
        $this->voidReason = '';
        $this->resetErrorBag();
    }

    public function void(VoidMerit $void): void
    {
        $void->handle($this->currentUser(), $this->find((int) $this->voidingId), $this->voidReason);
        $this->reset(['voidingId', 'voidReason']);
        unset($this->records, $this->totals);
        $this->dispatch('notify', message: 'Taken back. It stays on record, struck through, and no longer counts.');
    }

    public function render(): View
    {
        return view('livewire.people.merit-history');
    }

    /**
     * Looked up THROUGH this person, so a forged id is simply not found.
     */
    private function find(int $id): MeritRecord
    {
        return MeritRecord::query()->where('user_id', $this->userId)->with('user')->findOrFail($id);
    }
}
