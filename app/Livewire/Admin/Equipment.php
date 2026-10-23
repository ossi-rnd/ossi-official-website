<?php

namespace App\Livewire\Admin;

use App\Actions\Equipment\DecideEquipmentIncident;
use App\Actions\Equipment\IssueEquipment;
use App\Actions\Equipment\RejectEquipmentRequest;
use App\Actions\Equipment\ReportEquipmentIncident;
use App\Actions\Equipment\ReturnEquipment;
use App\Actions\Equipment\SaveEquipmentItem;
use App\Actions\Equipment\SetEquipmentItemPrices;
use App\Enums\Ability;
use App\Enums\EquipmentIncidentKind;
use App\Enums\EquipmentKind;
use App\Enums\EquipmentStatus;
use App\Enums\RadioStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EquipmentIncident;
use App\Models\EquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\Radio;
use App\Models\User;
use App\Support\Heads;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The equipment desk. Issuers (Operations for guards, Admin for office staff): requests, what's
 * out, returns, and reporting damage or loss. Heads (EquipmentIncident::canDecide) decide those
 * reports here too, after the employee was heard. Admin keeps the item list and sizes;
 * Accounting sets the prices (and sees only that list).
 *
 * @property-read Collection<int, EquipmentIssue> $requests
 * @property-read Collection<int, EquipmentIssue> $out
 * @property-read Collection<int, EquipmentIncident> $incidents
 * @property-read Collection<int, EquipmentIssue> $history
 * @property-read array{requests: int, out: int, incidents: int} $counts
 * @property-read Collection<int, User> $people
 * @property-read Collection<int, EquipmentItem> $activeItems
 * @property-read Collection<int, EquipmentItem> $allItems
 * @property-read Collection<int, Radio> $spareRadios
 */
#[Title('Equipment')]
class Equipment extends Component
{
    use InteractsWithUser;

    /** requests | out | incidents | history */
    #[Url(as: 'show', except: '')]
    public string $show = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    // Issuing
    public bool $issuing = false;

    public ?int $fromRequestId = null;

    public string $issueToId = '';

    public string $issueKind = 'item';

    public string $issueItemId = '';

    public string $issueRadioId = '';

    public string $issueSize = '';

    public string $issueQuantity = '1';

    public string $issueInstallment = '';

    // Not approving a request
    public ?int $rejectingId = null;

    public string $rejectReason = '';

    // Reporting damage or loss
    public ?int $reportingId = null;

    public string $incidentKind = 'damaged';

    public string $incidentDetails = '';

    // Deciding a report
    public ?int $decidingId = null;

    public string $decisionCharge = 'no';

    public string $decisionReason = '';

    public string $lossAmount = '';

    public string $amountBasis = '';

    public string $installment = '';

    // The item list
    public bool $itemFormOpen = false;

    public ?int $editingItemId = null;

    public string $itemName = '';

    public string $itemKind = 'gear';

    /** @var list<string> the sizes ticked or added, in order */
    public array $itemSizes = [];

    public string $customSize = '';

    public bool $itemActive = true;

    // Prices (Accounting)
    public ?int $pricingItemId = null;

    public string $basePrice = '';

    /** @var list<string> each size's own price, in the item's size order ('' = the item's) */
    public array $sizePrices = [];

    public static function canOpen(User $user): bool
    {
        return $user->can(Ability::IssueEquipment->value) || Heads::headsOfficeStaff($user) || $user->can(Ability::SetEquipmentPrices->value);
    }

    /** Issues, or decides damage and loss: sees the desk. Accounting alone sees only the item list. */
    public function seesDesk(): bool
    {
        return $this->issues() || Heads::headsOfficeStaff($this->currentUser());
    }

    public function mount(): void
    {
        abort_unless(self::canOpen($this->currentUser()), 403);
    }

    public function issues(): bool
    {
        return $this->currentUser()->can(Ability::IssueEquipment->value);
    }

