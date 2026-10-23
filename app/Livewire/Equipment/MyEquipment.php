<?php

namespace App\Livewire\Equipment;

use App\Actions\Equipment\AcknowledgeEquipment;
use App\Actions\Equipment\ExplainEquipmentIncident;
use App\Actions\Equipment\RequestEquipment;
use App\Enums\EquipmentStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EquipmentIncident;
use App\Models\EquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Support\Acknowledgements;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * My Equipment: what I hold (and confirm receiving), what I asked for, and any damage or loss
 * reported about it, where I give my side. Uniform deductions to authorize show here too.
 *
 * @property-read Collection<int, EquipmentIssue> $mine
 * @property-read Collection<int, EquipmentItem> $items
 */
#[Title('My Equipment')]
class MyEquipment extends Component
{
    use InteractsWithUser;

    public string $ackLocale = 'en';

    public bool $requesting = false;

    public string $requestItem = '';

    public string $requestSize = '';

    public string $requestQuantity = '1';

    public string $requestNote = '';

    public ?int $explainingId = null;

    public string $explanation = '';

    /** What needs them: items to confirm, and reports they can still explain (the sidebar badge). */
    public static function waitingFor(User $user): int
    {
        return EquipmentIssue::query()->where('user_id', $user->id)->where('status', EquipmentStatus::Issued)->whereNull('acknowledged_at')->count()
            + EquipmentIncident::query()->open()->whereNull('explained_at')->whereHas('issue', fn ($issue) => $issue->where('user_id', $user->id))->get()
                ->filter(fn (EquipmentIncident $incident) => $incident->canStillExplain())->count();
    }

    public function acknowledge(int $id, AcknowledgeEquipment $acknowledge): void
    {
        $acknowledge->handle($this->currentUser(), $this->ownOrFail($id), Acknowledgements::locale($this->ackLocale));
        unset($this->mine);
        $this->dispatch('notify', message: 'Confirmed. Thank you.');
    }

    public function startRequest(): void
    {
        $this->reset(['requestItem', 'requestSize', 'requestQuantity', 'requestNote']);
        $this->resetErrorBag();
        $this->requesting = true;
    }

    public function request(RequestEquipment $request): void
    {
        $this->validate([
            'requestItem' => ['required', 'integer', 'exists:equipment_items,id'],
            'requestQuantity' => ['required', 'integer', 'min:1', 'max:'.RequestEquipment::MAX_QUANTITY],
            'requestNote' => ['nullable', 'string', 'max:500'],
        ], [], ['requestItem' => 'item', 'requestQuantity' => 'quantity', 'requestNote' => 'note']);

        $request->handle($this->currentUser(), EquipmentItem::query()->findOrFail((int) $this->requestItem),
            filled($this->requestSize) ? $this->requestSize : null, (int) $this->requestQuantity, $this->requestNote);

        $this->reset(['requesting', 'requestItem', 'requestSize', 'requestQuantity', 'requestNote']);
        unset($this->mine);
        $this->dispatch('notify', message: 'Request sent.');
    }

    public function startExplain(int $incidentId): void
    {
        $this->explainingId = $this->ownIncident($incidentId)->id;
        $this->explanation = '';
        $this->resetErrorBag();
    }

    public function explain(ExplainEquipmentIncident $explain): void
    {
        $explain->handle($this->currentUser(), $this->ownIncident((int) $this->explainingId), $this->explanation);
        $this->reset(['explainingId', 'explanation']);
        unset($this->mine);
        $this->dispatch('notify', message: 'Your explanation was sent.');
    }

    public function closeDialogs(): void
    {
        $this->reset(['requesting', 'explainingId', 'explanation']);
        $this->resetErrorBag();
    }

    public function pickedItem(): ?EquipmentItem
    {
        return filled($this->requestItem) ? $this->items->firstWhere('id', (int) $this->requestItem) : null;
    }

    /**
     * Mine: with me first, then asked for, then the rest.
     *
     * @return Collection<int, EquipmentIssue>
     */
    #[Computed]
    public function mine(): Collection
    {
        $order = [EquipmentStatus::Issued->value => 0, EquipmentStatus::Requested->value => 1];

        return EquipmentIssue::query()->where('user_id', $this->currentUser()->id)->with(['incidents', 'issuer', 'deduction'])->orderByDesc('id')->get()
            ->sortBy(fn (EquipmentIssue $issue) => $order[$issue->status->value] ?? 2)->values();
    }

    /**
     * @return Collection<int, EquipmentItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return EquipmentItem::query()->active()->orderBy('kind')->orderBy('name')->get();
    }

    private function ownOrFail(int $id): EquipmentIssue
    {
        return EquipmentIssue::query()->where('user_id', $this->currentUser()->id)->findOrFail($id);
    }

    private function ownIncident(int $id): EquipmentIncident
    {
        return EquipmentIncident::query()->whereHas('issue', fn ($issue) => $issue->where('user_id', $this->currentUser()->id))->findOrFail($id);
    }

    public function render(): View
    {
        return view('livewire.equipment.my-equipment');
    }
}
