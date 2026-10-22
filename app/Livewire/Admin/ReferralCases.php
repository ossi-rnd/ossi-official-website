<?php

namespace App\Livewire\Admin;

use App\Actions\People\DecideReferral;
use App\Actions\People\SaveReferralTerms;
use App\Enums\Ability;
use App\Enums\ReferralStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * HR's referrals (hiring perks): who was referred, by whom; mark each hired (linked to
 * the hire's record: the bonus and its due date are frozen then) or not hired. HR also
 * sets the terms: the bonus, and the days the hire must stay.
 *
 * @property-read Collection<int, Referral> $referrals
 * @property-read Collection<int, EmployeeProfile> $recentHires
 */
#[Title('Referrals')]
class ReferralCases extends Component
{
    use InteractsWithUser;

    /** "open" (with HR, or hired and the bonus not settled) or a ReferralStatus value. */
    #[Url(as: 'status', except: 'open')]
    public string $statusFilter = 'open';

    public ?int $decidingId = null;

    /** "hired" or "not_hired". */
    public string $decision = 'hired';

    public string $hireId = '';

    public string $decisionNote = '';

    public bool $termsOpen = false;

    public string $bonusAmount = '';

    public string $daysToStay = '';

    public function mount(): void
    {
        $this->authorize(Ability::ManageReferrals->value);
    }

    public function decide(int $id, string $decision): void
    {
        $this->authorize(Ability::ManageReferrals->value);

        $this->decidingId = Referral::query()->where('status', ReferralStatus::Submitted)->findOrFail($id)->id;
        $this->decision = $decision === 'not_hired' ? 'not_hired' : 'hired';
        $this->reset(['hireId', 'decisionNote']);
        $this->resetErrorBag();
    }

    public function saveDecision(DecideReferral $decide): void
    {
        $this->authorize(Ability::ManageReferrals->value);

        $referral = Referral::query()->findOrFail((int) $this->decidingId);

        if ($this->decision === 'hired') {
            $this->validate(['hireId' => ['required', 'integer']], ['hireId.required' => 'Choose the hire\'s record.']);
            $decide->handle($referral, EmployeeProfile::query()->findOrFail((int) $this->hireId), null, $this->currentUser());
        } else {
            $this->validate(['decisionNote' => ['required', 'string', 'max:500']], attributes: ['decisionNote' => 'reason']);
            $decide->handle($referral, null, $this->decisionNote, $this->currentUser());
        }

        $this->reset(['decidingId', 'hireId', 'decisionNote']);
        unset($this->referrals);
        $this->dispatch('notify', message: 'Referral updated. The referrer is told.');
    }

    public function editTerms(): void
    {
        $this->authorize(Ability::ManageReferrals->value);

        $terms = Referral::terms();
        $this->bonusAmount = (string) $terms['bonus_amount'];
        $this->daysToStay = (string) $terms['days_to_stay'];
        $this->resetErrorBag();
        $this->termsOpen = true;
    }

    public function saveTerms(SaveReferralTerms $save): void
    {
        $this->validate([
            'bonusAmount' => ['required', 'integer', 'min:0', 'max:100000'],
            'daysToStay' => ['required', 'integer', 'min:0', 'max:365'],
        ], attributes: ['bonusAmount' => 'bonus', 'daysToStay' => 'days']);

        $save->handle($this->currentUser(), (int) $this->bonusAmount, (int) $this->daysToStay);

        $this->termsOpen = false;
        $this->dispatch('notify', message: 'Referral terms saved. Referrals already hired keep theirs.');
    }

    /**
     * @return Collection<int, Referral>
     */
    #[Computed]
    public function referrals(): Collection
    {
        return Referral::query()->with(['referrer', 'hire'])
            ->when($this->statusFilter === 'open', fn ($query) => $query->open())
            ->when(ReferralStatus::tryFrom($this->statusFilter), fn ($query, ReferralStatus $status) => $query->where('status', $status))
            // With HR first, then the soonest bonus.
            ->orderByRaw("case status when 'submitted' then 0 when 'hired' then 1 else 2 end")
            ->orderBy('bonus_due_on')->latest('id')
            ->get();
    }

    /**
     * Records to link a hire to: still working here, hired in the last 6 months, not linked yet.
     *
     * @return Collection<int, EmployeeProfile>
     */
    #[Computed]
    public function recentHires(): Collection
    {
        return EmployeeProfile::query()->active()->whereNotNull('hire_date')
            ->whereDate('hire_date', '>=', CarbonImmutable::today()->subMonths(6)->toDateString())
            ->whereNotIn('id', Referral::query()->whereNotNull('employee_profile_id')->select('employee_profile_id'))
            ->orderByDesc('hire_date')->orderBy('name')
            ->get(['id', 'name', 'employee_number', 'hire_date']);
    }

    public function render(): View
    {
        return view('livewire.admin.referral-cases', [
            'terms' => Referral::terms(),
            'deciding' => $this->decidingId !== null ? Referral::query()->with('referrer')->find($this->decidingId) : null,
        ]);
    }
}
