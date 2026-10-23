<div class="space-y-6">
    @if ($this->selectedRun === null)
        {{-- ================= Runs overview ================= --}}
        @php $processing = $this->runs->firstWhere('status', \App\Enums\PayrollRunStatus::Processing); @endphp
        @if ($processing)
            <x-ui.alert tone="blue">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <span><strong>{{ $processing->periodLabel() }}</strong> is processing. Pay date {{ $processing->pay_date->format('M j, Y') }}.</span>
                    <button type="button" wire:click="viewRun({{ $processing->id }})" class="inline-flex items-center gap-1 font-medium">Review <x-icon name="chevron-right" class="h-4 w-4" /></button>
                </div>
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Latest net payroll" :value="'₱'.number_format($this->stats['latest_net'], 2)" hint="Generated payslips, latest run" />
            <x-ui.stat label="Processing run (so far)" :value="'₱'.number_format($this->stats['processing_net'], 2)" hint="Net of payslips generated so far" />
            <x-ui.stat label="Latest deductions" :value="'₱'.number_format($this->stats['latest_deductions'], 2)" hint="Government, late and loans" />
            <x-ui.stat label="Paid last run" :value="$this->stats['paid_count']" hint="Payslips in the latest paid run" />
        </div>

        @if ($this->stats['trend'] !== [])
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="ui-card p-4 lg:col-span-2">
                    <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Payroll cost trend</p>
                    <div class="h-64"><canvas role="img" aria-label="Gross and net payroll per run" wire:ignore x-data="payrollCostChart(@js($this->stats['trend']))"></canvas></div>
                </div>
                <div class="ui-card p-4">
                    <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Deductions breakdown</p>
                    <div class="h-56"><canvas role="img" aria-label="Deductions by type for the latest run" wire:ignore x-data="deductionsChart(@js($this->stats['breakdown']))"></canvas></div>
                </div>
            </div>
        @endif

        <x-ui.card title="Payroll runs" description="Semi-monthly cut-offs: draft → processing → approved → paid.">
            <x-slot:actions>
                <x-ui.button type="button" variant="dark" size="sm" wire:click="openCreateRun"><x-icon name="document-plus" class="h-4 w-4" /> New payroll run</x-ui.button>
            </x-slot:actions>

            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Cut-off period</th><th class="ui-th">Pay date</th><th class="ui-th">Payslips</th><th class="ui-th text-right">Total gross</th><th class="ui-th text-right">Total net</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->runs as $run)
                            <tr wire:key="run-{{ $run->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $run->periodLabel() }}</td>
                                <td class="ui-td">{{ $run->pay_date->format('M j, Y') }}</td>
                                <td class="ui-td">{{ $run->payslip_records_count }}</td>
                                <td class="ui-td text-right"><x-money :amount="$run->total_gross ?? 0" /></td>
                                <td class="ui-td text-right"><x-money :amount="$run->total_net ?? 0" /></td>
                                <td class="ui-td"><x-ui.status-badge :status="$run->status" /></td>
                                <td class="ui-td text-right"><button type="button" wire:click="viewRun({{ $run->id }})" class="inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View <x-icon name="chevron-right" class="h-3.5 w-3.5" /></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-td py-8 text-center">No payroll runs yet. Create the first cut-off.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        @if ($showCreateRun)
            <x-ui.modal title="New payroll run" close="$set('showCreateRun', false)">
                <form id="create-run" wire:submit="createRun" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.input label="Period start" type="date" wire:model="startDate" />
                        <x-ui.input label="Period end" type="date" wire:model="endDate" />
                    </div>
                    <x-ui.input label="Pay date" type="date" wire:model="payDate" />
                    <p class="text-xs text-gray-500">Rates are taken from the schedule in force on the period START date, so this run's figures never change when rates are updated later.</p>
                </form>
                <x-slot:footer>
                    <x-ui.button form="create-run">Create draft</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @else
        {{-- ================= One run ================= --}}
        @php
            $run = $this->selectedRun;
            $status = $run->status;
        @endphp

        <button type="button" wire:click="backToRuns" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-100">
            <x-icon name="chevron-left" class="h-4 w-4" /> Back to payroll runs
        </button>

        @error('run') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
        @error('payslip') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
        @if ($none = $this->noRateWarning)
            <x-ui.alert tone="amber">
                {{ $none['count'] }} {{ str('employee')->plural($none['count']) }} {{ $none['count'] === 1 ? 'has' : 'have' }} no daily rate yet, so {{ $none['count'] === 1 ? 'their payslip' : 'their payslips' }} can't be generated:
                {{ implode(', ', $none['names']) }}{{ $none['count'] > count($none['names']) ? ' and '.($none['count'] - count($none['names'])).' more' : '' }}.
                Set their position's rate or a personal rate on <a href="{{ route('daily-rates', ['show' => 'missing']) }}" wire:navigate class="font-medium underline">Daily Rates</a>.
            </x-ui.alert>
        @endif
        @if ($this->pendingAdjustments > 0)
            <x-ui.alert tone="amber">
                {{ $this->pendingAdjustments }} {{ str('employee')->plural($this->pendingAdjustments) }} {{ $this->pendingAdjustments === 1 ? 'has' : 'have' }} an adjustment awaiting a Super Admin's approval. Their payslips can't be generated until it is approved or rejected.
            </x-ui.alert>
        @endif

        {{-- Pay that changed on earlier cut-offs, decided before these payslips are frozen. --}}
        @if ($status === \App\Enums\PayrollRunStatus::Processing)
            <livewire:payroll.prior-period-corrections :run-id="$run->id" :key="'corrections-'.$run->id" />
            {{-- Leave the law pays in this cut-off, decided before these payslips are frozen. --}}
            <livewire:payroll.paid-leave-suggestions :run-id="$run->id" :key="'paid-leave-'.$run->id" />
        @endif

        <x-ui.card :title="$run->periodLabel()" :description="'Pay date '.$run->pay_date->format('M j, Y').' · '.$this->missingPayslips.' employee(s) who worked still without a payslip'">
            <x-slot:actions>
                <x-ui.status-badge :status="$status" />
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="exportCsv"><x-icon name="download" class="h-4 w-4" /> CSV</x-ui.button>

                @if ($status === \App\Enums\PayrollRunStatus::Draft)
                    <x-ui.button type="button" size="sm" wire:click="advanceRun" wire:confirm="Start processing? Employees will see their estimated payslips.">Start processing</x-ui.button>
                @elseif ($status === \App\Enums\PayrollRunStatus::Processing)
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="generateAll" wire:loading.attr="disabled" wire:target="generateAll" wire:confirm="Generate and freeze payslips for everyone who worked this period?">
                        Generate all
                    </x-ui.button>
                    <x-ui.button type="button" variant="primary" size="sm" wire:click="advanceRun" wire:confirm="Approve this run? Generated payslips become final." tooltip-align="end"
                        :disabled-reason="$this->approvalBlockedReason">Approve run</x-ui.button>
                @elseif ($status === \App\Enums\PayrollRunStatus::Approved)
                    <x-ui.button type="button" variant="primary" size="sm" wire:click="advanceRun" wire:confirm="Mark every payslip in this run as paid?">Mark as paid</x-ui.button>
                @endif
            </x-slot:actions>

            {{-- Two views of the same page of employees: their payslips, or their contributions and tax. --}}
            <div class="flex gap-1 border-b border-gray-200 px-4 dark:border-gray-800" role="tablist">
                @foreach (['payslips' => 'Payslips', 'contributions' => 'Contributions & tax'] as $tab => $label)
                    <button type="button" role="tab" wire:click="$set('runView', '{{ $tab }}')" aria-selected="{{ $runView === $tab ? 'true' : 'false' }}"
                        @class(['-mb-px border-b-2 px-3 py-2.5 text-sm font-medium', 'border-blue-600 text-blue-600 dark:text-blue-400' => $runView === $tab, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $runView !== $tab])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($runView === 'contributions')
                @include('livewire.admin.partials.contributions-table')
            @else
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Name</th><th class="ui-th">Employee No.</th><th class="ui-th text-right">Daily rate</th><th class="ui-th">Days</th><th class="ui-th text-right">Gross</th><th class="ui-th text-right">Deductions</th><th class="ui-th text-right">Net</th><th class="ui-th">Payslip</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($this->employees as $employee)
                            @php $payslip = $this->pagePayslips[$employee->id]; @endphp
                            <tr wire:key="emp-{{ $employee->id }}">
                                <td class="ui-td">
                                    <span class="flex items-center gap-2">
                                        <x-ui.avatar :user="$employee" size="sm" />
                                        <span>
                                            <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $employee->name }}</span>
                                            <span class="block text-xs text-gray-500">{{ $employee->employeeProfile?->is_office_staff ? 'Office staff' : 'Field staff' }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="ui-td font-mono text-xs">{{ $employee->employeeProfile?->employee_number }}</td>
                                {{-- HR sets the rate on the employee record; Accounting sees it here. --}}
                                <td class="ui-td text-right whitespace-nowrap tabular-nums">@if ($payslip->dailyRate > 0) ₱{{ number_format($payslip->dailyRate, 2) }} @else <span class="text-amber-600 dark:text-amber-400">Not set</span> @endif</td>
                                <td class="ui-td">{{ $payslip->daysWorked }}</td>
                                @if ($payslip->daysWorked === 0 && ! $payslip->isGenerated)
                                    {{-- Nobody gets a payslip for a period they didn't work, so no negative "estimate". --}}
                                    <td class="ui-td text-right">—</td><td class="ui-td text-right">—</td><td class="ui-td text-right">—</td>
                                @else
                                    <td class="ui-td text-right"><x-money :amount="$payslip->grossPay()" /></td>
                                    <td class="ui-td text-right"><x-money :amount="$payslip->totalDeductions()" /></td>
                                    <td class="ui-td text-right font-medium text-gray-800 dark:text-gray-100"><x-money :amount="$payslip->netPay()" /></td>
                                @endif
                                <td class="ui-td">
                                    @if ($payslip->isGenerated)
                                        <x-ui.badge :tone="$status === \App\Enums\PayrollRunStatus::Paid ? 'green' : 'indigo'">{{ $status === \App\Enums\PayrollRunStatus::Paid ? 'Paid' : 'Generated' }}</x-ui.badge>
                                    @elseif ($payslip->daysWorked === 0)
                                        <span class="text-xs text-gray-400">No attendance</span>
                                    @else
                                        <x-ui.badge tone="blue">Estimate</x-ui.badge>
                                    @endif
                                </td>
                                <td class="ui-td text-right">
                                    <button type="button" wire:click="viewPayslip({{ $employee->id }})" aria-label="View {{ $employee->name }}'s payslip" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 hover:text-blue-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-blue-400"><x-icon name="eye" class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            <x-slot:footer>{{ $this->employees->links() }}</x-slot:footer>
        </x-ui.card>

        @if ($this->selectedEmployee && $this->selectedPayslip)
            @php $payslip = $this->selectedPayslip; @endphp
            <x-ui.modal :title="$this->selectedEmployee->name.' · '.$run->periodLabel()" close="closePayslip" max-width="xl">
                @error('payslip') <x-ui.alert tone="red" class="mb-4">{{ $message }}</x-ui.alert> @enderror

                <x-payslip-breakdown :payslip="$payslip" :attendance="$this->selectedAttendance" :late-days="$this->selectedLateDays" />

                @if ($this->selectedRecord)
                    <p class="mt-2 text-center text-xs text-gray-400">
                        Generated {{ $this->selectedRecord->generated_at->format('M j, Y g:i A') }}
                        @if ($this->selectedRecord->paid_at) · Paid {{ $this->selectedRecord->paid_at->format('M j, Y') }} @endif
                    </p>
                @endif

                {{-- Adjustments belong to THIS payslip: one run, one employee. --}}
                <livewire:payroll.payslip-adjustments :run-id="$this->selectedRun->id" :user-id="$this->selectedEmployee->id" :key="'adjustments-'.$this->selectedRun->id.'-'.$this->selectedEmployee->id" />

                {{-- Loans & deductions belong to the EMPLOYEE, so they carry over to future runs.
                     Managed only from a payslip that can still change; read-only on a frozen one. --}}
                @php $loansLocked = $this->loansLockedReason; @endphp
                <div class="mt-6 border-t border-gray-100 pt-4 dark:border-gray-800" x-data="{ adding: false }">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="ui-section-label">Loans &amp; company deductions</p>
                        @if ($loansLocked)
                            <x-ui.tooltip :text="$loansLocked" align="end">
                                <span class="inline-flex cursor-not-allowed items-center gap-1 text-sm font-medium text-gray-300 dark:text-gray-600"><x-icon name="lock" class="h-4 w-4" /> Add</span>
                            </x-ui.tooltip>
                        @else
                            <button type="button" x-show="!adding" x-on:click="adding = true" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="plus" class="h-4 w-4" /> Add</button>
                        @endif
                    </div>
                    @error('deductionLabel')
                        @if ($loansLocked)<x-ui.alert tone="amber" class="mb-2 text-xs">{{ $message }}</x-ui.alert>@endif
                    @enderror

                    <div class="space-y-2">
                        @forelse ($this->deductions as $deduction)
                            <div wire:key="deduction-{{ $deduction->id }}" @class(['flex items-center justify-between gap-2 rounded-md border border-gray-200 p-3 dark:border-gray-800', 'opacity-60' => ! $deduction->is_active])>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                        {{ $deduction->label }} <x-ui.badge>{{ $deduction->type->label() }}</x-ui.badge>
                                        @if (! $deduction->is_active) <x-ui.badge tone="amber">{{ $deduction->remaining_balance !== null && (float) $deduction->remaining_balance <= 0 ? 'Paid off' : 'Paused' }}</x-ui.badge> @endif
                                        {{-- Art. 113: not taken until they authorize it on My Payslips. --}}
                                        @if ($deduction->declined_at) <x-ui.badge tone="red">They don't agree</x-ui.badge>
                                        @elseif ($deduction->awaiting_authorization) <x-ui.badge tone="amber">Waiting for their authorization</x-ui.badge> @endif
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <x-money :amount="$deduction->installment_amount" /> per payslip
                                        @if ($deduction->remaining_balance !== null) · balance <x-money :amount="$deduction->remaining_balance" /> of <x-money :amount="$deduction->original_amount" /> @endif
                                    </p>
                                    @if ($deduction->type->needsAuthorization() && ! $deduction->awaiting_authorization)
                                        <p class="text-xs text-gray-500" @if ($deduction->authorization) title="{{ $deduction->authorization }}" @endif>
                                            {{ $deduction->authorization_basis ? $deduction->authorization_basis->label().($deduction->authorized_at ? ', '.$deduction->authorized_at->format('M j, Y') : '') : 'Added before in-app authorization: keep their signed paper' }}
                                        </p>
                                    @endif
                                </div>
                                <div @class(['flex shrink-0 gap-1', 'hidden' => $loansLocked])>
                                    <button type="button" wire:click="toggleDeduction({{ $deduction->id }})" aria-label="{{ $deduction->is_active ? 'Pause' : 'Resume' }} {{ $deduction->label }}" class="rounded p-1 text-gray-400 hover:text-amber-600 dark:hover:text-amber-400">
                                        <x-icon :name="$deduction->is_active ? 'pause' : 'play'" class="h-4 w-4" />
                                    </button>
                                    <button type="button" wire:click="deleteDeduction({{ $deduction->id }})" wire:confirm="Delete this deduction? Generated payslips keep their own copy." aria-label="Delete {{ $deduction->label }}" class="rounded p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">No loans or company deductions.</p>
                        @endforelse
                    </div>

                    @unless ($loansLocked)
                    <form x-show="adding" x-cloak wire:submit="addDeduction" x-on:submit="adding = false" class="mt-3 space-y-3 rounded-md border border-dashed border-gray-300 p-3 dark:border-gray-700">
                        <x-ui.input label="Description" wire:model="deductionLabel" placeholder="e.g. SSS salary loan" />
                        <x-ui.select label="Type" wire:model.live="deductionType">
                            @foreach ($deductionTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </x-ui.select>
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.input label="Per payslip (₱)" type="number" step="0.01" min="0.01" wire:model="deductionInstallment" />
                            @if (\App\Enums\DeductionType::tryFrom($deductionType)?->tracksBalance())
                                <x-ui.input label="Total amount (₱)" type="number" step="0.01" min="0.01" wire:model="deductionTotal" />
                            @endif
                        </div>
                        {{-- Labor Code Art. 113: the employee's written authorization, or the law (government loans). --}}
                        @if (\App\Enums\DeductionType::tryFrom($deductionType)?->needsAuthorization())
                            <div>
                                <x-ui.select label="Authorization" wire:model.live="deductionBasis">
                                    @foreach (\App\Enums\DeductionAuthorizationBasis::cases() as $basis)
                                        <option value="{{ $basis->value }}">{{ $basis->choice() }}</option>
                                    @endforeach
                                </x-ui.select>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ match ($deductionBasis) { 'paper' => 'Keep the signed paper on file. It applies from the next payslip.', 'law' => 'SSS and Pag-IBIG salary loans only. It applies from the next payslip.', default => 'They\'re asked on My Payslips (bell and email). Nothing is deducted until they authorize it.' } }}</p>
                            </div>
                        @endif
                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost" size="sm" x-on:click="adding = false">Cancel</x-ui.button>
                            <x-ui.button size="sm" variant="secondary">Add deduction</x-ui.button>
                        </div>
                    </form>
                    @endunless
                    <p class="mt-2 text-xs text-gray-400">
                        {{ $loansLocked ? 'Read-only here: this payslip is final. Loans and deductions apply to future payslips.' : 'New deductions apply to payslips generated from now on, once authorized.' }}
                    </p>
                </div>

                <x-slot:footer>
                    <x-ui.button variant="ghost" type="button" wire:click="closePayslip">Close</x-ui.button>
                    @if ($status->canGeneratePayslips() && ! $payslip->isGenerated && $payslip->daysWorked > 0)
                        <x-ui.button type="button" wire:click="generate({{ $this->selectedEmployee->id }})" wire:confirm="Generate and freeze this payslip?" wire:loading.attr="disabled">
                            <x-icon name="check" class="h-4 w-4" /> Generate payslip
                        </x-ui.button>
                    @endif
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif
</div>
