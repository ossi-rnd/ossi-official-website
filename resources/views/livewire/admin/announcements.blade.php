<div class="space-y-6">
    <x-ui.card title="Announcements" description="Live announcements show as a banner to their audience and appear in the bell. Urgent ones are also emailed.">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="megaphone" class="h-4 w-4" /> New announcement</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Title</th><th class="ui-th">Audience</th><th class="ui-th">Runs</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->announcements as $announcement)
                        @php
                            $now = now();
                            [$state, $tone] = match (true) {
                                $announcement->starts_at->greaterThan($now) => ['Scheduled', 'amber'],
                                $announcement->ends_at !== null && $announcement->ends_at->lessThanOrEqualTo($now) => ['Ended', 'gray'],
                                default => ['Live', 'blue'],
                            };
                        @endphp
                        <tr wire:key="announcement-{{ $announcement->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">
                                    {{ $announcement->title }}
                                    @if ($announcement->is_urgent)<x-ui.badge tone="red" class="ml-1">Urgent</x-ui.badge>@endif
                                </p>
                                <p class="max-w-md truncate text-xs text-gray-500">{{ $announcement->body }}</p>
                            </td>
                            <td class="ui-td">{{ $announcement->audienceRoles() === [] ? 'Everyone' : collect($announcement->audienceRoles())->map->label()->join(', ') }}</td>
                            <td class="ui-td whitespace-nowrap">
                                {{ $announcement->starts_at->format('M j, Y g:i A') }}
                                <span class="block text-xs text-gray-400">{{ $announcement->ends_at ? 'until '.$announcement->ends_at->format('M j, Y g:i A') : 'no end date' }}</span>
                            </td>
                            <td class="ui-td"><x-ui.badge :tone="$tone">{{ $state }}</x-ui.badge></td>
                            <td class="ui-td text-right whitespace-nowrap">
                                <button type="button" wire:click="edit({{ $announcement->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $announcement->id }})" wire:confirm="Delete this announcement?">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No announcements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit announcement' : 'New announcement'" close="cancel" max-width="lg">
            {{--
                Alpine handles the conveniences (Everyone/roles switch, date shortcuts,
                the counter, the button's wording); the values are still Livewire's.
            --}}
            <form id="announcement-form" wire:submit="save" class="space-y-6"
                x-data="{
                    pick: @js($audience !== []),
                    length: @js(mb_strlen($body)),
                    starts: $wire.entangle('startsAt'),
                    ends: $wire.entangle('endsAt'),
                    local(date) {
                        const pad = (n) => String(n).padStart(2, '0');
                        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
                    },
                    startNow() { this.starts = this.local(new Date()); },
                    endAfter(days) {
                        const from = this.starts ? new Date(this.starts) : new Date();
                        from.setDate(from.getDate() + days);
                        this.ends = this.local(from);
                    },
                    get scheduled() { return !! this.starts && new Date(this.starts) > new Date(Date.now() + 60000); },
                }"
                x-effect="$dispatch('announcement-scheduled', scheduled)">

                {{-- What it says --}}
                <div class="space-y-4">
                    <x-ui.input label="Title" wire:model="title" maxlength="150" placeholder="e.g. Office closed on Nov 1 (All Saints' Day)" />
                    <div x-on:input="length = $event.target.value.length">
                        <x-ui.textarea label="Message" wire:model="body" rows="4" maxlength="2000" placeholder="What people need to know, and what to do." />
                        <p class="mt-1 text-right text-xs text-gray-400" aria-live="polite"><span x-text="length"></span> / 2000</p>
                    </div>
                </div>

                {{-- Who sees it --}}
                <fieldset>
                    <legend class="ui-label">Who sees it</legend>
                    <div class="mt-2 inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 text-sm dark:border-gray-700 dark:bg-gray-800" role="radiogroup" aria-label="Audience">
                        <button type="button" role="radio" x-bind:aria-checked="(! pick).toString()" x-on:click="pick = false; $wire.audience = []"
                            x-bind:class="! pick ? 'bg-white text-gray-800 shadow-sm dark:bg-gray-900 dark:text-gray-100' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-100'"
                            class="rounded-md px-3 py-1.5 font-medium">Everyone</button>
                        <button type="button" role="radio" x-bind:aria-checked="pick.toString()" x-on:click="pick = true"
                            x-bind:class="pick ? 'bg-white text-gray-800 shadow-sm dark:bg-gray-900 dark:text-gray-100' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-100'"
                            class="rounded-md px-3 py-1.5 font-medium">Specific roles</button>
                    </div>
                    <div x-show="pick" x-cloak class="mt-3 flex flex-wrap gap-2">
                        @foreach ($roles as $role)
                            <label wire:key="audience-{{ $role->value }}" class="cursor-pointer">
                                <input type="checkbox" wire:model="audience" value="{{ $role->value }}" class="peer sr-only">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-300 px-3 py-1 text-sm text-gray-700 select-none peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:peer-checked:bg-blue-500/10 dark:peer-checked:text-blue-300 dark:hover:bg-white/5">{{ $role->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500" x-text="pick ? 'Only people with a selected role see it.' : 'Every signed-in employee sees it.'"></p>
                    @error('audience') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </fieldset>

                {{-- When --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <div class="flex items-center justify-between">
                            <label for="startsAt" class="ui-label">Starts</label>
                            <button type="button" x-on:click="startNow()" class="text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Now</button>
                        </div>
                        <x-ui.input id="startsAt" name="startsAt" type="datetime-local" x-model="starts" />
                    </div>
                    <div>
                        <label for="endsAt" class="ui-label">Ends <span class="font-normal text-gray-400">(optional)</span></label>
                        <x-ui.input id="endsAt" name="endsAt" type="datetime-local" x-model="ends" />
                        <div class="mt-1.5 flex flex-wrap gap-x-3 text-xs font-medium">
                            <button type="button" x-on:click="endAfter(1)" class="text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">+1 day</button>
                            <button type="button" x-on:click="endAfter(7)" class="text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">+1 week</button>
                            <button type="button" x-on:click="ends = ''" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-100">No end</button>
                        </div>
                    </div>
                </div>

                {{-- Urgent: an email to everyone, so it stands out. --}}
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3 has-checked:border-amber-300 has-checked:bg-amber-50 dark:border-gray-700 dark:has-checked:border-amber-500/40 dark:has-checked:bg-amber-500/10">
                    <input type="checkbox" wire:model="isUrgent" class="mt-0.5 h-4 w-4 rounded border-gray-300 accent-amber-600 focus:ring-amber-500">
                    <span>
                        <span class="flex items-center gap-1.5 text-sm font-medium text-gray-800 dark:text-gray-100"><x-icon name="warning" class="h-4 w-4 text-amber-600 dark:text-amber-400" /> Urgent</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Also emails everyone who sees it, as soon as it goes live. Use it for closures, emergencies and payroll issues.</span>
                    </span>
                </label>
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                <x-ui.button form="announcement-form" wire:loading.attr="disabled" wire:target="save">
                    @if ($editingId)
                        Save changes
                    @else
                        {{-- The footer sits outside the form's Alpine scope: it hears whether the start is later. --}}
                        <span x-data="{ scheduled: false }" x-on:announcement-scheduled.window="scheduled = $event.detail" x-text="scheduled ? 'Schedule' : 'Publish'">Publish</span>
                    @endif
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
