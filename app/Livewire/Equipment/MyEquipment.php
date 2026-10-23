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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * My Equipment, in two tabs:
 *  - Shop: the items for me (guards see guard items, office staff office items; EquipmentItem::for),
 *    with photo, sizes and price. I add what I need to a cart and send it as one request.
 *  - My items: what I hold (and confirm receiving), what I asked for, and any damage or loss
 *    reported about it, where I give my side.
 * Uniform deductions to authorize show above both, as on My Payslips.
 *
 * @property-read Collection<int, EquipmentIssue> $mine
 * @property-read Collection<int, EquipmentItem> $items
 * @property-read list<array{index: int, item: EquipmentItem, size: string|null, quantity: int, price: float|null}> $cartLines
 */
#[Title('My Equipment')]
class MyEquipment extends Component
{
    use InteractsWithUser;

    /** shop | mine; '' = the default (My items while something waits for me, else the shop). */
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public string $ackLocale = 'en';

    /** @var array<int|string, array{size?: string, quantity?: string}> each card's size and quantity, by item id */
    public array $picks = [];

    /**
     * The cart, kept for the session (it survives moving to another page and back).
     *
     * @var list<array{item_id: int, size: string|null, quantity: int}>
     */
    #[Session]
    public array $cart = [];

    public string $cartNote = '';

    public ?int $explainingId = null;

    public string $explanation = '';

    /** What needs them: items to confirm, and reports they can still explain (the sidebar badge). */
    public static function waitingFor(User $user): int
    {
        return EquipmentIssue::query()->where('user_id', $user->id)->where('status', EquipmentStatus::Issued)->whereNull('acknowledged_at')->count()
            + EquipmentIncident::query()->open()->whereNull('explained_at')->whereHas('issue', fn ($issue) => $issue->where('user_id', $user->id))->get()
                ->filter(fn (EquipmentIncident $incident) => $incident->canStillExplain())->count();
    }

    public function shownTab(): string
    {
        return in_array($this->tab, ['shop', 'mine'], true) ? $this->tab : (self::waitingFor($this->currentUser()) > 0 ? 'mine' : 'shop');
    }

    // ---- The shop and the cart ----

    /** A size chip on a card was tapped. */
    public function pickSize(int $itemId, string $size): void
    {
        $this->picks[$itemId]['size'] = $size;
        $this->resetErrorBag("picks.{$itemId}");
    }

    /** The − / + stepper on a card. */
    public function pickQuantity(int $itemId, int $by): void
    {
        $now = (int) ($this->picks[$itemId]['quantity'] ?? 1);
        $this->picks[$itemId]['quantity'] = (string) min(RequestEquipment::MAX_QUANTITY, max(1, $now + $by));
    }

    public function addToCart(int $itemId): void
    {
        $item = $this->items->firstWhere('id', $itemId);
        abort_if($item === null, 404);

        $size = $item->hasSizes() ? ($this->picks[$itemId]['size'] ?? '') : null;
        $quantity = (int) ($this->picks[$itemId]['quantity'] ?? 1);
        $this->resetErrorBag("picks.{$itemId}");

        if ($item->hasSizes() && ! in_array($size, $item->sizeList(), true)) {
            $this->addError("picks.{$itemId}", 'Choose your size.');

            return;
        }

        // The same item and size again: add to its quantity.
        foreach ($this->cart as $index => $line) {
            if ($line['item_id'] === $itemId && $line['size'] === $size) {
                $this->changeQuantity($index, max(1, $quantity));
                $this->dispatch('notify', message: "{$item->name} added.");

                return;
            }
        }

        if (count($this->cart) >= RequestEquipment::MAX_LINES) {
            $this->addError("picks.{$itemId}", 'Your cart is full ('.RequestEquipment::MAX_LINES.' items). Send it first.');

            return;
        }

        $this->cart[] = ['item_id' => $itemId, 'size' => $size, 'quantity' => min(RequestEquipment::MAX_QUANTITY, max(1, $quantity))];
        $this->dispatch('notify', message: "{$item->name} added to your cart.");
    }

    public function changeQuantity(int $index, int $by): void
    {
        $this->cart = array_map(fn (array $line, int $i) => $i === $index
            ? [...$line, 'quantity' => min(RequestEquipment::MAX_QUANTITY, max(1, $line['quantity'] + $by))]
            : $line, $this->cart, array_keys($this->cart));
    }

    public function removeFromCart(int $index): void
    {
        $this->cart = array_values(array_filter($this->cart, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function sendCart(RequestEquipment $request): void
    {
        $lines = array_map(fn (array $line) => ['item' => $line['item'], 'size' => $line['size'], 'quantity' => $line['quantity']], $this->cartLines);

        if (count($lines) !== count($this->cart)) {
            // Something in it is no longer given out (or no longer for them): drop it, and say so.
            $this->cart = array_map(fn (array $line) => ['item_id' => $line['item']->id, 'size' => $line['size'], 'quantity' => $line['quantity']], $this->cartLines);
            unset($this->cartLines);
            throw ValidationException::withMessages(['cart' => 'An item in your cart is no longer available and was taken out. Check it and send again.']);
        }

        $request->handle($this->currentUser(), $lines, $this->cartNote);

        $this->reset(['cart', 'cartNote']);
        $this->tab = 'mine';
        unset($this->mine, $this->cartLines);
        $this->dispatch('notify', message: 'Request sent. You\'re told when it\'s issued.');
    }

    // ---- My items ----

    public function acknowledge(int $id, AcknowledgeEquipment $acknowledge): void
    {
        $acknowledge->handle($this->currentUser(), $this->ownOrFail($id), Acknowledgements::locale($this->ackLocale));
        $this->tab = 'mine'; // stay here, though nothing may be waiting any more
        unset($this->mine);
        $this->dispatch('notify', message: 'Confirmed. Thank you.');
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
        $this->tab = 'mine';
        unset($this->mine);
        $this->dispatch('notify', message: 'Your explanation was sent.');
    }

    public function closeDialogs(): void
    {
        $this->reset(['explainingId', 'explanation']);
        $this->resetErrorBag();
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
     * The shop: items still given out, for this person.
     *
     * @return Collection<int, EquipmentItem>
     */
    #[Computed]
    public function items(): Collection
    {
        // Uniforms first (what guards shop for), then company gear.
        return EquipmentItem::query()->active()->for($this->currentUser())->orderByDesc('kind')->orderBy('name')->get();
    }

    /**
     * The cart with its items, leaving out anything no longer in the shop.
     *
     * @return list<array{index: int, item: EquipmentItem, size: string|null, quantity: int, price: float|null}>
     */
    #[Computed]
    public function cartLines(): array
    {
        $lines = [];

        foreach ($this->cart as $index => $line) {
            $item = $this->items->firstWhere('id', $line['item_id']);

            if ($item !== null) {
                $lines[] = ['index' => $index, 'item' => $item, 'size' => $line['size'], 'quantity' => $line['quantity'], 'price' => $item->priceFor($line['size'])];
            }
        }

        return $lines;
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
