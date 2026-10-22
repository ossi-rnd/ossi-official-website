<?php

use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// IDs beyond SSS, PhilHealth, Pag-IBIG and TIN: rows of name + number on the employee
// record, encrypted like the four government IDs, masked on the employee page.

it('saves other IDs with a new employee, dropping blank rows, encrypted', function () {
    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)
        ->call('create')
        ->assertSee('Other IDs')->assertSee('Add another ID')
        ->set('form.hire_date', '2026-06-27')->set('form.birth_province', 'PAM')
        ->set('form.name', 'Ana Reyes')->set('form.email', 'ana.reyes@example.test')
        ->set('form.other_ids', [
            ['label' => " Driver's License ", 'number' => 'N01-23-456789'],
            ['label' => '', 'number' => ''],
            ['label' => 'Security Guard License (LESP)', 'number' => 'LESP-2026-0042'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $profile = EmployeeProfile::query()->where('name', 'Ana Reyes')->sole();
    expect($profile->other_ids)->toBe([
        ['label' => "Driver's License", 'number' => 'N01-23-456789'],
        ['label' => 'Security Guard License (LESP)', 'number' => 'LESP-2026-0042'],
    ]);

    // Encrypted at rest: the column doesn't hold the numbers in plain text.
    $raw = (string) DB::table('employee_profiles')->where('id', $profile->id)->value('other_ids');
    expect($raw)->not->toContain('N01-23-456789')->not->toContain('LESP');

    // The audit log says they changed, never what they are.
    $entry = ActivityLog::query()->where('event', 'profile.created')->latest('id')->firstOrFail();
    expect(json_encode($entry->properties))->not->toContain('N01-23-456789');
});

it('needs a name with each number and a number with each name', function () {
    $profile = employee()->employeeProfile;

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->set('form.other_ids', [['label' => 'Passport', 'number' => ''], ['label' => '', 'number' => 'P1234567A']])
        ->call('save')
        ->assertHasErrors(['form.other_ids.0.number', 'form.other_ids.1.label']);

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->set('form.other_ids', array_fill(0, EmployeeProfile::MAX_OTHER_IDS + 1, ['label' => 'Passport', 'number' => 'P1']))
        ->call('save')
        ->assertHasErrors('form.other_ids');
});

it('masks other IDs on the employee page and shows them to the employee in full', function () {
    $guard = employee();
    $guard->employeeProfile?->forceFill(['other_ids' => [['label' => 'Passport', 'number' => 'P1234567A']]])->save();

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $guard->employeeProfile])
        ->assertSee('Passport')->assertSee('•••••567A')->assertDontSee('P1234567A')
        // Editing keeps them, and removing the last row saves none.
        ->call('edit')->assertSet('form.other_ids', [['label' => 'Passport', 'number' => 'P1234567A']])
        ->set('form.other_ids', [])
        ->call('save')->assertHasNoErrors();
    expect($guard->employeeProfile?->fresh()?->other_ids)->toBeNull();

    $guard->employeeProfile?->forceFill(['other_ids' => [['label' => 'Passport', 'number' => 'P1234567A']]])->save();
    $this->actingAs($guard)->get(route('profile'))->assertOk()->assertSee('Passport')->assertSee('P1234567A');
});
