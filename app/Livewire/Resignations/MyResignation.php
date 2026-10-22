<?php

namespace App\Livewire\Resignations;

use App\Actions\Resignations\FileResignation;
use App\Actions\Resignations\WithdrawResignation;
use App\Enums\ResignationReason;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\ChoosesResignationReasons;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Resignation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The employee's resignation: file it (letter, reason, last day), follow it
 * (accepted last day, clearance, final pay), or withdraw it while pending.
 * HR, or the President or Vice President for HR staff, handles it on Resignations.
 *
 * @property-read Resignation|null $current
 * @property-read Collection<int, Resignation> $past
 */
#[Title('Resignation')]
class MyResignation extends Component
{
    use ChoosesAcknowledgementLanguage, ChoosesResignationReasons, InteractsWithUser;

    public string $letter = '';

    public string $lastDay = '';

    public bool $asksWaiver = false;

    /**
     * "I've read these": required before it's sent. The lines are saved with it, and they
     * include the written authorization (Art. 113) to deduct what's owed from final pay.
     */
    public bool $acknowledges = false;

    public function mount(): void
    {
        $this->lastDay = CarbonImmutable::today()->addDays(Resignation::NOTICE_DAYS)->toDateString();
    }

    /** The one in progress (pending or accepted), if any. */
    #[Computed]
    public function current(): ?Resignation
    {
        return Resignation::query()->where('user_id', $this->currentUser()->id)->open()->with(['user.employeeProfile', 'decider', 'finalPay', 'complaint'])->latest()->first();
    }

    /**
     * Withdrawn or completed ones, for the record.
     *
     * @return Collection<int, Resignation>
     */
    #[Computed]
    public function past(): Collection
    {
        return Resignation::query()->where('user_id', $this->currentUser()->id)->whereNotIn('status', ['pending', 'accepted'])->latest()->limit(5)->get();
    }

    /** The last day is under 30 days away, and no just cause is ticked: the form offers the waiver. */
    public function needsWaiver(): bool
    {
        if ($this->justCauseTicked() || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->lastDay) !== 1) {
            return false;
        }

        return CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($this->lastDay), false) < Resignation::NOTICE_DAYS;
    }

    /** "HR", or "the President or Vice President" for someone in HR. */
    public function handler(): string
    {
        return Resignation::goesToExecutive($this->currentUser()) ? 'the President or Vice President' : 'HR';
    }

    public function submit(FileResignation $file): void
    {
        $validated = $this->validate([
            ...$this->reasonRules(),
            'letter' => ['required', 'string', 'min:20', 'max:5000'],
            'lastDay' => ['required', 'date'],
            'asksWaiver' => ['boolean'],
            'acknowledges' => ['accepted'],
        ], ['letter.min' => 'Write a short letter (at least 20 characters).', 'reasons.required' => 'Tick at least one reason.', 'acknowledges.accepted' => 'Read the points above and tick the box to send.'], ['lastDay' => 'last day']);

        // A placeholder from the sample letter, not filled in.
        if (str_contains($validated['letter'], '[')) {
            throw ValidationException::withMessages(['letter' => 'Replace the part in [brackets] with your own words.']);
        }

        $resignation = $file->handle(
            $this->currentUser(),
            $this->chosenReasons($validated['reasons']),
            $validated['reasonOther'] ?? null,
            $validated['letter'],
            CarbonImmutable::parse($validated['lastDay']),
            (bool) $validated['asksWaiver'],
            acknowledged: true,
            locale: $this->ackLocale,
        );

        $this->reset(['reasons', 'reasonOther', 'claimsJustCause', 'letter', 'asksWaiver', 'acknowledges']);
        unset($this->current);
        $this->dispatch('notify', message: 'Your resignation was sent to '.$this->handler().'.'
            .($resignation->complaint ? ' '.ucfirst($this->handler()).' also opened complaint '.$resignation->complaint->reference().' to look into what happened.' : ''));
    }

    /**
     * Fills the letter from what they ticked and their last day; anything only they
     * can say (what happened, for a just cause) is left in [brackets] to replace.
     */
    public function useSampleLetter(): void
    {
        $user = $this->currentUser();
        $user->loadMissing('employeeProfile');
        $lastDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->lastDay) === 1 ? CarbonImmutable::parse($this->lastDay) : null;
        $reasons = array_values(array_filter(array_map(fn (string $value) => ResignationReason::tryFrom($value), $this->reasons)));

        $this->letter = Resignation::sampleLetter($user->name, $user->employeeProfile?->position?->label(), $reasons, $this->reasonOther, $lastDay, $this->handler());
        $this->resetErrorBag('letter');
    }

    public function withdraw(WithdrawResignation $withdraw): void
    {
        $current = $this->current;

        if ($current === null) {
            return;
        }

        $withdraw->handle($current, $this->currentUser());
        unset($this->current, $this->past);
        $this->dispatch('notify', message: 'Your resignation was withdrawn.');
    }

    public function render(): View
    {
        return view('livewire.resignations.my-resignation');
    }
}
