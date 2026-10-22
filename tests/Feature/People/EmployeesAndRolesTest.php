<?php

use App\Actions\Payroll\GeneratePayslip;
use App\Actions\People\SetEmployeeRate;
use App\Actions\Roles\SyncUserRoles;
use App\Enums\Ability;
use App\Enums\Position;
use App\Enums\Role;
use App\Livewire\Admin\DailyRates;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\RolesAccess;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRate;
use App\Models\TimeLog;
use App\Notifications\DailyRateNeeded;
use App\Queries\People\RatesInForceQuery;
use App\Support\EmployeeNumber;
use App\Support\GovernmentId;
use App\Support\Provinces;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('lets HR add an employee, which is what allows them to register, and asks Accounting for their rate', function () {
    Notification::fake();
    $accounting = staff(Role::Accounting);

    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)
        ->call('create')
        ->set('form.hire_date', '2024-06-01')->set('form.birth_province', 'PAM')
        ->set('form.name', 'Ana Reyes')
        ->set('form.email', 'Ana.Reyes@Example.test')
        ->set('form.sss_number', '34-1234567-8')
        ->call('save')
        ->assertHasNoErrors();

    $profile = EmployeeProfile::query()->where('email', 'ana.reyes@example.test')->sole();
    expect($profile->employee_number)->toMatch('/^240601PAM-\d{4}$/'); // generated: hire date + province + 4 digits

    // HR doesn't set pay: Accounting is asked to.
    Notification::assertSentTo($accounting, DailyRateNeeded::class, fn (DailyRateNeeded $notice) => $notice->profile->is($profile)
        // The number on its own line in the bell, so it doesn't break at its dash.
        && $notice->toArray($accounting)['body'] === "Employee no. {$profile->employee_number}, just added by HR.\nSet their daily rate before their first payroll.");
    expect(EmployeeRate::query()->count())->toBe(0)
        ->and($profile->email)->toBe('ana.reyes@example.test')
        ->and($profile->isClaimed())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'profile.created')->exists())->toBeTrue();
});

it('encrypts government IDs at rest and keeps them out of the audit log', function () {
    $profile = EmployeeProfile::factory()->create(['sss_number' => '34-1234567-8']);

    $raw = DB::table('employee_profiles')->where('id', $profile->id)->value('sss_number');

    expect($raw)->not->toContain('1234567')
        ->and($profile->fresh()?->sss_number)->toBe('34-1234567-8');

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')->set('form.sss_number', '34-7654321-0')->call('save')->assertHasNoErrors();

    $entry = ActivityLog::query()->where('event', 'profile.updated')->sole();
    expect(json_encode($entry->properties))->not->toContain('7654321')->toContain('(changed)');
});

it('accepts government IDs only in the agency format, and saves them dashed', function () {
    $profile = EmployeeProfile::factory()->create();

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->set('form.sss_number', '34-12345A7-8')       // a letter
        ->set('form.philhealth_number', '12-34567890-1') // 11 digits, PIN has 12
        ->set('form.pagibig_number', '1234 5678 9012')
        ->set('form.tin_number', '123-456-789-0000')     // 13: neither 9, 12 nor 14
        ->call('save')
        ->assertHasErrors(['form.sss_number', 'form.philhealth_number', 'form.tin_number'])
        ->assertHasNoErrors('form.pagibig_number')
        ->set('form.sss_number', '3412345678')
        ->set('form.philhealth_number', '')               // not known yet is fine
        ->set('form.tin_number', '123456789-00000')
        ->call('save')
        ->assertHasNoErrors();

    $profile->refresh();
    expect($profile->sss_number)->toBe('34-1234567-8')
        ->and($profile->philhealth_number)->toBeNull()
        ->and($profile->pagibig_number)->toBe('1234-5678-9012')
        ->and($profile->tin_number)->toBe('123-456-789-00000')
        ->and(GovernmentId::problem('tin_number', '123-456-789'))->toBeNull()
        ->and(GovernmentId::problem('sss_number', '34-1234567'))->toBe('SSS No. has 10 digits (00-0000000-0); this one has 9.');
});