    /** The tab shown: a head who doesn't issue only has reports to decide. */
    public function shownTab(): string
    {
        if (! $this->issues()) {
            return 'incidents';
        }

        return in_array($this->show, ['requests', 'out', 'incidents', 'history'], true) ? $this->show : ($this->counts['requests'] > 0 ? 'requests' : 'out');
    }

    // ---- Issuing ----

    public function startIssue(?int $requestId = null): void
    {
        abort_unless($this->issues(), 403);
        $this->resetIssue();

        if ($requestId !== null) {
            $request = $this->handledIssue($requestId);
            abort_unless($request->status === EquipmentStatus::Requested, 404);
            $this->fromRequestId = $request->id;
            $this->issueToId = (string) $request->user_id;
            $this->issueItemId = (string) $request->equipment_item_id;
            $this->issueSize = (string) $request->size;
            $this->issueQuantity = (string) $request->quantity;
        }

        $this->issuing = true;
    }

    public function issue(IssueEquipment $issueEquipment): void
    {
        abort_unless($this->issues(), 403);

        $this->validate([
            'issueToId' => ['required', 'integer', 'exists:users,id'],
            'issueKind' => ['required', Rule::in(['item', 'radio'])],
            'issueItemId' => [Rule::requiredIf($this->issueKind === 'item'), 'nullable', 'integer', 'exists:equipment_items,id'],
            'issueRadioId' => [Rule::requiredIf($this->issueKind === 'radio'), 'nullable', 'integer', 'exists:radios,id'],
            'issueQuantity' => ['required', 'integer', 'min:1', 'max:'.IssueEquipment::MAX_QUANTITY],
            'issueInstallment' => ['nullable', 'numeric', 'min:0.01', 'max:999999'],
        ], [], ['issueToId' => 'person', 'issueItemId' => 'item', 'issueRadioId' => 'radio', 'issueQuantity' => 'quantity', 'issueInstallment' => 'per payslip']);

        $item = $this->issueKind === 'item' ? EquipmentItem::query()->findOrFail((int) $this->issueItemId) : null;
        $radio = $this->issueKind === 'radio' ? Radio::query()->findOrFail((int) $this->issueRadioId) : null;

        $issueEquipment->handle(
            $this->currentUser(),
            User::query()->with('employeeProfile')->findOrFail((int) $this->issueToId),
            $item,
            $radio,
            filled($this->issueSize) ? $this->issueSize : null,
            (int) $this->issueQuantity,
            filled($this->issueInstallment) ? (float) $this->issueInstallment : null,
            $this->fromRequestId !== null ? $this->handledIssue($this->fromRequestId) : null,
        );

        $this->resetIssue();
        $this->forget();
        $this->dispatch('notify', message: 'Issued. They confirm receipt on My Equipment.');
    }

    public function cancelIssue(): void
    {
        $this->resetIssue();
    }

    private function resetIssue(): void
    {
        $this->reset(['issuing', 'fromRequestId', 'issueToId', 'issueKind', 'issueItemId', 'issueRadioId', 'issueSize', 'issueQuantity', 'issueInstallment']);
        $this->resetErrorBag();
    }

    /** The item picked in the issue form, for its sizes and price. */
    public function pickedItem(): ?EquipmentItem
    {
        return $this->issueKind === 'item' && filled($this->issueItemId) ? $this->activeItems->firstWhere('id', (int) $this->issueItemId) : null;
    }

    // ---- Not approving a request ----

    public function startReject(int $id): void
    {
        $this->rejectingId = $this->handledIssue($id)->id;
        $this->rejectReason = '';
        $this->resetErrorBag();
    }

    public function reject(RejectEquipmentRequest $reject): void
    {
        $reject->handle($this->currentUser(), $this->handledIssue((int) $this->rejectingId), $this->rejectReason);
        $this->reset(['rejectingId', 'rejectReason']);
        $this->forget();
        $this->dispatch('notify', message: 'Not approved. They were told why.');
    }

    // ---- Returns ----

    public function markReturned(int $id, ReturnEquipment $return): void
    {
        $return->handle($this->currentUser(), $this->handledIssue($id), null);
        $this->forget();
        $this->dispatch('notify', message: 'Recorded as returned.');
    }

