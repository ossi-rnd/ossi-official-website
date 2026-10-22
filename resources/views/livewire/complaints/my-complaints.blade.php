<div class="mx-auto max-w-3xl space-y-6">
    {{-- $handler: "HR", or "the President or Vice President" for HR staff (their complaints never reach HR). --}}
    <x-ui.card title="File a complaint" :description="'Goes to '.$handler.' only. Your supervisor and co-workers can\'t see it. '.ucfirst($handler).' updates you here.'">
        <form wire:submit="submit" class="space-y-4 p-6">
            {{-- First, so someone in trouble sees it before anything else. Never counted against the weekly limit. --}}
            <label for="complaint-help" @class([
                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10' => $asksForHelp,
                'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600' => ! $asksForHelp,
            ])>
                <input id="complaint-help" type="checkbox" wire:model.live="asksForHelp" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-800 dark:text-red-400">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">I need help now</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">You're being threatened, or you don't feel safe. {{ ucfirst($handler) }} is alerted at once by email too, and it goes to the top of their list.</span>
                    @if ($asksForHelp)
                        <span class="mt-1 block text-xs font-medium text-gray-800 dark:text-gray-100">In danger right now? Call 911 first, then send this.</span>
                    @endif
                </span>
            </label>

            {{-- The weekly allowance, seen before writing (ComplaintRules::allowanceFor, the same count FileComplaint checks). --}}
            @if ($allowance['limit'] > 0)
                <div class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                    @if ($allowance['left'] === 0)
                        <span class="mt-1 size-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                        <p>
                            <span class="font-medium text-gray-800 dark:text-gray-100">You've used this week's {{ str('complaint')->plural($allowance['limit']) }}.</span>
                            Next one possible {{ $allowance['nextAt']?->format('M j, g:i A') }}. You can still add to an open one below.
                            Harassment, and asking for help, can be sent any time.
                        </p>
                    @else
                        <p>
                            <span class="font-medium text-gray-800 dark:text-gray-100">You can file {{ $allowance['left'] }} {{ str('complaint')->plural($allowance['left']) }} this week</span>
                            (up to {{ $allowance['limit'] }} in 7 days). Harassment, and asking for help, don't count.
                        </p>
                    @endif
                </div>
            @endif

            @error('limit') <x-ui.alert tone="amber">{{ $message }}</x-ui.alert> @enderror

            {{-- About → its common subjects (HR's list), or "Other" to type one. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.select label="About" wire:model.live="category">
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if ($this->subjects->isNotEmpty())
                    {{-- wire:key: the list changes with the category, so the custom select re-mounts. --}}
                    <div wire:key="subjects-{{ $category }}">
                        <x-ui.select label="Subject" wire:model.live="subjectId">
                            <option value="">Choose one</option>
                            @foreach ($this->subjects as $option)
                                <option value="{{ $option->id }}">{{ $option->label }}</option>
                            @endforeach
                            <option value="{{ \App\Livewire\Complaints\MyComplaints::OTHER }}">Other (type it)</option>
                        </x-ui.select>
                    </div>
                @endif
            </div>
            @if ($this->typesSubject())
                <x-ui.input :label="$this->subjects->isEmpty() ? 'Subject' : 'Your subject'" wire:model="subject" maxlength="150" placeholder="One line, e.g. Relief guard keeps arriving two hours late" />
            @endif
            <x-ui.textarea label="What happened" wire:model="details" rows="5" maxlength="5000" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input label="Person or party involved (optional)" wire:model="against" />
                <x-ui.input label="When it happened (optional)" type="date" wire:model="incidentOn" :max="today()->toDateString()" />
            </div>

            {{-- Proof helps but is never required: many complaints (harassment, threats) leave nothing on paper. --}}
            <div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Proof, if you have any</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Not required: you can send this without it. {{ ucfirst($handler) }} may ask for it later, and you can add it then.</p>
                </div>
                <div>
                    <label for="complaint-proof" class="ui-label">Photos, screenshots or a PDF <span class="font-normal text-gray-500">(up to {{ config('complaints.max_files') }})</span></label>
                    <input id="complaint-proof" type="file" multiple wire:model="proof" accept="image/jpeg,image/png,application/pdf"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200 dark:hover:file:bg-gray-700">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Only you and {{ $handler }} can open them.</p>
                    @error('proof') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @error('proof.*') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <x-ui.input label="Who saw it (optional)" wire:model="witnesses" maxlength="1000" placeholder="e.g. Jun Reyes, the day guard at Gate 2" />
            </div>

            {{-- True, the person involved may be told (due process), no retaliation: saved with the complaint. --}}
            @include('livewire.partials.acknowledgement', ['lines' => \App\Models\Complaint::acknowledgementLines($handler, $ackLocale), 'model' => 'acknowledges', 'id' => 'complaint-acknowledge'])

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <x-ui.button wire:loading.attr="disabled" wire:target="submit">
                    Send to {{ $handler }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="My complaints">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->complaints as $complaint)
                <li wire:key="complaint-{{ $complaint->id }}" class="p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 dark:text-gray-100">{{ $complaint->subject }}</p>
                            <p class="text-xs text-gray-500">{{ $complaint->reference() }} · {{ $complaint->category->label() }} · filed {{ $complaint->created_at->format('M j, Y') }}</p>
                        </div>
                        <span class="flex shrink-0 flex-wrap gap-1.5">
                            @if ($complaint->needsHelpNow()) <x-ui.badge tone="red">Asked for help</x-ui.badge> @endif
                            <x-ui.badge :tone="$complaint->status->tone()">{{ $complaint->status->label() }}</x-ui.badge>
                        </span>
                    </div>

                    {{-- The handler asked for proof: what for, and where to add it. --}}
                    @if ($complaint->awaitsProof())
                        <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                            <p class="font-medium text-gray-800 dark:text-gray-100">{{ ucfirst($complaint->handlerLabel()) }} asks for proof, if you have any</p>
                            <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $complaint->proof_request_note }}</p>
                        </div>
                    @endif

                    @if ($complaint->files->isNotEmpty() || ! $complaint->status->isClosed())
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            @foreach ($complaint->files as $file)
                                <a href="{{ route('complaint.file', $file) }}" target="_blank" class="max-w-48 truncate font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">{{ $file->name ?? 'Proof' }}</a>
                            @endforeach
                            @unless ($complaint->status->isClosed())
                                <button type="button" wire:click="startAddingProof({{ $complaint->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Add proof</button>
                            @endunless
                        </div>
                    @endif

                    @if ($complaint->resolution)
                        <div class="mt-3 rounded-md bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800/60 dark:text-gray-200">
                            <p class="text-xs font-medium tracking-wide text-gray-400 uppercase">Outcome from {{ $complaint->handlerLabel() }}</p>
                            <p class="mt-1 whitespace-pre-line">{{ $complaint->resolution }}</p>
                        </div>
                    @endif
                </li>
            @empty
                <li class="p-8 text-center text-sm text-gray-500">No complaints filed.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($addingProofTo)
        <x-ui.modal title="Add proof" close="stopAddingProof" max-width="md">
            <form id="proof-form" wire:submit="addProof" class="space-y-3">
                <label for="complaint-extra-proof" class="ui-label">Photo, screenshot or PDF</label>
                <input id="complaint-extra-proof" type="file" wire:model="extraProof" accept="image/jpeg,image/png,application/pdf"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200 dark:hover:file:bg-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Only you and {{ $handler }} can open it. {{ ucfirst($handler) }} is told.</p>
                @error('extraProof') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error('proof') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </form>
            <x-slot:footer>
                <x-ui.button form="proof-form" wire:target="addProof,extraProof">Add</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
