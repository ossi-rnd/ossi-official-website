<?php

namespace App\Livewire\Admin;

use App\Actions\People\DecideNotice;
use App\Actions\People\IssueNoticeToExplain;
use App\Actions\People\ScheduleNoticeHearing;
use App\Enums\Ability;
use App\Enums\NteOutcome;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\MeritType;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Queries\People\PeopleToActOnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notices to Explain (roadmap 30): issue one to someone you supervise, follow
 * it (explanation, hearing), and decide it once due process is done. HR and the
 * Executive read them all (NoticeToExplain::scopeVisibleTo).
 *
 * @property-read LengthAwarePaginator<int, NoticeToExplain> $notices
 * @property-read NoticeToExplain|null $opened
 * @property-read Collection<int, User> $candidates
 * @property-read User|null $chosen
 * @property-read Collection<int, MeritType> $demerits
 */
#[Title('Notices to Explain')]
class NoticesToExplain extends Component
{
    use InteractsWithUser, WithPagination;

    /** open | all */
    #[Url(as: 'show')]
    public string $statusFilter = 'open';

    #[Url(as: 'open')]
    public ?int $openId = null;

    // Issuing. ?issue=user_id opens the form for that person (from their 201 file).
    #[Url(as: 'issue')]
    public ?int $issueTo = null;

    public bool $issuing = false;

    public string $personSearch = '';

    public string $subject = '';

    public string $incidentDate = '';

    public string $details = '';

    public string $rule = '';

    public string $possiblePenalty = 'reprimand';

    public string $explainBy = '';

    // Hearing and decision on the opened notice.
    public string $hearingAt = '';

    public string $hearingPlace = '';

    public string $decision = 'reprimand';

    public string $decisionReason = '';

    public string $suspensionDays = '';

    public string $suspensionFrom = '';

    public string $demeritTypeId = '';