    // ---- Damage or loss ----

    public function startReport(int $id): void
    {
        $this->reportingId = $this->handledIssue($id)->id;
        $this->reset(['incidentKind', 'incidentDetails']);
        $this->resetErrorBag();
    }

    public function report(ReportEquipmentIncident $report): void
    {
        $this->validate(['incidentKind' => ['required', Rule::enum(EquipmentIncidentKind::class)]]);

        $report->handle($this->currentUser(), $this->handledIssue((int) $this->reportingId), EquipmentIncidentKind::from($this->incidentKind), $this->incidentDetails);
        $this->reset(['reportingId', 'incidentKind', 'incidentDetails']);
        $this->forget();
        $this->dispatch('notify', message: 'Reported. They have '.EquipmentIncident::EXPLAIN_DAYS.' days to explain.');
    }

    public function startDecide(int $id): void
    {
        $incident = $this->visibleIncident($id);
        abort_unless(EquipmentIncident::canDecide($this->currentUser(), $incident->issue->user), 403);

        $this->decidingId = $incident->id;
        $this->reset(['decisionCharge', 'decisionReason', 'lossAmount', 'amountBasis', 'installment']);
        $this->lossAmount = $incident->issue->unit_price !== null ? number_format($incident->issue->total(), 2, '.', '') : '';
        $this->resetErrorBag();
    }

    public function decide(DecideEquipmentIncident $decide): void
    {
        $charge = $this->decisionCharge === 'charge';

        if ($charge) {
            $this->validate([
                'lossAmount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
                'installment' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            ], [], ['lossAmount' => 'actual loss', 'installment' => 'per payslip']);
        }

        $decide->handle($this->currentUser(), $this->visibleIncident((int) $this->decidingId), $charge, $this->decisionReason,
            $charge ? (float) $this->lossAmount : null, $charge ? $this->amountBasis : null, $charge ? (float) $this->installment : null);

        $this->reset(['decidingId', 'decisionCharge', 'decisionReason', 'lossAmount', 'amountBasis', 'installment']);
        $this->forget();
        $this->dispatch('notify', message: $charge ? 'Decided: charged. It shows on their next payslips.' : 'Decided: not charged.');
    }

    public function maxInstallment(): ?float
    {
        $incident = $this->decidingId !== null ? EquipmentIncident::query()->with('issue.user')->find($this->decidingId) : null;

        return $incident === null ? null : EquipmentIncident::maxInstallmentFor($incident->issue->user);
    }

    public function closeDialogs(): void
    {
        $this->reset(['rejectingId', 'rejectReason', 'reportingId', 'incidentKind', 'incidentDetails', 'decidingId', 'decisionCharge', 'decisionReason', 'lossAmount', 'amountBasis', 'installment']);
        $this->resetErrorBag();
    }

    // ---- The item list ----

    public function startItem(?int $id = null): void
    {
        abort_unless($this->currentUser()->can(Ability::ManageEquipmentItems->value), 403);

        $this->cancelItem();
        $this->resetErrorBag();

        if ($id !== null) {
            $item = EquipmentItem::query()->findOrFail($id);
            $this->editingItemId = $item->id;
            $this->itemName = $item->name;
            $this->itemKind = $item->kind->value;
            $this->itemSizes = $item->sizeList();
            $this->itemActive = $item->is_active;
        }

        $this->itemFormOpen = true;
    }

    /** Ticks or unticks a standard size. */
    public function toggleSize(string $size): void
    {
        $this->itemSizes = in_array($size, $this->itemSizes, true)
            ? array_values(array_diff($this->itemSizes, [$size]))
            : SaveEquipmentItem::cleanSizes([...$this->itemSizes, $size]);
    }

    /** Ticks every standard size at once. */
    public function allStandardSizes(): void
    {
        $this->itemSizes = SaveEquipmentItem::cleanSizes([...$this->itemSizes, ...EquipmentItem::STANDARD_SIZES]);
    }