it('saves phones in one readable shape and refuses dirty names and numbers', function () {
    $profile = EmployeeProfile::factory()->create();

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->set('form.name', 'Juan 2')
        ->set('form.contact_number', '(02) 8123 4567')      // their own number must be a mobile
        ->set('form.emergency_contact_phone', '0917 CALL')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.contact_number', 'form.emergency_contact_phone'])
        ->set('form.name', '  Juan   Dela Cruz ')
        ->set('form.contact_number', '+63 917-123-4567')
        ->set('form.emergency_contact_phone', '(02) 8123 4567')
        ->call('save')
        ->assertHasNoErrors();

    $profile->refresh();
    expect($profile->name)->toBe('Juan Dela Cruz')
        ->and($profile->contact_number)->toBe('0917 123 4567')
        ->and($profile->emergency_contact_phone)->toBe('(02) 8123 4567');
});

it('requires a unique employee number and email', function () {
    EmployeeProfile::factory()->create(['employee_number' => '240601PAM-0001', 'email' => 'one@example.test']);

    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)
        ->call('create')
        ->call('useNumberFrom', 'card')->set('form.employee_number', '240601PAM-0001')->set('form.name', 'Dup')->set('form.email', 'one@example.test')
        ->call('save')
        ->assertHasErrors(['form.employee_number', 'form.email']);
});

it('changes roles, audited, and refuses to remove your own Super Admin role', function () {
    $superAdmin = staff(Role::SuperAdmin);
    $target = employee();

    Livewire::actingAs($superAdmin)->test(RolesAccess::class)
        ->call('edit', $target->id)->set('jobRole', 'hr')->call('save')->assertHasNoErrors();

    expect($target->fresh()?->hasRole(Role::Hr))->toBeTrue();
    expect(ActivityLog::query()->where('event', 'roles.changed')->sole()->properties)->toMatchArray([
        'old' => ['roles' => ['employee']],
        'new' => ['roles' => ['employee', 'hr']],
    ]);

    Livewire::actingAs($superAdmin)->test(RolesAccess::class)
        ->call('edit', $superAdmin->id)->set('jobRole', '')->call('save')->assertHasErrors('roles');
});

it('keeps at least one Super Admin', function () {
    $first = staff(Role::SuperAdmin);
    $second = staff(Role::SuperAdmin);

    // Two exist: one may remove the other.
    app(SyncUserRoles::class)->handle($second, [Role::Employee], $first);

    // Now $first is the last one; not even another caller may remove them.
    expect(fn () => app(SyncUserRoles::class)->handle($first->fresh() ?? $first, [Role::Employee], $second))
        ->toThrow(ValidationException::class, 'At least one Super Admin must remain.');
});

it('does not show guard deployment fields for office staff', function () {
    $guard = employee(['detachment' => 'SM Megamall', 'post' => 'Main Entrance']);
    $hr = staff(Role::Hr);
    $hr->employeeProfile?->forceFill(['detachment' => 'Leftover', 'post' => 'Leftover post'])->save();

    $this->actingAs($guard)->get(route('profile'))->assertSee('Detachment')->assertSee('SM Megamall');
    $this->actingAs($hr)->get(route('profile'))->assertDontSee('Detachment')->assertDontSee('Leftover');
    $this->actingAs($hr)->get(route('employees.show', $hr->employeeProfile))->assertDontSee('Leftover post');

    Livewire::actingAs($hr)->test(Employees::class)->assertSee('Office staff')->assertSee('SM Megamall · Main Entrance');
});

it('searches everyone in one box and narrows to guards or office staff by tab, with counts', function () {
    employee(['name' => 'Gina Guard', 'detachment' => 'SM Megamall', 'post' => 'Lobby']);
    $hr = staff(Role::Hr);
    $hr->employeeProfile?->forceFill(['name' => 'Olive Office', 'position' => Position::AccountingStaff])->save();

    $page = Livewire::actingAs($hr)->test(Employees::class)
        ->assertSee('Gina Guard')->assertSee('Olive Office')
        // Office staff show their position in the Assignment column.
        ->assertSee('Assignment')->assertSee(Position::AccountingStaff->label());

    $counts = $page->instance()->kindCounts;
    expect($counts['all'])->toBe($counts['field'] + $counts['office'])
        ->and($counts['office'])->toBeGreaterThan(0);

    $page->call('showKind', 'field')->assertSet('kind', 'field')->assertSee('Gina Guard')->assertDontSee('Olive Office');
    $page->call('showKind', 'office')->assertSee('Olive Office')->assertDontSee('Gina Guard');
    $page->call('showKind', 'nonsense')->assertSet('kind', 'all');

    // The search works across both, and inside a tab.
    $page->set('search', 'Olive')->assertSee('Olive Office')->assertDontSee('Gina Guard');
});

