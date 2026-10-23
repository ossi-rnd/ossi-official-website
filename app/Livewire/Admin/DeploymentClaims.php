<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\CancelReimbursement;
use App\Actions\Payroll\FileReimbursement;
use App\Enums\ReimbursementCategory;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\Reimbursement;
use App\Models\User;
use App\Queries\People\PeopleToActOnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Deployment costs (roadmap 5): Operations claims what a guard paid for a deployment
 * (a fare to a far post, a reliever's ride), with the receipt. The President or Vice President approves
 * it like any reimbursement, and the guard is paid back on their next payslip, not
 * taxed. Operation HQ: any guard; a Satellite: its region's (Reimbursement::canFileFor).
 * Operations staff's own deployment costs go on My Reimbursements ("Deployment").
 *
 * @property-read Collection<int, Reimbursement> $claims
 * @property-read Collection<int, AbsenceNotice> $reliefsToClaim
 * @property-read Collection<int, User> $candidates
 * @property-read User|null $chosen
 * @property-read Collection<int, DutyAssignment> $recentDuties
 * @property-read Collection<int, Client> $clientChoices
 */
#[Title('Deployment Costs')]
class DeploymentClaims extends Component
{
    use ChoosesAcknowledgementLanguage, InteractsWithUser, WithFileUploads;

    public bool $filing = false;

    public ?int $guardId = null;

    public string $personSearch = '';

    public string $spentOn = '';

    public string $amount = '';

    public string $purpose = '';

    public string $deploymentFor = '';

    /**
     * How "Which deployment" is answered: '' choosing from the guard's roster, 'picked' a roster duty
     * (or the relief this claim came from), locked with Change; 'typed' written by hand, only when
     * Operations says it isn't on the roster.
     */
    public string $deploymentMode = '';

    /** "Another client's post": the client picked from the list. */
    public string $deploymentClientId = '';

    /** @var UploadedFile|null */
    public $receipt = null;

    public bool $acknowledges = false;

    /** The relief a reliever's fare comes from (?relief=, from Relievers), if any. */
    #[Url(as: 'relief', except: null)]
    public ?int $reliefId = null;

    public function mount(): void
    {
        if ($this->reliefId !== null) {
            $this->startForRelief($this->reliefId);
        }
    }

    /**
     * Reliefs done lately with no fare claim yet, for guards this person files for:
     * the usual claims, one tap away.
     *
     * @return Collection<int, AbsenceNotice>
     */
    #[Computed]
    public function reliefsToClaim(): Collection
    {
        $me = $this->currentUser();

        return AbsenceNotice::query()->visibleTo($me)->awaitingFareClaim()
            ->with(['user', 'reliever.employeeProfile'])->latest('duty_date')->limit(20)->get()
            ->filter(fn (AbsenceNotice $relief) => $relief->reliever !== null && Reimbursement::canFileFor($me, $relief->reliever))
            ->take(8)->values();
    }

    /** The reliever's fare for this relief: the form, filled from it. */
    public function startForRelief(int $id): void
    {
        $relief = AbsenceNotice::query()->visibleTo($this->currentUser())->with(['user', 'reliever.employeeProfile'])->findOrFail($id);
        abort_unless($relief->reliever !== null && $relief->fareClaimable() && Reimbursement::canFileFor($this->currentUser(), $relief->reliever), 404);

        $this->start();
        $this->reliefId = $relief->id;
        $this->guardId = $relief->reliever->id;
        $this->deploymentFor = $relief->reliefLabel();
        $this->deploymentMode = 'picked'; // that relief, locked (Change to pick another)
        $this->spentOn = $relief->duty_date->toDateString();
        unset($this->chosen);
    }

    /**
     * The chosen guard's duties on the roster within the filing window (newest first), to pick
     * from instead of typing: their own posts and the reliefs they covered.
     *
     * @return Collection<int, DutyAssignment>
     */
    #[Computed]
    public function recentDuties(): Collection
    {
        if ($this->guardId === null) {
            return new Collection;
        }

        return DutyAssignment::query()->where('user_id', $this->guardId)
            // By date: duty_date is stored with a time ("… 00:00:00"), so a plain between would drop today.
            ->whereDate('duty_date', '>=', today()->subDays(Reimbursement::fileWithinDays())->toDateString())
            ->whereDate('duty_date', '<=', today()->toDateString())
            ->with(['client', 'absenceNotice.user'])
            ->orderByDesc('duty_date')->orderByDesc('id')->limit(12)->get();
    }

    /** "Relief at Metrobank – Imus, Gate 2, Oct 20, for Juan Cruz" or "BDO – Makati, Lobby, Oct 21". */
    public function dutyLabel(DutyAssignment $duty): string
    {
        if ($duty->absenceNotice !== null) {
            return $duty->absenceNotice->reliefLabel();
        }

        return collect([$duty->client?->name, $duty->post])->filter()->implode(', ').', '.$duty->duty_date->format('M j');
    }

    /** A duty picked from the roster: the deployment and the date, filled in; a relief's fare is linked to it. */
    public function pickDuty(int $dutyId): void
    {
        $duty = $this->recentDuties->firstWhere('id', $dutyId);
        abort_if($duty === null, 404);

        $this->deploymentFor = $this->dutyLabel($duty);
        $this->deploymentMode = 'picked';
        $this->spentOn = $duty->duty_date->toDateString();

        // A relief whose fare isn't claimed yet: claim it as that relief (once), as from Relievers.
        $notice = $duty->absenceNotice;
        $this->reliefId = $notice !== null && $notice->fareClaimable() && AbsenceNotice::query()->awaitingFareClaim()->whereKey($notice->id)->exists()
            ? $notice->id
            : null;
        $this->resetErrorBag(['deploymentFor', 'relief']);
    }

    /**
     * The claims this person filed for guards.
     *
     * @return Collection<int, Reimbursement>
     */
    #[Computed]
    public function claims(): Collection
    {
        return Reimbursement::query()->where('filed_by', $this->currentUser()->id)->whereColumn('filed_by', '!=', 'user_id')
            ->with(['user.employeeProfile.client', 'decider:id,name', 'paidInRun', 'absenceNotice.user'])->latest('id')->limit(50)->get();
    }

    /**
     * Guards they may file for, by name or employee number.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $me = $this->currentUser();

        return app(PeopleToActOnQuery::class)->handle($me, $this->personSearch, fn (User $user) => Reimbursement::canFileFor($me, $user));
    }

    #[Computed]
    public function chosen(): ?User
    {
        return $this->guardId !== null ? User::query()->with('employeeProfile.client')->find($this->guardId) : null;
    }

    public function start(): void
    {
        $this->reset(['guardId', 'personSearch', 'amount', 'purpose', 'deploymentFor', 'deploymentMode', 'deploymentClientId', 'receipt', 'acknowledges', 'reliefId']);
        $this->spentOn = CarbonImmutable::today()->toDateString();
        $this->filing = true;
        $this->resetErrorBag();
    }

    public function choose(int $userId): void
    {
        $guard = User::query()->with('employeeProfile.client')->findOrFail($userId);
        abort_unless(Reimbursement::canFileFor($this->currentUser(), $guard), 403);

        $this->guardId = $guard->id;
        $this->personSearch = '';
        unset($this->chosen, $this->recentDuties);

        // Always choices first: their roster duties, their usual post, another client's post.
        $this->deploymentMode = '';
        $this->deploymentFor = '';
        $this->deploymentClientId = '';
    }

    /** Their usual post, from their employee record. */
    public function usualPost(): ?string
    {
        $profile = $this->chosen?->employeeProfile;

        return $profile !== null && $profile->hasDeployment() ? $profile->assignmentLabel() : null;
    }

    public function pickUsualPost(): void
    {
        $post = $this->usualPost();
        abort_if($post === null, 404);

        $this->deploymentFor = $post;
        $this->deploymentMode = 'picked';
        $this->reliefId = null;
        $this->resetErrorBag(['deploymentFor', 'relief']);
    }

    /** "Another client's post": the client list opens. */
    public function chooseClient(): void
    {
        $this->deploymentMode = 'client';
        $this->deploymentClientId = '';
        $this->reliefId = null;
    }

    /** A client picked from the list: that post, locked. */
    public function updatedDeploymentClientId(): void
    {
        $client = filled($this->deploymentClientId) ? $this->clientChoices->firstWhere('id', (int) $this->deploymentClientId) : null;

        if ($client !== null) {
            $this->deploymentFor = $client->name;
            $this->deploymentMode = 'picked';
            $this->resetErrorBag(['deploymentFor', 'relief']);
        }
    }

    /**
     * Clients this person deploys to: all, or a Satellite's own region.
     *
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clientChoices(): Collection
    {
        $me = $this->currentUser();

        return Client::query()
            ->when($me->isRegionallyLimited(), fn ($query) => $query->where('region', $me->operations_region))
            ->orderBy('name')->get();
    }

    /** "Not on the roster? Type it": the text box, empty, and no relief linked. */
    public function typeDeployment(): void
    {
        $this->deploymentMode = 'typed';
        $this->deploymentFor = '';
        $this->reliefId = null;
        $this->resetErrorBag(['deploymentFor', 'relief']);
    }

    /** Change the picked (or typed) deployment: back to the choices. */
    public function changeDeployment(): void
    {
        $this->deploymentMode = '';
        $this->deploymentFor = '';
        $this->deploymentClientId = '';
        $this->reliefId = null;
        $this->resetErrorBag(['deploymentFor', 'relief']);
    }

    public function closeForm(): void
    {
        $this->reset(['filing', 'reliefId']);
    }

    public function changeGuard(): void
    {
        // Another guard: no longer that relief's reliever.
        $this->reset(['guardId', 'deploymentFor', 'deploymentMode', 'deploymentClientId', 'reliefId']);
        unset($this->chosen, $this->recentDuties);
    }

    public function file(FileReimbursement $file): void
    {
        $validated = $this->validate([
            'guardId' => ['required', 'integer'],
            'spentOn' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.Reimbursement::maxAmount(deployment: true)],
            'purpose' => ['required', 'string', 'min:10', 'max:1000'],
            'deploymentFor' => ['required', 'string', 'max:255'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'acknowledges' => ['accepted'],
        ], [
            'guardId.required' => 'Choose the guard.',
            'deploymentFor.required' => 'Pick a duty, their post or a client, or type it if it isn\'t on any list.',
            'acknowledges.accepted' => 'Read the points above and tick the box to send.',
            'receipt.required' => 'Attach the receipt (a photo or a PDF).',
            'receipt.mimes' => 'Attach a photo (JPG, PNG) or a PDF.',
            'purpose.min' => 'Say what it was for: the trip, and why.',
        ], ['deploymentFor' => 'deployment']);

        if (! $this->receipt instanceof UploadedFile) {
            throw ValidationException::withMessages(['receipt' => 'Attach the receipt (a photo or a PDF).']);
        }

        $guard = User::query()->with('employeeProfile.client')->findOrFail((int) $validated['guardId']);

        $file->handle(
            $guard,
            ReimbursementCategory::Deployment,
            CarbonImmutable::parse($validated['spentOn']),
            (float) $validated['amount'],
            $validated['purpose'],
            $this->receipt,
            acknowledged: true,
            locale: $this->ackLocale,
            filedBy: $this->currentUser(),
            deploymentFor: $validated['deploymentFor'],
            relief: $this->reliefId !== null ? AbsenceNotice::query()->visibleTo($this->currentUser())->find($this->reliefId) : null,
        );

        $this->reset(['filing', 'guardId', 'personSearch', 'amount', 'purpose', 'deploymentFor', 'receipt', 'acknowledges', 'reliefId']);
        unset($this->claims, $this->reliefsToClaim);
        $this->dispatch('notify', message: "Sent to the President or Vice President. Once approved, it's added to {$guard->name}'s next payslip.");
    }

    public function cancel(int $id, CancelReimbursement $cancel): void
    {
        $cancel->handle($this->currentUser(), Reimbursement::query()->where('filed_by', $this->currentUser()->id)->findOrFail($id));
        unset($this->claims);
        $this->dispatch('notify', message: 'Cancelled.');
    }

    public function render(): View
    {
        return view('livewire.admin.deployment-claims');
    }
}
