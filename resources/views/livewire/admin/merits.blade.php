@php
    $signed = fn (int $points): string => $points > 0 ? '+'.$points : '−'.abs($points);
    $canManageTypes = auth()->user()?->can(\App\Enums\Ability::ManageMeritTypes->value) ?? false;
@endphp

<div class="space-y-6">
    <x-ui.card title="Merits & Demerits" description="Recognition and minor offences for the people you supervise, each with a reason. The employee is told, and can add their side to a demerit within {{ \App\Models\MeritRecord::REPLY_DAYS }} days. Totals count the last 12 months. Nothing here touches pay.">
        <x-slot:actions>
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search a name" aria-label="Search a name" class="w-full sm:w-56" />
            <x-ui.select wire:model.live="kindFilter" aria-label="Show">
                <option value="all">All</option>
                <option value="merit">Merits</option>
                <option value="demerit">Demerits</option>
            </x-ui.select>
            <x-ui.button type="button" wire:click="startGiving">Give a merit or demerit</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="kindFilter,search">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">For</th><th class="ui-th">Date</th><th class="ui-th text-right">Points</th><th class="ui-th">Given by</th></tr></thead>
                <tbody>
                    @forelse ($this->records as $record)
                        <tr wire:key="merit-{{ $record->id }}" @class(['opacity-60' => $record->voided_at])>
                            <td class="ui-td">
                                <p><x-person-link :user="$record->user" /></p>
                                <p class="text-xs text-gray-500">{{ $record->user->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>
                            <td class="ui-td">
                                <p @class(['text-gray-800 dark:text-gray-100', 'line-through' => $record->voided_at])>{{ $record->title }}</p>
                                <p class="max-w-sm truncate text-xs text-gray-500" title="{{ $record->reason }}">{{ $record->reason }}</p>
                                @if ($record->voided_at)
                                    <p class="text-xs text-gray-500">Taken back: {{ $record->void_reason }}</p>
                                @elseif ($record->employee_reply)
                                    <p class="text-xs text-gray-500">Replied: “{{ \Illuminate\Support\Str::limit($record->employee_reply, 80) }}”</p>
                                @elseif ($record->awaitsReply())
                                    <p class="flex items-center gap-1.5 text-xs text-gray-500"><span class="size-1.5 rounded-full bg-amber-500"></span>Can reply until {{ $record->reply_until?->format('M j') }}</p>
                                @endif
                            </td>
                            <td class="ui-td whitespace-nowrap">{{ $record->occurred_on->format('M j, Y') }}</td>
                            <td class="ui-td text-right font-semibold whitespace-nowrap text-gray-800 tabular-nums dark:text-gray-100">{{ $signed($record->points) }}</td>
                            <td class="ui-td whitespace-nowrap text-gray-500">{{ $record->giver?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No merits or demerits here yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>{{ $this->records->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($canManageTypes)
        <x-ui.card title="Types and points" description="The list everyone picks from, so the same act gets the same points. Switching one off keeps it on past records.">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="startType">Add a type</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Type</th><th class="ui-th">Kind</th><th class="ui-th text-right">Points</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($this->types as $type)
                            <tr wire:key="type-{{ $type->id }}" @class(['opacity-60' => ! $type->is_active])>
                                <td class="ui-td">
                                    <p class="text-gray-800 dark:text-gray-100">{{ $type->name }} @unless ($type->is_active)<span class="text-xs text-gray-500">(off)</span>@endunless</p>
                                    @if ($type->description)<p class="text-xs text-gray-500">{{ $type->description }}</p>@endif
                                </td>
                                <td class="ui-td">{{ ucfirst($type->kind) }}</td>
                                <td class="ui-td text-right font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $signed($type->signedPoints()) }}</td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    <button type="button" wire:click="startType({{ $type->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                    <button type="button" wire:click="toggleType({{ $type->id }})" class="ml-3 font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">{{ $type->is_active ? 'Switch off' : 'Use again' }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- Giving one. --}}
    @if ($giving)
        @php $type = ctype_digit($typeId) ? $this->types->firstWhere('id', (int) $typeId) : null; @endphp
        <x-ui.modal title="Give a merit or demerit" close="closeGiving" max-width="xl">
            <form id="give-form" wire:submit="give" class="space-y-4">
                <div>
                    <p class="ui-label">Employee</p>
                    @if ($person = $this->chosen)
                        <div class="mt-1 flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $person->name }}</span>
                                <span class="block text-xs text-gray-500">{{ $person->employeeProfile?->assignmentLabel() ?? '—' }}</span>
                            </span>
                            <button type="button" wire:click="$set('giveTo', null)" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Change</button>
                        </div>
                    @else
                        <x-ui.input type="search" wire:model.live.debounce.300ms="personSearch" placeholder="Search by name or employee no." aria-label="Search the employee by name or employee number" class="mt-1" />
                        @if ($this->candidates->isNotEmpty())
                            @if (mb_strlen(trim($personSearch)) < 2)<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Suggested, or search above:</p>@endif
                            <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                                @foreach ($this->candidates as $candidate)
                                    <li wire:key="candidate-{{ $candidate->id }}">
                                        <button type="button" wire:click="choose({{ $candidate->id }})" class="w-full px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                            <span class="block text-sm text-gray-800 dark:text-gray-100">{{ $candidate->name }}</span>
                                            <span class="block text-xs text-gray-500">{{ $candidate->employeeProfile?->assignmentLabel() ?? '—' }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($personSearch)) >= 2)
                            <p class="mt-1 text-xs text-gray-500">No one you supervise by that name or number.</p>
                        @endif
                        @error('giveTo') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @endif
                </div>

                <x-ui.select label="For" wire:model.live="typeId">
                    <option value="">Choose…</option>
                    @foreach ($this->types->where('is_active', true)->sortBy('kind') as $option)
                        <option value="{{ $option->id }}">{{ $option->kind === 'merit' ? 'Merit' : 'Demerit' }} · {{ $option->name }} ({{ $signed($option->signedPoints()) }})</option>
                    @endforeach
                    <option value="other">Other…</option>
                </x-ui.select>
                @if ($type?->description)<p class="-mt-2 text-xs text-gray-500">{{ $type->description }}</p>@endif

                @if ($typeId === 'other')
                    <div class="grid gap-3 sm:grid-cols-[1fr_auto_6rem] sm:items-start">
                        <x-ui.input label="Name it" wire:model="otherTitle" placeholder="e.g. Helped a lost visitor" />
                        <fieldset>
                            <legend class="ui-label">Kind</legend>
                            <div class="mt-1 grid grid-cols-2 gap-1 rounded-md border border-gray-300 p-1 dark:border-gray-700">
                                @foreach (['merit' => 'Merit', 'demerit' => 'Demerit'] as $value => $label)
                                    <label class="flex min-h-8 cursor-pointer items-center justify-center rounded px-3 text-xs font-medium has-checked:bg-gray-800 has-checked:text-white dark:has-checked:bg-gray-100 dark:has-checked:text-gray-800">
                                        <input type="radio" wire:model="otherKind" value="{{ $value }}" class="sr-only">{{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <x-ui.input label="Points" type="number" min="1" max="20" wire:model="otherPoints" />
                    </div>
                @endif

                <x-ui.input label="Date it happened" type="date" wire:model="occurredOn" :max="today()->toDateString()" />
                <x-ui.textarea label="What happened" wire:model="reason" rows="3" maxlength="1000" placeholder="e.g. The building admin wrote to thank him for stopping a shoplifter at Gate 2." />
                @if ($type?->kind === 'demerit' || ($typeId === 'other' && $otherKind === 'demerit'))
                    <p class="-mt-2 text-xs text-gray-500">They're told, and can add their side within {{ \App\Models\MeritRecord::REPLY_DAYS }} days. For a serious offence, use a Notice to Explain instead.</p>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="closeGiving">Cancel</x-ui.button>
                <x-ui.button form="give-form" wire:target="give">Give</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- HR: a type. --}}
    @if ($addingType || $editingTypeId)
        <x-ui.modal :title="$addingType ? 'Add a type' : 'Edit the type'" close="cancelType" max-width="lg">
            <form id="type-form" wire:submit="saveType" class="space-y-4">
                <x-ui.input label="Name" wire:model="typeName" placeholder="e.g. Late logbook entry" />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select label="Kind" wire:model="typeKind">
                        <option value="merit">Merit</option>
                        <option value="demerit">Demerit</option>
                    </x-ui.select>
                    <x-ui.input label="Points (1–20)" type="number" min="1" max="20" wire:model="typePoints" />
                </div>
                <x-ui.input label="Description (optional)" wire:model="typeDescription" />
                @unless ($addingType)<p class="text-xs text-gray-500">Records already given keep their title and points.</p>@endunless
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="cancelType">Cancel</x-ui.button>
                <x-ui.button form="type-form" wire:target="saveType">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
