<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\CancelReimbursement;
use App\Actions\Payroll\FileReimbursement;
use App\Enums\ReimbursementCategory;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AbsenceNotice;
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
        $this->spentOn = $relief->duty_date->toDateString();
        unset($this->chosen);
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
        $this->reset(['guardId', 'personSearch', 'amount', 'purpose', 'deploymentFor', 'receipt', 'acknowledges', 'reliefId']);
        $this->spentOn = CarbonImmutable::today()->toDateString();
        $this->filing = true;
        $this->resetErrorBag();
    }

    public function choose(int $userId): void
    {
        $guard = User::query()->with('employeeProfile.client')->findOrFail($userId);
        abort_unless(Reimbursement::canFileFor($this->currentUser(), $guard), 403);

        $this->guardId = $guard->id;
        // Their post, as the usual answer; Operations can say otherwise (a relief elsewhere).
        $this->deploymentFor = (string) $guard->employeeProfile?->assignmentLabel();
        $this->personSearch = '';
        unset($this->chosen);
    }

    public function closeForm(): void
    {
        $this->reset(['filing', 'reliefId']);
    }

    public function changeGuard(): void
    {
        // Another guard: no longer that relief's reliever.
        $this->reset(['guardId', 'deploymentFor', 'reliefId']);
        unset($this->chosen);
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
