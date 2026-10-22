@php
    $me = auth()->user();
    $canApprove = $me?->can(\App\Enums\Ability::ApprovePayrollAdjustments->value) ?? false;
    $category = \App\Enums\AdjustmentCategory::tryFrom($adjustmentCategory);
@endphp

{{-- Corrections are their own lines with a reason, never an edit of the calculated figures. --}}
<div class="mt-6 border-t border-gray-100 pt-4 dark:border-gray-800" x-data="{ adding: false }" x-on:adjustments-changed.window="adding = false">
    <div class="mb-2 flex items-center justify-between">
        <p class="ui-section-label">Adjustments</p>
        @if ($this->canAdd)
            <button type="button" x-show="!adding" x-on:click="adding = true" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="plus" class="h-4 w-4" /> Add adjustment</button>
        @endif
    </div>

    <div class="space-y-2">
        @forelse ($this->adjustments as $adjustment)
            <div wire:key="adjustment-{{ $adjustment->id }}" @class(['rounded-md border border-gray-200 p-3 dark:border-gray-800', 'opacity-60' => $adjustment->status === \App\Enums\AdjustmentStatus::Rejected])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                            <span @class(['text-emerald-600 dark:text-emerald-400' => ! $adjustment->isDeduction(), 'text-red-600 dark:text-red-400' => $adjustment->isDeduction()])>{{ $adjustment->isDeduction() ? '−' : '+' }}<x-money :amount="abs((float) $adjustment->amount)" /></span>
                            <x-ui.badge class="ml-1">{{ $adjustment->category->label() }}</x-ui.badge>
                            <x-ui.badge :tone="$adjustment->status->tone()">{{ $adjustment->status->label() }}</x-ui.badge>
                        </p>
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $adjustment->reason }}</p>
                        <p class="mt-0.5 text-xs text-gray-400">
                            Added by {{ $adjustment->creator->name }} · {{ $adjustment->created_at->format('M j, g:i A') }}
                            @if ($adjustment->reviewer && $adjustment->reviewed_by !== $adjustment->created_by) · {{ $adjustment->status === \App\Enums\AdjustmentStatus::Rejected ? 'Rejected' : 'Approved' }} by {{ $adjustment->reviewer->name }} @endif
                        </p>
                        @if ($adjustment->review_note)<p class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ $adjustment->review_note }}</p>@endif
                    </div>
                    @if (! $this->isGenerated)
                        <button type="button" wire:click="remove({{ $adjustment->id }})" wire:confirm="Remove this adjustment? It stays in the audit log." aria-label="Remove adjustment" class="shrink-0 rounded p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400"><x-icon name="trash" class="h-4 w-4" /></button>
                    @endif
                </div>

                @if ($adjustment->status === \App\Enums\AdjustmentStatus::Pending)
                    <div class="mt-3 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                        @if ($canApprove && $adjustment->created_by !== $me?->id)
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="startReject({{ $adjustment->id }})" class="mr-auto">Reject</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="approve({{ $adjustment->id }})">Approve</x-ui.button>
                        @else
                            <x-ui.button type="button" size="sm" tooltip-align="end"
                                :disabled-reason="$adjustment->created_by === $me?->id ? 'You added this adjustment, so someone else must approve it. A Super Admin has been notified.' : 'Only a Super Admin can approve adjustments of ₱'.number_format((float) $threshold).' or more.'">Approve</x-ui.button>
                        @endif
                    </div>
                    @if ($rejectingId === $adjustment->id)
                        <form wire:submit="reject" class="mt-3 space-y-2">
                            <x-ui.textarea label="Why is it rejected?" wire:model="reviewNote" rows="2" maxlength="500" />
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('rejectingId', null)">Cancel</x-ui.button>
                                <x-ui.button size="sm" variant="danger">Reject adjustment</x-ui.button>
                            </div>
                        </form>
                    @endif
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-400">No adjustments.</p>
        @endforelse
    </div>

    @if ($this->canAdd)
        <form x-show="adding" x-cloak wire:submit="add" class="mt-3 space-y-3 rounded-md border border-dashed border-gray-300 p-3 dark:border-gray-700">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.select label="Category" wire:model.live="adjustmentCategory">
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <fieldset>
                    <legend class="ui-label">Effect</legend>
                    <div class="mt-1 grid grid-cols-2 gap-1 rounded-md border border-gray-300 p-1 dark:border-gray-700">
                        @foreach (['add' => 'Add to pay', 'deduct' => 'Deduct'] as $value => $label)
                            @php $allowed = $value === 'add' ? ($category?->allowsAddition() ?? true) : ($category?->allowsDeduction() ?? true); @endphp
                            <label @class(['flex min-h-8 items-center justify-center rounded px-2 text-xs font-medium', 'cursor-pointer has-checked:bg-gray-900 has-checked:text-white dark:has-checked:bg-white dark:has-checked:text-gray-800' => $allowed, 'cursor-not-allowed text-gray-300 dark:text-gray-600' => ! $allowed])>
                                <input type="radio" wire:model="adjustmentDirection" value="{{ $value }}" class="sr-only" @disabled(! $allowed)>{{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            @if ($category)<p class="-mt-1 text-xs text-gray-500">{{ $category->description() }}</p>@endif
            <x-ui.input label="Amount (₱)" type="number" step="0.01" min="0.01" wire:model="adjustmentAmount" :hint="$threshold !== null ? '₱'.number_format($threshold).' or more needs a Super Admin\'s approval.' : 'Applies to this payslip right away, and is recorded in the audit log.'" />
            <x-ui.textarea label="Reason (shown on the payslip)" wire:model="adjustmentReason" rows="2" maxlength="500" placeholder="e.g. Nov 3 clock-in missed, confirmed by supervisor" />
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="adding = false">Cancel</x-ui.button>
                {{-- Secondary: the modal's one blue action is "Generate payslip" in the footer. --}}
                <x-ui.button size="sm" variant="secondary">Add adjustment</x-ui.button>
            </div>
        </form>
    @elseif ($this->isGenerated)
        <p class="mt-2 text-xs text-gray-400">This payslip is generated and frozen. A later correction goes on the next payroll.</p>
    @endif
</div>
