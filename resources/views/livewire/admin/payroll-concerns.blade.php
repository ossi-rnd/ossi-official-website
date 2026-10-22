<div>
    <x-ui.card title="Payroll Concerns" description="What employees reported on their payslips (My Payslips → Report a problem). Answer each one: pay it on a payslip still being processed, explain why it's right, or mark it not valid. The employee is told either way.">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Filter">
                <option value="open">Open</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-slot:actions>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Payslip</th><th class="ui-th">Problem</th><th class="ui-th">Reported</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->concerns as $concern)
                        <tr wire:key="concern-{{ $concern->id }}">
                            <td class="ui-td">
                                <p><x-person-link :user="$concern->user" /></p>
                                <p class="text-xs text-gray-500">{{ $concern->user->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>
                            <td class="ui-td whitespace-nowrap">{{ $concern->run->periodLabel() }}</td>
                            <td class="ui-td">
                                <p class="text-gray-800 dark:text-gray-100">{{ $concern->category->label() }}</p>
                                <p class="max-w-xs truncate text-xs text-gray-500" title="{{ $concern->details }}">{{ $concern->details }}</p>
                            </td>
                            <td class="ui-td whitespace-nowrap"><x-filed-at :at="$concern->created_at" /></td>
                            <td class="ui-td"><x-ui.badge :tone="$concern->status->tone()">{{ $concern->status->label() }}</x-ui.badge></td>
                            <td class="ui-td text-right"><button type="button" wire:click="open({{ $concern->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">{{ $statusFilter === 'open' ? 'Nothing waiting: every concern is answered.' : 'No payroll concerns yet.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->concerns->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($opened = $this->opened)
        @php $isOpen = $opened->status === \App\Enums\PayrollConcernStatus::Open; @endphp
        <x-ui.modal :title="$opened->user->name.' · '.$opened->run->periodLabel()" close="close" max-width="2xl">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge :tone="$opened->status->tone()">{{ $opened->status->label() }}</x-ui.badge>
                <span class="text-xs text-gray-500 dark:text-gray-400">Reported {{ $opened->created_at->format('M j, Y g:i A') }}</span>
                <a href="{{ route('payroll', ['run' => $opened->payroll_run_id]) }}" wire:navigate class="ml-auto text-xs font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">Open this payroll run</a>
            </div>

            <div class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $opened->category->label() }}</p>
                <p class="mt-1 text-sm whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $opened->details }}</p>
                @if ($opened->attachment_path)
                    <a href="{{ route('payroll-concerns.attachment', $opened) }}" target="_blank" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                        <x-icon name="arrow-top-right-on-square" class="h-4 w-4" /> {{ $opened->attachment_name }}
                    </a>
                @endif
            </div>

            @if (! $isOpen)
                <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm dark:bg-white/[0.03]">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Answered by {{ $opened->resolver?->name ?? '—' }} · {{ $opened->resolved_at?->format('M j, Y') }}</p>
                    <p class="mt-1 text-gray-800 dark:text-gray-100">{{ $opened->response }}</p>
                    @if ($opened->adjustment)
                        @php $paid = (float) $opened->adjustment->amount; @endphp
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $paid >= 0 ? 'Added' : 'Deducted' }} ₱{{ number_format(abs($paid), 2) }} on the {{ $opened->adjustment->run->periodLabel() }} payslip.</p>
                    @endif
                </div>
            @else
                <form id="answer-form" wire:submit="answer" class="mt-5 space-y-4">
                    <fieldset>
                        <legend class="ui-label">Answer</legend>
                        <div class="mt-1 grid gap-2 sm:grid-cols-3">
                            @foreach ([\App\Actions\Payroll\AnswerPayrollConcern::PAY => ['Pay it', 'On a payslip still being processed'], \App\Actions\Payroll\AnswerPayrollConcern::EXPLAIN => ['Explain', 'It was right; nothing changes'], \App\Actions\Payroll\AnswerPayrollConcern::DECLINE => ['Not valid', 'With the reason']] as $value => [$label, $hint])
                                <label @class(['cursor-pointer rounded-lg border p-3 text-sm has-checked:border-blue-600 has-checked:ring-1 has-checked:ring-blue-600', 'border-gray-200 dark:border-gray-700'])>
                                    <input type="radio" wire:model.live="outcome" value="{{ $value }}" class="sr-only">
                                    <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $label }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($outcome === \App\Actions\Payroll\AnswerPayrollConcern::PAY)
                        @if ($this->payableRuns->isEmpty())
                            <x-ui.alert tone="amber">No payslip of {{ $opened->user->name }} is open right now (a run in processing, with their attendance, not yet generated). Answer it once the next run starts processing, or explain it now.</x-ui.alert>
                        @else
                            <div class="grid gap-3 sm:grid-cols-[1fr_auto_10rem] sm:items-start">
                                <x-ui.select label="On the payslip for" wire:model="payRunId">
                                    @foreach ($this->payableRuns as $run)
                                        <option value="{{ $run->id }}">{{ $run->periodLabel() }}</option>
                                    @endforeach
                                </x-ui.select>
                                <fieldset>
                                    <legend class="ui-label">Effect</legend>
                                    <div class="mt-1 grid grid-cols-2 gap-1 rounded-md border border-gray-300 p-1 dark:border-gray-700">
                                        @foreach (['add' => 'Add', 'deduct' => 'Deduct'] as $value => $label)
                                            <label class="flex min-h-8 cursor-pointer items-center justify-center rounded px-3 text-xs font-medium has-checked:bg-gray-800 has-checked:text-white dark:has-checked:bg-gray-100 dark:has-checked:text-gray-800">
                                                <input type="radio" wire:model="direction" value="{{ $value }}" class="sr-only">{{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <x-ui.input label="Amount (₱)" type="number" step="0.01" min="0.01" wire:model="amount" />
                            </div>
                            <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">Added as a Correction (taxable, like the pay it replaces), with your reply as the reason on the payslip.</p>
                        @endif
                    @endif

                    <x-ui.textarea label="Reply to the employee" wire:model="response" rows="3" maxlength="1000"
                        :placeholder="match ($outcome) { 'pay' => 'e.g. Confirmed with your supervisor: Oct 10 added.', 'explain' => 'e.g. Oct 10 was your day off on the roster, so it isn\'t paid.', default => 'e.g. No attendance or roster record for Oct 10.' }" />
                </form>
            @endif

            @if ($isOpen)
                <x-slot:footer>
                    <x-ui.button type="button" variant="ghost" wire:click="close">Cancel</x-ui.button>
                    <x-ui.button form="answer-form" wire:target="answer" :disabled="$outcome === 'pay' && $this->payableRuns->isEmpty()">Send answer</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</div>
