{{--
    Region → Province → Client filter bar (Livewire\Concerns\FiltersByArea), under a list's header.
    By where people are deployed, not their home address. wire:key: each list changes with the
    one before it, so the custom select re-mounts with the right options.
--}}
@php
    $isRegion = \App\Enums\Region::tryFrom($this->areaRegion) !== null;
    // $bare: on its own (the dashboard), not as a strip under a card's header.
    $bare ??= false;
@endphp
<div @class(['flex flex-wrap items-center gap-2', 'border-b border-gray-100 px-5 py-3 dark:border-gray-800' => ! $bare])>
    <span class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
        <x-icon name="building" class="h-3.5 w-3.5" /> Client site
    </span>
    <div wire:key="area-region" class="w-full sm:w-52">
        <x-ui.select wire:model.live="areaRegion" aria-label="Region">
            <option value="">All regions</option>
            @foreach ($this->areaRegions() as $region)
                <option value="{{ $region->value }}">{{ $region->label() }}</option>
            @endforeach
            {{-- Not for Operations: they see guards only (no view-office-staff), on every page. --}}
            @if (auth()->user()?->can(\App\Enums\Ability::ViewOfficeStaff->value))
                <option value="{{ $this::OFFICE }}">Office staff (no client)</option>
            @endif
        </x-ui.select>
    </div>
    @if ($isRegion)
        <div wire:key="area-province-{{ $this->areaRegion }}" class="w-full sm:w-48">
            <x-ui.select wire:model.live="areaProvince" aria-label="Province">
                <option value="">All provinces</option>
                @foreach ($this->areaProvinces() as $province)
                    <option value="{{ $province }}">{{ $province }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div wire:key="area-client-{{ $this->areaRegion }}-{{ $this->areaProvince }}" class="w-full sm:w-56">
            <x-ui.select wire:model.live="areaClient" aria-label="Client">
                <option value="">All clients</option>
                @foreach ($this->areaClients() as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    @endif
    @if ($this->hasAreaFilter())
        <button type="button" wire:click="clearArea" class="text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Clear</button>
    @endif
</div>
