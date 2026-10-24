<?php

namespace App\Livewire\Admin;

use App\Actions\Attendance\ForgetGuardPhone;
use App\Actions\People\DecideProbationEvaluation;
use App\Actions\People\DeployEmployee;
use App\Actions\People\EvaluateProbation;
use App\Actions\People\SaveEmployeeProfile;
use App\Actions\People\ShiftForTimes;
use App\Data\ResolvedRate;
use App\Enums\Ability;
use App\Enums\AbsenceNoticeStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Livewire\Forms\EmployeeProfileForm;
use App\Models\AbsenceNotice;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRate;
use App\Models\ProbationEvaluation;
use App\Models\Shift;
use App\Models\TrainingAttendee;
use App\Queries\People\EffectiveRatesQuery;
use App\Support\ProbationRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read EmployeeProfile $profile
 * @property-read Collection<int, Shift> $shifts
 * @property-read Collection<int, EmployeeRate> $rates
 * @property-read ResolvedRate $paidRate
 * @property-read Collection<int, Client> $deployableClients
 * @property-read Collection<int, TrainingAttendee> $trainingRecords
 * @property-read Collection<int, AwayLog> $recentAways
 * @property-read Collection<int, AbsenceNotice> $recentAbsences
 * @property-read ProbationEvaluation|null $evaluation
 */
#[Title('Employee Profile')]
class EmployeeDetail extends Component
{
    use InteractsWithUser;

    #[Locked]
    public int $profileId;

    public bool $editing = false;

    public EmployeeProfileForm $form;

    /** "Change deployment": Operations moves a guard without HR's full edit. */
    public bool $deploying = false;

    public string $deployClientId = '';

    public string $deployPost = '';

    public string $deployShiftId = '';

    public function mount(EmployeeProfile $profile): void
    {
        // A Satellite Operation opens only records in their region.
        abort_unless($this->currentUser()->canSeeProfile($profile), 403);

        $this->profileId = $profile->id;
    }

    #[Computed]
    public function profile(): EmployeeProfile
    {
        return EmployeeProfile::query()->with(['user.roles', 'user.device', 'shift', 'client', 'office'])->findOrFail($this->profileId);
    }

    /** Lost phone, or a new company phone handed out: their next clock-in registers the phone they use. */
    public function forgetPhone(ForgetGuardPhone $forget): void
    {
        $guard = $this->profile->user;
        abort_if($guard === null, 404);

        try {
            $forget->handle($this->currentUser(), $guard);
        } catch (AuthorizationException) {
            abort(403);
        }

        unset($this->profile);
        $this->dispatch('notify', message: 'Phone forgotten. Their next clock-in registers the phone they use.');
    }

    /**
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        return Shift::query()->orderBy('name')->get();
    }

    /** Region → province → detachment stay consistent as HR picks. */
    public function updatedForm(mixed $value, string $key): void
    {
        $this->form->areaChanged($key);
        $this->form->numberChanged($key);
    }

    /** Keep the saved number, or replace it with the one on their ID card. */
    public function useNumberFrom(string $source): void
    {
        $this->form->useNumberFrom($source);
        $this->resetErrorBag('form.employee_number');
    }

