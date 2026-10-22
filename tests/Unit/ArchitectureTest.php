<?php

use Livewire\Component;
use Livewire\Form;

// House rules a pull request can't quietly break.

arch()->preset()->php();
// The demo seeder uses seeded mt_rand() on purpose (same demo data on every machine); it never runs in production.
arch()->preset()->security()->ignoring('Database\Seeders\DemoSeeder');

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

// Fortify's generated classes follow Fortify's contracts (create(), update()), not ours.
arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal()
    ->ignoring('App\Actions\Fortify');

arch('actions have a handle method')
    ->expect('App\Actions')
    ->toHaveMethod('handle')
    ->ignoring('App\Actions\Fortify');

arch('queries are final and have handle()')
    ->expect('App\Queries')
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('enums are enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('data objects are immutable')
    ->expect('App\Data')
    ->toBeReadonly()
    ->toBeFinal();

arch('controllers are invokable')
    ->expect('App\Http\Controllers')
    ->toBeInvokable()
    ->ignoring('App\Http\Controllers\Controller');

arch('livewire pages extend Component')
    ->expect('App\Livewire')
    ->toExtend(Component::class)
    ->ignoring(['App\Livewire\Forms', 'App\Livewire\Concerns']);

arch('form objects extend Form')
    ->expect('App\Livewire\Forms')
    ->toExtend(Form::class);

arch('components stay thin: no DB facade or raw queries')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn('App\Livewire');

arch('calculators are pure: no models, no facades')
    ->expect(['App\Services\Payroll\PayslipCalculator', 'App\Services\Payroll\StatutoryContributions', 'App\Services\Attendance\AttendanceCalculator'])
    ->not->toUse(['Illuminate\Support\Facades', 'Illuminate\Database\Eloquent\Builder', 'App\Models\PayslipRecord', 'App\Models\TimeLog']);

arch('mail is always queued')
    ->expect('App\Notifications')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

// Every notice lands in the bell (email is an extra for the urgent ones), so
// nothing important lives only in an inbox. The two auth emails are mail by nature.
it('sends every notification to the bell', function () {
    $mailOnly = ['QueuedVerifyEmail.php', 'QueuedResetPassword.php'];

    $files = glob(dirname(__DIR__, 2).'/app/Notifications/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        if (in_array(basename($file), $mailOnly, true)) {
            continue;
        }

        $source = (string) file_get_contents($file);
        expect(str_contains($source, "'database'"))->toBeTrue(basename($file).' must include the database (bell) channel')
            // ...written in the request, not left in the queue with the email.
            ->and(str_contains($source, 'WritesBellImmediately'))->toBeTrue(basename($file).' must use WritesBellImmediately');
    }
});

// $wire.upload(), $wire.refresh()… are Livewire's own JavaScript methods. A
// component action with the same name is silently unreachable from Blade:
// wire:submit="upload" calls the JS uploader, not the PHP method. Tests that
// ->call('upload') still pass, so only this check catches it.
it('has no Livewire action named like a built-in $wire method', function () {
    $reserved = ['upload', 'uploadMultiple', 'removeUpload', 'cancelUpload', 'entangle', 'watch', 'refresh', 'js', 'on', 'dispatchSelf', 'intercept'];
    $clashes = [];

    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Livewire';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // app/Livewire/People/RequirementsChecklist.php → App\Livewire\People\RequirementsChecklist
        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'App\\Livewire\\'.strtr($relative, ['/' => '\\']);

        if (! class_exists($class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $class && in_array($method->getName(), $reserved, true)) {
                $clashes[] = "{$class}::{$method->getName()}";
            }
        }
    }

    expect($clashes)->toBe([]);
});

// "â€”" on screen instead of "—": a file was re-saved with the wrong encoding.
// It is easy to miss in review and ugly on payslips, so any such sequence fails.
it('has no garbled (double-encoded) characters in the code or views', function () {
    $root = dirname(__DIR__, 2);
    $patterns = ["\u{00E2}\u{20AC}", "\u{00C3}\u{00A9}", "\u{00E2}\u{201A}\u{00B1}"]; // â€ (— – " ' …), Ã© (é), â‚± (₱)
    $offenders = [];

    foreach (['app', 'config', 'database', 'resources', 'routes', 'tests', 'docs/features'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! in_array($file->getExtension(), ['php', 'md', 'js', 'css'], true) || $file->getFilename() === 'ArchitectureTest.php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                    break;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