it('shows Operations guards only, with no tabs', function () {
    employee(['name' => 'Gina Guard', 'detachment' => 'SM Megamall', 'post' => 'Lobby']);
    staff(Role::Hr)->employeeProfile?->forceFill(['name' => 'Olive Office'])->save();

    Livewire::actingAs(staff(Role::OperationsHq))->test(Employees::class)
        ->assertSee('Gina Guard')->assertDontSee('Olive Office')
        ->assertDontSeeHtml('aria-label="Which employees"')
        // Asking for the office tab changes nothing.
        ->set('kind', 'office')->assertSee('Gina Guard')->assertDontSee('Olive Office');
});

it('clears detachment and post and sets the office position when a record becomes office staff', function () {
    $profile = employee(['detachment' => 'SM Megamall', 'post' => 'Lobby', 'position' => 'security_guard'])->employeeProfile;

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->assertSee('Detachment')
        ->assertSeeHtml('value="security_guard"')
        ->set('form.is_office_staff', true)
        ->assertDontSeeHtml('value="security_guard"')
        ->assertDontSeeHtml('wire:model="form.post"')
        ->call('save')
        ->assertHasNoErrors();

    expect($profile?->fresh())
        ->detachment->toBeNull()
        ->post->toBeNull()
        ->position->toBeNull();   // HR then picks the office job
});

