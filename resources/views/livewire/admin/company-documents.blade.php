<div class="space-y-6">
    <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Document type">
        @foreach (\App\Enums\DocumentCategory::cases() as $option)
            <button type="button" role="tab" wire:click="selectTab('{{ $option->value }}')" aria-selected="{{ $tab === $option->value ? 'true' : 'false' }}"
                @class(['-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $option->value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $option->value])>
                {{ $option->label() }}
                @if (($this->dueCounts[$option->value] ?? 0) > 0)
                    <span class="rounded-full bg-amber-100 px-1.5 text-xs text-amber-700 dark:bg-amber-500/20 dark:text-amber-300" title="Due within 30 days or overdue">{{ $this->dueCounts[$option->value] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <x-ui.card :title="$category->label()" :description="$category->description()">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="upload" class="h-4 w-4" /> Upload {{ $category->singular() }}</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-th">Document</th>
                        @if ($category === \App\Enums\DocumentCategory::ClientLegal)<th class="ui-th">Client</th>@endif
                        <th class="ui-th">Reference</th>
                        <th class="ui-th">{{ $category === \App\Enums\DocumentCategory::License ? 'Due date' : 'Expires' }}</th>
                        <th class="ui-th">Uploaded</th>
                        <th class="ui-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->documents as $document)
                        @php
                            $days = $document->daysToExpiry(\Carbon\CarbonImmutable::today());
                            $state = $document->expiryState(\Carbon\CarbonImmutable::today());
                        @endphp
                        <tr wire:key="document-{{ $document->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $document->title }}</p>
                                <p class="text-xs text-gray-500">{{ $document->file_name }}{{ $document->earlier_copies_count > 0 ? ' · renewed copy' : '' }}</p>
                            </td>
                            @if ($category === \App\Enums\DocumentCategory::ClientLegal)
                                <td class="ui-td">{{ $document->client?->name ?? '—' }}</td>
                            @endif
                            <td class="ui-td">{{ $document->reference_number ?? '—' }}</td>
                            <td class="ui-td whitespace-nowrap">
                                @if ($document->expires_on)
                                    <p>{{ $document->expires_on->format('M j, Y') }}</p>
                                    @if ($state === 'expired')
                                        <x-ui.badge tone="red">Overdue {{ abs((int) $days) }} {{ str('day')->plural(abs((int) $days)) }}</x-ui.badge>
                                    @elseif ($state === 'due_soon')
                                        <x-ui.badge tone="amber">{{ $days === 0 ? 'Due today' : "Due in {$days} ".str('day')->plural((int) $days) }}</x-ui.badge>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="ui-td">
                                <p>{{ $document->created_at->format('M j, Y') }}</p>
                                <p class="text-xs text-gray-500">{{ $document->uploader->name ?? '—' }}</p>
                            </td>
                            <td class="ui-td text-right whitespace-nowrap">
                                <a href="{{ route('documents.file', $document) }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View</a>
                                <button type="button" wire:click="renew({{ $document->id }})" class="ml-3 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Renew</button>
                                <x-ui.row-delete wire:click="delete({{ $document->id }})" wire:confirm="Delete &quot;{{ $document->title }}&quot; and its earlier copies? Use Renew for a new version instead.">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">Nothing here yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$renewing ? 'Renew '.$renewing->title : 'Upload '.$category->singular()" close="cancel" max-width="lg">
            <form id="document-form" wire:submit="save" class="space-y-4">
                @if ($renewing)
                    <x-ui.alert tone="blue">The current copy{{ $renewing->expires_on ? ' (due '.$renewing->expires_on->format('M j, Y').')' : '' }} is kept in the history. Upload the renewed one with its new due date.</x-ui.alert>
                @endif

                <x-ui.input label="Title" wire:model="title" :placeholder="match ($category) { \App\Enums\DocumentCategory::License => 'e.g. PNP-SOSIA License to Operate', \App\Enums\DocumentCategory::ClientLegal => 'e.g. Security service agreement', default => 'e.g. SEC Certificate of Incorporation' }" />

                @if ($category === \App\Enums\DocumentCategory::ClientLegal)
                    <x-ui.select label="Client (optional)" wire:model="clientId">
                        <option value="">Not about one client</option>
                        @foreach ($this->clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }} · {{ $client->locationLabel() }}</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <x-ui.input label="Reference number (optional)" wire:model="referenceNumber" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Issued (optional)" type="date" wire:model="issuedOn" />
                    <x-ui.input :label="$category === \App\Enums\DocumentCategory::License ? 'Due date' : 'Expires (optional)'" type="date" wire:model="expiresOn" />
                </div>

                <x-ui.textarea label="Notes (optional)" wire:model="notes" rows="2" maxlength="2000" />

                <x-ui.file-input label="File" model="file" id="document-file" accept="application/pdf,image/png,image/jpeg" hint="PDF, JPG or PNG, up to {{ (int) (config('documents.max_kilobytes') / 1024) }} MB." />
            </form>
            <x-slot:footer>
                <x-ui.button form="document-form" wire:loading.attr="disabled" wire:target="file, save">{{ $renewing ? 'Upload renewal' : 'Upload' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