    public function mount(): void
    {
        $this->authorize(Ability::IssueNoticesToExplain->value);

        if ($this->issueTo !== null) {
            $this->startIssuing($this->issueTo);
        }
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, NoticeToExplain>
     */
    #[Computed]
    public function notices(): LengthAwarePaginator
    {
        return NoticeToExplain::query()
            ->visibleTo($this->currentUser())
            ->when($this->statusFilter === 'open', fn ($query) => $query->open())
            ->with(['user.employeeProfile.client', 'issuer:id,name'])
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function opened(): ?NoticeToExplain
    {
        $notice = $this->openId !== null ? NoticeToExplain::query()->with(['user.employeeProfile.client', 'issuer', 'decider', 'demerit'])->find($this->openId) : null;

        return $notice !== null && NoticeToExplain::canView($this->currentUser(), $notice->user) ? $notice : null;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $me = $this->currentUser();

        return app(PeopleToActOnQuery::class)->handle($me, $this->personSearch, fn (User $user) => NoticeToExplain::canIssue($me, $user), $this->lastClientId());
    }

    /**
     * Before typing, suggest the client site of the last one this viewer issued.
     */
    public function lastClientId(): ?int
    {
        $lastUserId = NoticeToExplain::query()->where('issued_by', $this->currentUser()->id)->latest('id')->value('user_id');

        return $lastUserId !== null ? EmployeeProfile::query()->where('user_id', $lastUserId)->value('client_id') : null;
    }

    #[Computed]
    public function chosen(): ?User
    {
        return $this->issueTo !== null ? User::query()->with('employeeProfile.client')->find($this->issueTo) : null;
    }

    /**
     * @return Collection<int, MeritType>
     */
    #[Computed]
    public function demerits(): Collection
    {
        return MeritType::query()->active()->where('kind', MeritType::DEMERIT)->orderBy('points')->get();
    }

    public function startIssuing(?int $userId = null): void
    {
        $this->authorize(Ability::IssueNoticesToExplain->value);
        $this->reset(['personSearch', 'subject', 'details', 'rule']);
        $this->possiblePenalty = NteOutcome::Reprimand->value;
        $this->incidentDate = CarbonImmutable::today()->toDateString();
        $this->explainBy = CarbonImmutable::today()->addDays(NoticeToExplain::MIN_DAYS)->toDateString();
        $this->issueTo = $userId !== null && ($user = User::query()->find($userId)) !== null && NoticeToExplain::canIssue($this->currentUser(), $user) ? $user->id : null;
        unset($this->chosen);
        $this->issuing = true;
        $this->resetErrorBag();
    }

    public function choose(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        abort_unless(NoticeToExplain::canIssue($this->currentUser(), $user), 403);
        $this->issueTo = $user->id;
        $this->personSearch = '';
        unset($this->chosen);
    }

    public function closeIssuing(): void
    {
        $this->reset(['issuing', 'issueTo', 'personSearch', 'subject', 'details', 'rule']);
    }

    public function issue(IssueNoticeToExplain $issue): void
    {
        $this->validate([
            'issueTo' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:150'],
            'incidentDate' => ['required', 'date'],
            'details' => ['required', 'string', 'max:3000'],
            'rule' => ['required', 'string', 'max:1000'],
            'possiblePenalty' => ['required', Rule::in(array_map(fn (NteOutcome $penalty) => $penalty->value, NteOutcome::penalties()))],
            'explainBy' => ['required', 'date'],
        ], ['issueTo.required' => 'Choose the employee.']);

        $notice = $issue->handle(
            $this->currentUser(),
            User::query()->findOrFail($this->issueTo),
            $this->subject,
            CarbonImmutable::parse($this->incidentDate),
            $this->details,
            $this->rule,
            NteOutcome::from($this->possiblePenalty),
            CarbonImmutable::parse($this->explainBy),
        );

        $this->closeIssuing();
        unset($this->notices);
        $this->dispatch('notify', message: "Notice issued to {$notice->user->name}. They're told by bell and email, and have until {$notice->explain_by->format('M j')} to explain.");
    }

    public function open(int $id): void
    {
        $this->openId = $id;
        unset($this->opened);
        abort_if($this->opened === null, 404);
        $this->reset(['hearingAt', 'hearingPlace', 'decisionReason', 'suspensionDays', 'demeritTypeId']);
        $this->decision = $this->opened->possible_penalty->value;
        $this->suspensionFrom = CarbonImmutable::tomorrow()->toDateString();
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['openId']);
    }

    public function scheduleHearing(ScheduleNoticeHearing $schedule): void
    {
        $notice = $this->opened;
        abort_if($notice === null, 404);
        $this->validate(['hearingAt' => ['required', 'date'], 'hearingPlace' => ['required', 'string', 'max:255']], ['hearingAt.required' => 'When?', 'hearingPlace.required' => 'Where?']);

        $schedule->handle($this->currentUser(), $notice, CarbonImmutable::parse($this->hearingAt), $this->hearingPlace);
        unset($this->opened, $this->notices);
        $this->dispatch('notify', message: 'Hearing set. The employee is told when and where.');
    }

    public function decide(DecideNotice $decide): void
    {
        $notice = $this->opened;
        abort_if($notice === null, 404);
        $this->validate(['decision' => ['required', Rule::enum(NteOutcome::class)], 'decisionReason' => ['required', 'string', 'max:3000']], ['decisionReason.required' => 'Give the reasons.']);

        $outcome = NteOutcome::from($this->decision);
        $decide->handle(
            $this->currentUser(),
            $notice,
            $outcome,
            $this->decisionReason,
            $outcome === NteOutcome::Suspension ? (int) $this->suspensionDays : null,
            $outcome === NteOutcome::Suspension && $this->suspensionFrom !== '' ? CarbonImmutable::parse($this->suspensionFrom) : null,
            $this->demeritTypeId !== '' ? MeritType::query()->findOrFail((int) $this->demeritTypeId) : null,
        );

        unset($this->opened, $this->notices);
        $this->dispatch('notify', message: 'Decided. The employee is told'.($outcome === NteOutcome::Dismissal ? ', and HR, to update the record.' : '.'));
    }

    public function render(): View
    {
        return view('livewire.admin.notices-to-explain');
    }
}
