<?php

namespace App\Livewire\Referrals;

use App\Actions\People\SubmitReferral;
use App\Actions\People\WithdrawReferral;
use App\Enums\Position;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Referral;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Refer a friend (hiring perks): name someone to hire, see what became of each, and
 * when the bonus comes. The terms are HR's (Referral::terms).
 *
 * @property-read Collection<int, Referral> $referrals
 */
#[Title('Refer a Friend')]
class MyReferrals extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public string $applicantName = '';

    public string $applicantPhone = '';

    public string $position = '';

    public string $note = '';

    public function mount(): void
    {
        abort_unless(Referral::canRefer($this->currentUser()), 403);
    }

    public function start(): void
    {
        $this->reset(['applicantName', 'applicantPhone', 'position', 'note']);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function submit(SubmitReferral $submit): void
    {
        $validated = $this->validate([
            'applicantName' => ['required', 'string', 'max:150'],
            'applicantPhone' => ['required', 'string', 'max:20'],
            'position' => ['nullable', Rule::in(array_map(fn (Position $position) => $position->value, Position::offered()))],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: ['applicantName' => 'name', 'applicantPhone' => 'mobile number', 'position' => 'job']);

        $submit->handle($this->currentUser(), $validated['applicantName'], $validated['applicantPhone'], Position::tryFrom((string) $validated['position']), $validated['note']);

        $this->formOpen = false;
        unset($this->referrals);
        $this->dispatch('notify', message: 'Referral sent to HR.');
    }

    public function withdraw(int $id, WithdrawReferral $withdraw): void
    {
        $withdraw->handle(Referral::query()->where('referrer_id', $this->currentUser()->id)->findOrFail($id), $this->currentUser());
        unset($this->referrals);
        $this->dispatch('notify', message: 'Referral withdrawn.');
    }

    /**
     * @return Collection<int, Referral>
     */
    #[Computed]
    public function referrals(): Collection
    {
        return Referral::query()->where('referrer_id', $this->currentUser()->id)->with('paidInRun')->latest('id')->get();
    }

    public function render(): View
    {
        return view('livewire.referrals.my-referrals', ['terms' => Referral::terms()]);
    }
}
