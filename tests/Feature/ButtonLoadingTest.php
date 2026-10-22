<?php

// Every <x-ui.button> can show a spinner while busy (data-loading from Livewire or
// form-loading.js, or .is-busy from wire:target), covering the label so the size stays.

it('gives every button a hidden spinner that shows while it is busy', function () {
    $html = (string) $this->blade('<x-ui.button class="w-full">Log in</x-ui.button>');

    expect($html)->toContain('group relative')
        ->toContain('group-data-loading:invisible group-[.is-busy]:invisible">Log in</span>')
        ->toContain('hidden items-center justify-center group-data-loading:flex')
        ->toContain('animate-spin')
        ->not->toContain('wire:loading.class');
});

it('also shows it while the wire:target it names is running, e.g. an upload', function () {
    $html = (string) $this->blade('<x-ui.button wire:loading.attr="disabled" wire:target="file, save">Upload</x-ui.button>');

    expect($html)->toContain('wire:loading.class="is-busy"')
        ->toContain('wire:target="file, save"')
        ->toContain('group-[.is-busy]:flex');
});

it('puts no spinner on links or locked buttons', function () {
    expect((string) $this->blade('<x-ui.button href="/payroll">Open</x-ui.button>'))->not->toContain('animate-spin')
        ->and((string) $this->blade('<x-ui.button disabled-reason="Generate all payslips first.">Approve</x-ui.button>'))->not->toContain('animate-spin');
});

it('loads the plain-form loading script on the login page', function () {
    $this->get(route('login'))->assertOk()->assertSee('group-data-loading:flex', false);
    expect(file_get_contents(resource_path('js/app.js')))->toContain('registerFormLoading();');
});