it('drops an office position when a record goes back to the field', function () {
    $profile = staff(Role::Hr)->employeeProfile;
    $profile?->forceFill(['position' => Position::HrStaff])->save();

    Livewire::actingAs(staff(Role::SuperAdmin))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->set('form.is_office_staff', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($profile?->fresh()?->position)->toBeNull();
});

it('lets Accounting set a daily rate from a date, keeping the history, and payroll uses the one in force', function () {
    $guard = employee();
    $profile = $guard->employeeProfile;

    Livewire::actingAs(staff(Role::Accounting))->test(DailyRates::class)
        ->assertSee('Company default')
        ->call('startRateChange', $profile->id)
        ->set('rateAmount', '755')->set('rateEffectiveFrom', '2026-09-26')->set('rateNote', 'Wage Order NCR-28')
        ->call('saveRate')->assertHasNoErrors()
        ->call('startRateChange', $profile->id)
        ->assertSee('Wage Order NCR-28')                  // history in the modal
        ->set('rateAmount', '800')->set('rateEffectiveFrom', '2026-10-16')
        ->call('saveRate')->assertHasNoErrors();

    $rates = app(RatesInForceQuery::class);
    expect($rates->handle([$profile->id], CarbonImmutable::parse('2026-09-25')))->toBe([])
        ->and($rates->handle([$profile->id], CarbonImmutable::parse('2026-10-01')))->toBe([$profile->id => 755.0])
        ->and($rates->handle([$profile->id], CarbonImmutable::parse('2026-10-16')))->toBe([$profile->id => 800.0]);
    expect(ActivityLog::query()->where('event', 'rate.set')->count())->toBe(2);
});

it('shows HR the rate read-only, and keeps setting it to Accounting', function () {
    $hr = staff(Role::Hr);
    $profile = employee()->employeeProfile;
    app(SetEmployeeRate::class)->handle($profile, 760, CarbonImmutable::parse('2026-01-01'), null, staff(Role::Accounting));

    Livewire::actingAs($hr)->test(EmployeeDetail::class, ['profile' => $profile])
        ->assertSee('₱760.00')
        ->assertSee('Set by Accounting')
        ->assertDontSee('Change rate');

    expect(fn () => app(SetEmployeeRate::class)->handle($profile, 800, CarbonImmutable::today(), null, $hr))->toThrow(AuthorizationException::class);
    $this->actingAs($hr)->get(route('daily-rates'))->assertForbidden();
});

it('refuses a rate change that would contradict a payslip already generated', function () {
    $guard = employee();
    $run = payrollRun('2026-10-01', '2026-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $guard->id]);
    app(GeneratePayslip::class)->handle($run, $guard, staff(Role::Accounting));

    expect(fn () => app(SetEmployeeRate::class)->handle($guard->employeeProfile, 800, CarbonImmutable::parse('2026-10-10'), null, staff(Role::Accounting)))
        ->toThrow(ValidationException::class, 'Start the new rate on Oct 16, 2026 or later');
});

it('takes the blood type from the list only', function () {
    $hr = staff(Role::Hr);
    $add = fn (string $type) => Livewire::actingAs($hr)->test(Employees::class)->call('create')
        ->set('form.hire_date', '2024-06-01')->set('form.birth_province', 'PAM')->set('form.name', 'Blood Test')->set('form.email', 'blood'.strlen($type).'@example.test')
        ->set('form.blood_type', $type)->call('save');

    $add('o pos')->assertHasErrors(['form.blood_type']);
    $add('AB-')->assertHasNoErrors();
    expect(EmployeeProfile::query()->where('email', 'blood3@example.test')->value('blood_type'))->toBe('AB-');
});

it('takes an ID card\'s number only in the card format, YYMMDD + province + 4 digits, -RH for a rehire', function () {
    $hr = staff(Role::Hr);
    $add = fn (string $number, string $email) => Livewire::actingAs($hr)->test(Employees::class)->call('create')
        ->call('useNumberFrom', 'card')->set('form.employee_number', $number)->set('form.name', 'Format Test')->set('form.email', $email)->call('save');

    $add('EMP-1234', 'a@example.test')->assertHasErrors(['form.employee_number']);
    $add('240601-PAM-1001', 'b@example.test')->assertHasErrors(['form.employee_number']);   // the old dashed shape isn't the card's
    $add('240631PAM-1001', 'c@example.test')->assertHasErrors(['form.employee_number']);    // June 31 isn't a date
    $add('240601SEC-1003', 'f@example.test')->assertHasErrors(['form.employee_number']);    // SEC isn't a province code
    $add('240601PAM-1001-XX', 'g@example.test')->assertHasErrors(['form.employee_number']);
    $add(' 240601pam-1001 ', 'd@example.test')->assertHasNoErrors();                        // tidied: uppercase, no spaces
    $add('240601PAM-1002-RH', 'e@example.test')->assertHasNoErrors();

    expect(EmployeeProfile::query()->pluck('employee_number')->all())->toContain('240601PAM-1001', '240601PAM-1002-RH')
        // The hire date comes from the card's number.
        ->and(EmployeeProfile::query()->where('email', 'e@example.test')->sole()->hire_date?->toDateString())->toBe('2024-06-01')
        ->and(EmployeeNumber::isRehire('240601PAM-1002-RH'))->toBeTrue();
});

it('generates the number from the hire date and province of birth, -RH for a rehire', function () {
    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)->call('create')
        ->assertSee('Choose the hire date and province of birth')
        ->set('form.name', 'Province Test')->set('form.email', 'prov@example.test')
        ->call('save')->assertHasErrors(['form.hire_date', 'form.birth_province'])
        ->set('form.hire_date', '2026-06-27')->set('form.birth_province', 'PAM')->set('form.rehired', true)
        ->assertSee('260627PAM-····-RH')
        ->call('save')->assertHasNoErrors();

    $profile = EmployeeProfile::query()->where('email', 'prov@example.test')->sole();
    expect($profile->employee_number)->toMatch('/^260627PAM-\d{4}-RH$/')
        ->and(EmployeeNumber::provinceCode($profile->employee_number))->toBe('PAM');

    // Editing keeps it, even when the hire date changes: it's the ID card's identity.
    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')->assertSet('form.birth_province', 'PAM')->assertSee($profile->employee_number)
        ->set('form.hire_date', '2026-07-01')->call('save')->assertHasNoErrors();
    expect($profile->fresh()?->employee_number)->toBe($profile->employee_number);
});

it('refuses a card number that doesn\'t fit the hire date or province on the record', function () {
    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)->call('create')
        ->call('useNumberFrom', 'card')->set('form.employee_number', '260627PAM-0950')
        ->assertSet('form.hire_date', '2026-06-27')->assertSet('form.birth_province', 'PAM') // filled from it
        ->set('form.hire_date', '2026-06-20')
        ->set('form.name', 'Mismatch')->set('form.email', 'mismatch@example.test')
        ->call('save')->assertHasErrors(['form.employee_number']);
});

