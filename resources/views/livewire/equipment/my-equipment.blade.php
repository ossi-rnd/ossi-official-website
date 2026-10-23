<div class="mx-auto max-w-5xl space-y-6">
    @php
        $tab = $this->shownTab();
        $waiting = \App\Livewire\Equipment\MyEquipment::waitingFor(auth()->user());
        $peso = fn ($amount) => '₱'.number_format((float) $amount, 2);
        $cartCount = array_sum(array_column($cart, 'quantity'));
    @endphp

    {{-- A uniform's price waits for their authority to deduct (Art. 113), as on My Payslips. --}}
    <livewire:payroll.deduction-authorizations />

    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="My Equipment">
        @foreach (['shop' => 'Shop', 'mine' => 'My items'] as $value => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $value }}')" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                @class(['-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $value])>
                {{ $label }}
                @if ($value === 'shop' && $cartCount > 0)
                    <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-700 tabular-nums dark:bg-gray-800 dark:text-gray-200">{{ $cartCount }} in cart</span>
                @elseif ($value === 'mine' && $waiting > 0)
                    <span class="rounded-full bg-amber-100 px-1.5 text-xs text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ $waiting }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($tab === 'shop')
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            {{-- The items for me. Columns follow the space this area has (a container query), not
                 the screen: beside the cart on a wide screen it is still only ~46rem. --}}
            <div class="@container min-w-0">
            <div class="grid content-start gap-4 @lg:grid-cols-2 @4xl:grid-cols-3">
                @forelse ($this->items as $item)
                    @php $picked = $picks[$item->id]['size'] ?? ''; $uniform = $item->kind === \App\Enums\EquipmentKind::Uniform; @endphp
                    <article wire:key="shop-{{ $item->id }}" class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex aspect-[4/3] items-center justify-center bg-gray-50 dark:bg-gray-800/60">
                            @if ($item->photoUrl())
                                <img src="{{ $item->photoUrl() }}" alt="{{ $item->name }}" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <x-icon name="package" class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            @endif
                        </div>
                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $item->name }}</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    @if ($uniform)
                                        {{ $picked !== '' ? $item->priceLabel($picked) : $item->priceLabel() }} · paid through your payslip, once you authorize it
                                    @else
                                        Company property · no charge · returned when you leave
                                    @endif
                                </p>
                            </div>
                            <div class="mt-auto space-y-3">
                                {{-- Sizes as chips: one tap, and every size is visible. --}}
                                @if ($item->hasSizes())
                                    <div role="radiogroup" aria-label="Size of {{ $item->name }}">
                                        <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Size</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($item->sizeList() as $size)
                                                <button type="button" role="radio" aria-checked="{{ $picked === $size ? 'true' : 'false' }}"
                                                    wire:click="pickSize({{ $item->id }}, @js($size))"
                                                    @class(['min-w-9 rounded-md border px-2 py-1 text-xs font-medium tabular-nums transition-colors',
                                                        'border-blue-600 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:hover:bg-blue-500/20' => $picked === $size,
                                                        'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800' => $picked !== $size])>{{ $size }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Quantity stepper, then the button. --}}
                                @php $qty = (int) ($picks[$item->id]['quantity'] ?? 1); @endphp
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Quantity</p>
                                    <div class="inline-flex shrink-0 items-center rounded-md border border-gray-300 dark:border-gray-700" role="group" aria-label="Quantity of {{ $item->name }}">
                                        <button type="button" wire:click="pickQuantity({{ $item->id }}, -1)" @disabled($qty <= 1)
                                            class="flex h-9 w-9 items-center justify-center rounded-l-md text-gray-600 hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-white/10" aria-label="One less">−</button>
                                        <span class="w-7 text-center text-sm font-medium tabular-nums text-gray-800 dark:text-gray-100" aria-live="polite">{{ $qty }}</span>
                                        <button type="button" wire:click="pickQuantity({{ $item->id }}, 1)" @disabled($qty >= \App\Actions\Equipment\RequestEquipment::MAX_QUANTITY)
                                            class="flex h-9 w-9 items-center justify-center rounded-r-md text-gray-600 hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-white/10" aria-label="One more">+</button>
                                    </div>
                                </div>
                                {{-- Its own row, full width: a card can be narrow (three across beside the cart). --}}
                                <x-ui.button type="button" variant="secondary" size="sm" class="w-full justify-center" wire:click="addToCart({{ $item->id }})" wire:target="addToCart({{ $item->id }})"><x-icon name="cart" class="h-4 w-4" /> Add to cart</x-ui.button>
                            </div>
                            @error("picks.{$item->id}")<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </article>
                @empty
                    <p class="col-span-full rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Nothing in the shop for you yet. Admin adds the items.</p>
                @endforelse
            </div>
            </div>

            {{-- The cart. On a phone it comes after the items, so a bar at the bottom of the screen
                 says what's in it and jumps here. --}}
            <aside id="cart" class="scroll-mt-20 lg:sticky lg:top-20 lg:self-start">
                <x-ui.card title="Your cart" :description="$cartCount > 0 ? 'Sent as one request. Operations (guards) or Admin (office staff) issues it.' : 'Add what you need from the shop.'">
                    @if ($this->cartLines !== [])
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($this->cartLines as $line)
                                <li wire:key="cart-{{ $line['index'] }}" class="flex items-start justify-between gap-3 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $line['item']->name }}{{ $line['size'] ? ' ('.$line['size'].')' : '' }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $line['item']->kind === \App\Enums\EquipmentKind::Uniform ? ($line['price'] !== null ? $peso($line['price'] * $line['quantity']) : 'Price not set yet') : 'No charge' }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <button type="button" wire:click="changeQuantity({{ $line['index'] }}, -1)" class="rounded-md px-2 py-0.5 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10" aria-label="One less">−</button>
                                        <span class="w-5 text-center text-sm tabular-nums">{{ $line['quantity'] }}</span>
                                        <button type="button" wire:click="changeQuantity({{ $line['index'] }}, 1)" class="rounded-md px-2 py-0.5 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10" aria-label="One more">+</button>
                                        <button type="button" wire:click="removeFromCart({{ $line['index'] }})" class="ml-1 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200" aria-label="Remove"><x-icon name="x" class="h-4 w-4" /></button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @php $uniformTotal = collect($this->cartLines)->filter(fn ($l) => $l['item']->kind === \App\Enums\EquipmentKind::Uniform)->sum(fn ($l) => ($l['price'] ?? 0) * $l['quantity']); @endphp
                        <div class="space-y-3 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                            @if ($uniformTotal > 0)
                                <p class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Uniforms, if issued</span><span class="font-medium tabular-nums">{{ $peso($uniformTotal) }}</span></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Nothing is deducted until it's issued and you authorize it.</p>
                            @endif
                            <x-ui.textarea label="Note (optional)" wire:model="cartNote" rows="2" maxlength="500" placeholder="e.g. My polo is torn" />
                            @error('cart')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                            <x-ui.button type="button" class="w-full justify-center" wire:click="sendCart" wire:target="sendCart" wire:loading.attr="disabled">Send request</x-ui.button>
                        </div>
                    @else
                        <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Your cart is empty.</p>
                    @endif
                </x-ui.card>
            </aside>
        </div>

        @if ($cartCount > 0)
            <div class="h-16 lg:hidden" aria-hidden="true"></div>{{-- room, so the bar never covers the last card --}}
            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden dark:border-gray-800 dark:bg-gray-900/95" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-3">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $cartCount }} {{ str('item')->plural($cartCount) }} in your cart</p>
                    <a href="#cart" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"><x-icon name="cart" class="h-4 w-4" /> Review cart</a>
                </div>
            </div>
        @endif
    @else
        <x-ui.card title="My items" description="Uniforms, radios and gear issued to you, and what you asked for. Company property stays the company's: take care of it and return it when asked or when you leave.">
            @if ($this->mine->contains(fn ($issue) => $issue->status === \App\Enums\EquipmentStatus::Issued && $issue->acknowledged_at === null))
                <div class="px-6 pt-4">@include('livewire.partials.acknowledgement-language')</div>
            @endif

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($this->mine as $issue)
                    <li wire:key="mine-{{ $issue->id }}" class="space-y-3 px-6 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $issue->describe() }}
                                <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $issue->charged ? 'Uniform, '.$peso($issue->total()) : ($issue->status === \App\Enums\EquipmentStatus::Requested ? 'Requested' : 'Company property') }}</span>
                            </p>
                            <x-ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-ui.badge>
                        </div>

                        @if ($issue->status === \App\Enums\EquipmentStatus::Issued)
                            <p class="text-xs text-gray-500 dark:text-gray-400">Issued {{ $issue->issued_at?->format('M j, Y') }} by {{ $issue->issuer?->name ?? '—' }}</p>
                            @if ($issue->acknowledged_at === null)
                                <p class="rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" lang="{{ $ackLocale }}">“{{ \App\Actions\Equipment\AcknowledgeEquipment::text($issue, $ackLocale) }}”</p>
                                <div class="flex justify-end">
                                    <x-ui.button type="button" size="sm" variant="dark" wire:click="acknowledge({{ $issue->id }})" wire:target="acknowledge({{ $issue->id }})">{{ \App\Support\Acknowledgements::line('ui.received', $ackLocale) }}</x-ui.button>
                                </div>
                            @else
                                <p class="text-xs text-gray-500 dark:text-gray-400">You confirmed receiving it on {{ $issue->acknowledged_at->format('M j, Y') }}.</p>
                            @endif
                        @elseif ($issue->status === \App\Enums\EquipmentStatus::Requested)
                            <p class="text-xs text-gray-500 dark:text-gray-400">Asked {{ $issue->requested_at?->format('M j, Y') }}{{ $issue->request_note ? ' · “'.$issue->request_note.'”' : '' }}</p>
                        @elseif ($issue->status === \App\Enums\EquipmentStatus::Rejected)
                            <p class="text-xs text-gray-500 dark:text-gray-400">Not approved: {{ $issue->reject_reason }}</p>
                        @elseif ($issue->status === \App\Enums\EquipmentStatus::Returned)
                            <p class="text-xs text-gray-500 dark:text-gray-400">Returned {{ $issue->returned_at?->format('M j, Y') }}</p>
                        @endif

                        @foreach ($issue->incidents as $incident)
                            <div wire:key="incident-{{ $incident->id }}" class="space-y-2 rounded-lg border border-amber-200 px-3 py-2.5 text-sm dark:border-amber-500/30">
                                <p class="font-medium text-amber-700 dark:text-amber-400">Reported {{ strtolower($incident->kind->label()) }} on {{ $incident->created_at->format('M j, Y') }}</p>
                                <p class="text-gray-700 dark:text-gray-300">{{ $incident->details }}</p>
                                @if ($incident->explanation)
                                    <p class="text-gray-600 dark:text-gray-400">Your side: “{{ $incident->explanation }}”</p>
                                @endif
                                @if ($incident->decision)
                                    <p class="text-gray-700 dark:text-gray-300">
                                        <span class="font-medium">{{ $incident->decision->label() }}{{ $incident->loss_amount ? ': '.$peso($incident->loss_amount).', '.$peso($incident->installment).' per payslip' : '' }}.</span>
                                        {{ $incident->decision_reason }}
                                    </p>
                                @elseif ($incident->canStillExplain())
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-gray-600 dark:text-gray-400">You may explain until {{ $incident->explain_by->format('M j, Y') }}. Nothing is charged before you are heard.</p>
                                        <x-ui.button type="button" size="sm" variant="dark" wire:click="startExplain({{ $incident->id }})">Explain</x-ui.button>
                                    </div>
                                @else
                                    <p class="text-gray-600 dark:text-gray-400">Waiting for the decision.</p>
                                @endif
                            </div>
                        @endforeach
                    </li>
                @empty
                    <li class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Nothing issued to you yet. See the Shop for what you can ask for.</li>
                @endforelse
            </ul>
        </x-ui.card>
    @endif

    @if ($explainingId)
        <x-ui.modal title="Your side" close="closeDialogs" max-width="md">
            <form id="explain-form" wire:submit="explain">
                <x-ui.textarea label="What happened, in your own words" wire:model="explanation" rows="5" maxlength="3000" hint="Sent once. Your head reads it before deciding." />
            </form>
            <x-slot:footer>
                <x-ui.button form="explain-form" wire:loading.attr="disabled" wire:target="explain">Send</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
