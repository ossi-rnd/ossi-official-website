<?php

use App\Enums\Role;
use App\Livewire\Complaints\MyComplaints;
use App\Livewire\Load\BuyLoad;
use App\Livewire\Resignations\MyResignation;
use App\Models\Complaint;
use App\Models\LoadRequest;
use App\Models\Resignation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// The acknowledgements can be read in English or Filipino; the record keeps the
// lines in the language the employee read, word for word.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
    staff(Role::Hr);
});

it('has every line in both languages', function () {
    $en = Arr::dot(require lang_path('en/acknowledgements.php'));
    $fil = Arr::dot(require lang_path('fil/acknowledgements.php'));

    expect(array_keys($fil))->toEqualCanonicalizing(array_keys($en));

    // Every :placeholder in the English is in the Filipino too (case-insensitive: :Handler / :handler).
    foreach ($en as $key => $text) {
        preg_match_all('/:([a-z]+)/i', $text, $mine);
        preg_match_all('/:([a-z]+)/i', $fil[$key], $theirs);
        expect(array_map('strtolower', $theirs[1]))->toEqualCanonicalizing(array_map('strtolower', $mine[1]));
    }
});

it('switches a form to Filipino, and saves what was read in Filipino', function () {
    $guard = employee();

    Livewire::actingAs($guard)->test(MyResignation::class)
        ->assertSee('I am resigning of my own free will.')
        ->set('ackLocale', 'fil')
        ->assertSee('Nagbibitiw ako nang kusang-loob. Walang pumilit sa akin.')
        ->assertSee('Nabasa ko at naiintindihan ko ang mga ito')
        ->set('reasons', ['another_job'])
        ->set('letter', 'Nagbibitiw ako para sa trabahong mas malapit sa amin.')
        ->set('acknowledges', true)
        ->call('submit')->assertHasNoErrors();

    $resignation = Resignation::query()->sole();
    expect($resignation->acknowledgement)->toBe(Resignation::acknowledgementLines(false, 'HR', 'fil'))
        ->and($resignation->acknowledgement[2])->toBe('Maaari ko lamang itong bawiin hangga\'t hindi pa ito tinatanggap ng HR.')
        // The authorization to deduct is there in Filipino too.
        ->and($resignation->deduction_consent_at)->not->toBeNull();

    // An unknown language falls back to English.
    Livewire::actingAs(employee())->test(MyComplaints::class)->set('ackLocale', 'xx')->assertSet('ackLocale', 'en');
    expect(Complaint::acknowledgementLines('the President or Vice President', 'fil')[1])->toStartWith('Pananatilihing kumpidensyal ito ng Presidente o Bise Presidente');
});

it('saves the Borrow Load authorization in the language it was ticked in', function () {
    $guard = employee();

    Livewire::actingAs($guard)->test(BuyLoad::class)
        ->set('network', 'globe')->set('mobileNumber', '0917 123 4567')->set('amount', 100)
        ->set('ackLocale', 'fil')->set('authorized', true)
        ->call('submit')->assertHasNoErrors();

    expect(LoadRequest::query()->sole()->deduction_authorization)
        ->toBe('Pinahihintulutan ko ang kumpanya na ibawas ang ₱100 mula sa aking susunod na payslip para sa load na ito (Labor Code Art. 113).');
});
