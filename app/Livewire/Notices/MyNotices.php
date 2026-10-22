<?php

namespace App\Livewire\Notices;

use App\Actions\People\AcknowledgeNotice;
use App\Actions\People\ExplainNotice;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\NoticeToExplain;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The employee's Notices to Explain: read each one, mark it received, explain
 * in writing within the days given (and ask for a hearing), and read the
 * decision. Opening the page doesn't mark anything received: they say so.
 *
 * @property-read Collection<int, NoticeToExplain> $notices
 */
#[Title('My Notices')]
class MyNotices extends Component
{
    use InteractsWithUser;

    public ?int $explainingId = null;

    public string $explanation = '';

    public bool $wantsHearing = false;

    /**
     * @return Collection<int, NoticeToExplain>
     */
    #[Computed]
    public function notices(): Collection
    {
        return NoticeToExplain::query()->where('user_id', $this->currentUser()->id)->with(['issuer:id,name', 'decider:id,name', 'demerit'])->latest('id')->get();
    }

    public function acknowledge(int $id, AcknowledgeNotice $acknowledge): void
    {
        $acknowledge->handle($this->currentUser(), $this->find($id));
        unset($this->notices);
        $this->dispatch('notify', message: 'Marked received.');
    }

    public function startExplaining(int $id): void
    {
        $this->explainingId = $this->find($id)->id;
        $this->reset(['explanation', 'wantsHearing']);
        $this->resetErrorBag();
    }

    public function explain(ExplainNotice $explain): void
    {
        $explain->handle($this->currentUser(), $this->find((int) $this->explainingId), $this->explanation, $this->wantsHearing);
        $this->reset(['explainingId', 'explanation', 'wantsHearing']);
        unset($this->notices);
        $this->dispatch('notify', message: 'Your explanation is sent. You\'ll be told the decision here and by email.');
    }

    public function render(): View
    {
        return view('livewire.notices.my-notices');
    }

    private function find(int $id): NoticeToExplain
    {
        return NoticeToExplain::query()->where('user_id', $this->currentUser()->id)->findOrFail($id);
    }
}
