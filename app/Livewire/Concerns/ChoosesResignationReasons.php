<?php

namespace App\Livewire\Concerns;

use App\Enums\ResignationReason;
use Illuminate\Validation\Rule;

/**
 * The resignation reasons checklist, shared by the employee's form and HR's
 * paper letter (partial livewire.partials.resignation-reasons). The Art. 300(b)
 * just causes stay folded behind one question ($claimsJustCause), so nobody
 * ticks a serious claim against the company by accident; unticking it clears them.
 */
trait ChoosesResignationReasons
{
    /** @var list<string> every reason ticked; nothing to start */
    public array $reasons = [];

    public string $reasonOther = '';

    public bool $claimsJustCause = false;

    public function updatedClaimsJustCause(bool $value): void
    {
        if (! $value) {
            $this->reasons = array_values(array_filter($this->reasons, fn (string $reason) => ! (ResignationReason::tryFrom($reason)?->skipsNotice() ?? false)));
        }
    }

    /** A just cause (Art. 300(b)) is ticked: no notice is needed. */
    public function justCauseTicked(): bool
    {
        return collect($this->reasons)->contains(fn (string $value) => ResignationReason::tryFrom($value)?->skipsNotice() ?? false);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function reasonRules(): array
    {
        return [
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['string', Rule::in(array_map(fn (ResignationReason $r) => $r->value, ResignationReason::offered()))],
            'reasonOther' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<mixed>  $values  the validated reasons
     * @return list<ResignationReason>
     */
    protected function chosenReasons(array $values): array
    {
        return array_values(array_map(fn (string $value) => ResignationReason::from($value), array_filter($values, 'is_string')));
    }
}