    public function edit(): void
    {
        $this->authorize(Ability::ManagePeople->value);

        $this->form->fillFrom($this->profile);
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function save(SaveEmployeeProfile $saveProfile, ShiftForTimes $shiftForTimes): void
    {
        $this->authorize(Ability::ManagePeople->value);

        $attributes = $this->form->toProfileAttributes();
        $saveProfile->handle($this->profile, $attributes, $this->form->chosenShift($shiftForTimes, $this->currentUser()), $this->currentUser());

        unset($this->profile);
        $this->editing = false;
        $this->dispatch('notify', message: 'Employee record saved.');
    }

    // ---- Probation: the head evaluates, HR approves ---------------------

    public bool $evaluating = false;

    public string $evaluationOutcome = '';

    public string $evaluationReason = '';

    public bool $returning = false;

    public string $decisionNote = '';

    /** The evaluation to show: waiting for HR, else the latest one. */
    #[Computed]
    public function evaluation(): ?ProbationEvaluation
    {
        return $this->profile->probationEvaluations()->with(['evaluator', 'decider'])
            ->orderByRaw("status = 'pending' desc")->latest('id')->first();
    }

    public function canEvaluate(): bool
    {
        $evaluation = $this->evaluation;

        return ProbationEvaluation::canEvaluate($this->currentUser(), $this->profile)
            && ! in_array($evaluation?->status, [ProbationEvaluation::PENDING, ProbationEvaluation::APPROVED], true)
            && ! (ProbationRules::endsOn($this->profile)?->isBefore(CarbonImmutable::today()) ?? true);
    }

    public function canDecideEvaluation(): bool
    {
        return $this->evaluation?->status === ProbationEvaluation::PENDING
            && ProbationEvaluation::canDecide($this->currentUser(), $this->profile);
    }

    public function startEvaluation(): void
    {
        abort_unless($this->canEvaluate(), 403);

        $this->reset('evaluationOutcome', 'evaluationReason');
        $this->resetErrorBag();
        $this->evaluating = true;
    }

    public function evaluate(EvaluateProbation $evaluate): void
    {
        try {
            $evaluation = $evaluate->handle($this->currentUser(), $this->profile, $this->evaluationOutcome, $this->evaluationReason);
        } catch (AuthorizationException) {
            abort(403);
        }

        unset($this->profile, $this->evaluation);
        $this->evaluating = false;
        $this->dispatch('notify', message: $evaluation->status === ProbationEvaluation::APPROVED
            ? ($evaluation->passes() ? 'Approved: they are now Regular, and were told.' : 'Approved: they received the written notice. Employment ends after the last day of probation.')
            : 'Evaluation sent to HR to approve.');
    }

    public function approveEvaluation(DecideProbationEvaluation $decide): void
    {
        $evaluation = $this->evaluation;
        abort_if($evaluation === null, 404);

        try {
            $decide->handle($this->currentUser(), $evaluation, approve: true);
        } catch (AuthorizationException) {
            abort(403);
        }

        unset($this->profile, $this->evaluation);
        $this->dispatch('notify', message: $evaluation->passes() ? 'Approved: they are now Regular, and were told.' : 'Approved: they received the written notice. Employment ends after the last day of probation.');
    }

    public function startReturn(): void
    {
        abort_unless($this->canDecideEvaluation(), 403);

        $this->reset('decisionNote');
        $this->resetErrorBag();
        $this->returning = true;
    }

    public function returnEvaluation(DecideProbationEvaluation $decide): void
    {
        $evaluation = $this->evaluation;
        abort_if($evaluation === null, 404);

        try {
            $decide->handle($this->currentUser(), $evaluation, approve: false, note: $this->decisionNote);
        } catch (AuthorizationException) {
            abort(403);
        }

        unset($this->evaluation);
        $this->returning = false;
        $this->dispatch('notify', message: 'Sent back. The head was told why.');
    }

    // ---- Deployment (client, post, shift) -------------------------------

    public function startDeployment(): void
    {
        $this->authorize(Ability::DeployGuards->value);

        $this->deployClientId = (string) $this->profile->client_id;
        $this->deployPost = (string) $this->profile->post;
        $this->deployShiftId = (string) $this->profile->shift_id;
        $this->resetErrorBag();
        $this->deploying = true;
    }

    public function saveDeployment(DeployEmployee $deploy): void
    {
        $this->authorize(Ability::DeployGuards->value);

        $this->validate([
            'deployClientId' => ['nullable', 'integer', 'exists:clients,id'],
            'deployPost' => ['nullable', 'string', 'max:255'],
            'deployShiftId' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $deploy->handle(
            $this->profile,
            $this->deployClientId !== '' ? Client::query()->find((int) $this->deployClientId) : null,
            $this->deployPost,
            $this->deployShiftId !== '' ? Shift::query()->find((int) $this->deployShiftId) : null,
            $this->currentUser(),
        );

        unset($this->profile);
        $this->deploying = false;
        $this->dispatch('notify', message: 'Deployment saved.');
    }

    /**
     * The clients this user may deploy to: all, or their region's.
     *
     * @return Collection<int, Client>
     */
    #[Computed]
    public function deployableClients(): Collection
    {
        $user = $this->currentUser();

        return Client::query()
            ->when($user->isRegionallyLimited(), fn ($query) => $query->where('region', $user->operations_region->value ?? ''))
            ->orderBy('region')->orderBy('province')->orderBy('name')
            ->get();
    }

    /**
     * Trainings they were in, newest first.
     *
     * @return Collection<int, TrainingAttendee>
     */
    #[Computed]
    public function trainingRecords(): Collection
    {
        return TrainingAttendee::query()
            ->where('employee_profile_id', $this->profileId)
            ->with('training')
            ->get()
            ->sortByDesc(fn (TrainingAttendee $attendee) => $attendee->training->starts_on)
            ->values();
    }

    /**
     * Their time away in the last 30 days, newest first (the summary only: the
     * map and trail are on Patrols / Office Away for those who monitor it).
     *
     * @return Collection<int, AwayLog>
     */
    #[Computed]
    public function recentAways(): Collection
    {
        if ($this->profile->user_id === null) {
            return new Collection;
        }

        return AwayLog::query()
            ->where('user_id', $this->profile->user_id)
            ->where('left_at', '>=', CarbonImmutable::today()->subDays(30))
            ->with(['approver', 'latestLocation'])
            ->latest('left_at')
            ->get();
    }

    /**
     * Absences in the last 90 days, newest first: reported ahead, or not
     * reported at all (no-shows), so a pattern of no-shows stands out.
     *
     * @return Collection<int, AbsenceNotice>
     */
    #[Computed]
    public function recentAbsences(): Collection
    {
        if ($this->profile->user_id === null) {
            return new Collection;
        }

        return AbsenceNotice::query()
            ->where('user_id', $this->profile->user_id)
            ->where('status', '!=', AbsenceNoticeStatus::Cancelled)
            ->whereDate('duty_date', '>=', CarbonImmutable::today()->subDays(90)->toDateString())
            ->with('reliever:id,name')
            ->latest('duty_date')
            ->get();
    }

    // ---- Pay rate (read-only here: Accounting sets it on Daily Rates) ------

    /**
     * Newest first. The first row whose date has passed is the rate in force.
     *
     * @return Collection<int, EmployeeRate>
     */
    #[Computed]
    public function rates(): Collection
    {
        return EmployeeRate::query()->where('employee_profile_id', $this->profileId)->with('setter:id,name')->orderByDesc('effective_from')->get();
    }

    /**
     * What payroll pays them today: personal rate, else their position's, else the company default.
     */
    #[Computed]
    public function paidRate(): ResolvedRate
    {
        return app(EffectiveRatesQuery::class)->forProfile($this->profile, CarbonImmutable::today());
    }

    public function render(): View
    {
        return view('livewire.admin.employee-detail');
    }
}