    /** Adds custom sizes: one, or several separated by commas ("38, 39, 40"), or a range ("38-45"). */
    public function addCustomSize(): void
    {
        $this->resetErrorBag('customSize');
        $entries = [];

        foreach (explode(',', $this->customSize) as $part) {
            $part = trim($part);

            if (preg_match('/^(\d{1,3})\s*(?:-|to)\s*(\d{1,3})$/i', $part, $range) === 1 && (int) $range[2] > (int) $range[1] && (int) $range[2] - (int) $range[1] < EquipmentItem::MAX_SIZES) {
                array_push($entries, ...array_map('strval', range((int) $range[1], (int) $range[2])));
            } elseif ($part !== '') {
                $entries[] = $part;
            }
        }

        $this->itemSizes = SaveEquipmentItem::cleanSizes([...$this->itemSizes, ...$entries]);
        $this->customSize = '';
    }

    public function removeSize(string $size): void
    {
        $this->itemSizes = array_values(array_diff($this->itemSizes, [$size]));
    }

    public function saveItem(SaveEquipmentItem $save): void
    {
        $this->validate([
            'itemName' => ['required', 'string', 'max:80'],
            'itemKind' => ['required', Rule::enum(EquipmentKind::class)],
        ], [], ['itemName' => 'name']);

        $save->handle($this->editingItemId !== null ? EquipmentItem::query()->findOrFail($this->editingItemId) : null,
            $this->itemName, EquipmentKind::from($this->itemKind), $this->itemSizes, $this->itemActive, $this->currentUser());

        $this->cancelItem();
        unset($this->allItems, $this->activeItems);
        $this->dispatch('notify', message: 'Item saved.'.(EquipmentKind::from($this->itemKind) === EquipmentKind::Uniform ? ' Accounting sets its price.' : ''));
    }

    public function cancelItem(): void
    {
        $this->reset(['itemFormOpen', 'editingItemId', 'itemName', 'itemKind', 'itemSizes', 'customSize', 'itemActive']);
    }

    // ---- Prices (Accounting) ----

    public function startPricing(int $id): void
    {
        abort_unless($this->currentUser()->can(Ability::SetEquipmentPrices->value), 403);

        $item = EquipmentItem::query()->findOrFail($id);
        $this->pricingItemId = $item->id;
        $this->basePrice = $item->unit_price === null ? '' : (string) $item->unit_price;
        $this->sizePrices = array_map(fn (array $entry) => $entry['price'] === null ? '' : number_format((float) $entry['price'], 2, '.', ''), $item->sizes ?? []);
        $this->resetErrorBag();
    }

    public function savePrices(SetEquipmentItemPrices $setPrices): void
    {
        $this->validate([
            'basePrice' => ['nullable', 'numeric', 'min:0', 'max:'.SetEquipmentItemPrices::MAX_PRICE],
            'sizePrices.*' => ['nullable', 'numeric', 'min:0', 'max:'.SetEquipmentItemPrices::MAX_PRICE],
        ], [], ['basePrice' => 'price', 'sizePrices.*' => 'size price']);

        $item = EquipmentItem::query()->findOrFail((int) $this->pricingItemId);
        $bySize = [];
        foreach ($item->sizeList() as $index => $size) {
            $bySize[$size] = filled($this->sizePrices[$index] ?? '') ? (float) $this->sizePrices[$index] : null;
        }

        $setPrices->handle($item, filled($this->basePrice) ? (float) $this->basePrice : null, $bySize, $this->currentUser());

        $this->reset(['pricingItemId', 'basePrice', 'sizePrices']);
        unset($this->allItems, $this->activeItems);
        $this->dispatch('notify', message: 'Prices saved. Items issued from now on use them.');
    }

    public function cancelPricing(): void
    {
        $this->reset(['pricingItemId', 'basePrice', 'sizePrices']);
    }

    // ---- Lists ----

    /**
     * @return Collection<int, EquipmentIssue>
     */
    #[Computed]
    public function requests(): Collection
    {
        return $this->issuesQuery()->where('status', EquipmentStatus::Requested)->orderBy('requested_at')->get();
    }

