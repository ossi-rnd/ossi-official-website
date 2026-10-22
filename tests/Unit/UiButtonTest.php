<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

it('renders a normal button when there is no disabled reason', function () {
    $html = Blade::render('<x-ui.button wire:click="save">Save</x-ui.button>');

    expect($html)->toContain('wire:click="save"')->not->toContain('aria-disabled')->not->toContain('role="tooltip"');
});

it('disables the button, drops its action and explains why when given a reason', function () {
    $html = Blade::render('<x-ui.button wire:click="save" wire:confirm="Sure?" :disabled-reason="$reason">Save</x-ui.button>', ['reason' => 'Fill in the period first.']);

    expect($html)
        ->toContain('disabled aria-disabled="true"')
        ->toContain('role="tooltip"')
        ->toContain('Fill in the period first.')
        ->toContain('tabindex="0"')          // reachable by keyboard and tap
        ->toContain('aria-describedby="tooltip-')
        ->not->toContain('wire:click')
        ->not->toContain('wire:confirm');
});

// The theme is blue + grays. Green buttons were removed on purpose (see the colour
// rules in components/ui/button.blade.php); this stops them coming back.
it('uses no green "success" buttons anywhere', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php') && str_contains((string) file_get_contents($file->getPathname()), 'variant="success"')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