it('never gives two people the same number for a date and province', function () {
    $taken = collect(range(0, 9998))->map(fn (int $sequence) => '260627PAM-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT));
    foreach ($taken->chunk(500) as $chunk) {
        DB::table('employee_profiles')->insert($chunk->map(fn (string $number) => ['employee_number' => $number, 'name' => 'Taken', 'email' => $number.'@example.test', 'employment_status' => 'regular', 'is_office_staff' => false, 'created_at' => now(), 'updated_at' => now()])->values()->all());
    }

    // Only 9999 is left, and it's found; other dates and provinces are free.
    expect(EmployeeNumber::generate(CarbonImmutable::parse('2026-06-27'), 'PAM', false))->toBe('260627PAM-9999')
        ->and(EmployeeNumber::generate(CarbonImmutable::parse('2026-06-27'), 'CAV', true))->toMatch('/^260627CAV-\d{4}-RH$/');
});

it('lists every province and Metro Manila once, each with three unique letters', function () {
    // 82 ISO 3166-2:PH provinces + Metro Manila (NCR). Update when a province is created or split.
    expect(Provinces::ALL)->toHaveCount(83)
        ->and(array_unique(Provinces::ALL))->toHaveCount(83)
        ->and(Provinces::name('NCR'))->toBe('Metro Manila');

    foreach (Provinces::codes() as $code) {
        expect($code)->toMatch('/^[A-Z]{3}$/');
    }
});

it('keeps an old-format number valid until it is changed', function () {
    $profile = EmployeeProfile::factory()->create(['employee_number' => 'EMP-1001']);

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')->set('form.name', 'Renamed')->call('save')->assertHasNoErrors()
        ->call('edit')->call('useNumberFrom', 'card')->set('form.employee_number', 'EMP-1002')->call('save')->assertHasErrors(['form.employee_number']);

    expect($profile->fresh()?->employee_number)->toBe('EMP-1001');
});

it('gives each person one role, keeping Employee automatic', function () {
    $superAdmin = staff(Role::SuperAdmin);
    $clerk = staff(Role::Hr);

    // HR → Accounting: the role is replaced, Employee stays; "Employee" isn't a choice.
    Livewire::actingAs($superAdmin)->test(RolesAccess::class)
        ->call('edit', $clerk->id)
        ->assertSet('jobRole', 'hr')
        ->assertSee('No extra role')
        ->assertDontSee('role-employee')
        ->set('jobRole', 'accounting')->call('save')->assertHasNoErrors();
    expect($clerk->fresh()?->roleList())->toEqualCanonicalizing([Role::Employee, Role::Accounting]);

    // No extra role: a regular employee.
    Livewire::actingAs($superAdmin)->test(RolesAccess::class)->call('edit', $clerk->id)->set('jobRole', '')->call('save')->assertHasNoErrors();
    expect($clerk->fresh()?->roleList())->toBe([Role::Employee]);

    // An account from before, with two job roles: warned, and saving keeps the one picked.
    $both = staff(Role::Hr, Role::Admin);
    Livewire::actingAs($superAdmin)->test(RolesAccess::class)
        ->call('edit', $both->id)
        ->assertSee('has 2 roles now')
        ->set('jobRole', 'admin')->call('save')->assertHasNoErrors();
    expect($both->fresh()?->roleList())->toEqualCanonicalizing([Role::Employee, Role::Admin]);

    // An account without an employee record never gets Employee.
    $president = staff(Role::President);
    Livewire::actingAs($superAdmin)->test(RolesAccess::class)->call('edit', $president->id)->set('jobRole', 'vice-president')->call('save')->assertHasNoErrors();
    expect($president->fresh()?->roleList())->toBe([Role::VicePresident]);

    // Not a real role: refused.
    Livewire::actingAs($superAdmin)->test(RolesAccess::class)->call('edit', $clerk->id)->set('jobRole', 'employee')->call('save')->assertHasErrors('jobRole');
});

it('leaves what each role can open exactly as it was', function () {
    // One role per person changes the picker, not the access: HR still reviews guards' leave,
    // Operation HQ still manages clients and relievers, Accounting still runs payroll.
    expect(Ability::ReviewFieldLeave->roles())->toContain(Role::Hr)
        ->and(Ability::ManageClients->roles())->toBe([Role::OperationsHq, Role::Admin, Role::SuperAdmin])
        ->and(Ability::ManageRelievers->roles())->toContain(Role::OperationsHq)
        ->and(Ability::ManagePayroll->roles())->toBe([Role::Accounting, Role::SuperAdmin]);
});