    /**
     * @return Collection<int, EquipmentIssue>
     */
    #[Computed]
    public function out(): Collection
    {
        return $this->issuesQuery()->where('status', EquipmentStatus::Issued)->with(['incidents', 'deduction'])->orderByDesc('issued_at')->limit(200)->get();
    }

    /**
     * Open reports first (ready to decide before still waiting), then the latest decided.
     *
     * @return Collection<int, EquipmentIncident>
     */
    #[Computed]
    public function incidents(): Collection
    {
        return EquipmentIncident::query()->visibleTo($this->currentUser())->with(['issue.user', 'reporter', 'decider'])
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->whereHas('issue', fn (Builder $issue) => $this->matching($issue)))
            ->orderByRaw('decision is not null')->orderByDesc('id')->limit(100)->get();
    }

    /**
     * @return Collection<int, EquipmentIssue>
     */
    #[Computed]
    public function history(): Collection
    {
        return $this->issuesQuery()->whereIn('status', [EquipmentStatus::Returned, EquipmentStatus::Lost, EquipmentStatus::Rejected])->orderByDesc('updated_at')->limit(100)->get();
    }

    /**
     * @return array{requests: int, out: int, incidents: int}
     */
    #[Computed]
    public function counts(): array
    {
        $viewer = $this->currentUser();

        return [
            'requests' => $this->issues() ? EquipmentIssue::query()->handledBy($viewer)->where('status', EquipmentStatus::Requested)->count() : 0,
            'out' => $this->issues() ? EquipmentIssue::query()->handledBy($viewer)->where('status', EquipmentStatus::Issued)->count() : 0,
            'incidents' => self::incidentsToDecide($viewer),
        ];
    }

    /** Reports this person can decide now (the sidebar badge, with requests). */
    public static function incidentsToDecide(User $viewer): int
    {
        return EquipmentIncident::query()->visibleTo($viewer)->open()->with('issue.user')->get()
            ->filter(fn (EquipmentIncident $incident) => $incident->readyToDecide() && EquipmentIncident::canDecide($viewer, $incident->issue->user))
            ->count();
    }

    /**
     * People this viewer issues to, still working here.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()->onPayroll()->where(fn (Builder $users) => EquipmentIssue::constrainPeopleHandledBy($users, $this->currentUser()))
            ->whereKeyNot($this->currentUser()->id)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, EquipmentItem>
     */
    #[Computed]
    public function activeItems(): Collection
    {
        return EquipmentItem::query()->active()->orderBy('kind')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, EquipmentItem>
     */
    #[Computed]
    public function allItems(): Collection
    {
        return EquipmentItem::query()->orderByDesc('is_active')->orderBy('kind')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Radio>
     */
    #[Computed]
    public function spareRadios(): Collection
    {
        return Radio::query()->where('status', RadioStatus::Spare)->orderBy('serial_number')->get();
    }

    /**
     * @return Builder<EquipmentIssue>
     */
    private function issuesQuery(): Builder
    {
        return EquipmentIssue::query()->handledBy($this->currentUser())->with(['user', 'issuer'])
            ->when(trim($this->search) !== '', fn (Builder $query) => $this->matching($query));
    }

    /**
     * @param  Builder<EquipmentIssue>  $query
     */
    private function matching(Builder $query): void
    {
        $term = trim($this->search);
        $query->where(fn (Builder $match) => $match->where('label', 'like', "%{$term}%")
            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$term}%")));
    }

    private function handledIssue(int $id): EquipmentIssue
    {
        return EquipmentIssue::query()->handledBy($this->currentUser())->with('user')->findOrFail($id);
    }

    private function visibleIncident(int $id): EquipmentIncident
    {
        return EquipmentIncident::query()->visibleTo($this->currentUser())->with('issue.user')->findOrFail($id);
    }

    private function forget(): void
    {
        unset($this->requests, $this->out, $this->incidents, $this->history, $this->counts, $this->spareRadios);
    }

    public function render(): View
    {
        return view('livewire.admin.equipment');
    }
}
