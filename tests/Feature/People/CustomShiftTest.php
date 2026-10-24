<?php

use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use Livewire\Livewire;

// HR types an employee's shift hours (no list to pick from): they become the
// shift with exactly those times, reused when one already exists.

it('starts a new employee on the default hours, and saves typed hours as a shift', function () {
    $hr = staff(Role::Hr);
    Shift::query()->create(['name' => 'Day Shift', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10, 'is_default' => true]);

    $page = Livewire::actingAs($hr)->test(Employees::class)
        ->call('create')
        ->assertSee('Shift hours')->assertSee('Common hours')->assertDontSee('Default shift')
        ->assertSet('form.custom_start', '09:00')->assertSet('form.custom_end', '18:00')->assertSet('form.custom_grace', '10');

    $page->set('form.hire_date', '2024-06-01')->set('form.birth_province', 'PAM')
        ->set('form.name', 'Ana Reyes')
        ->set('form.email', 'ana.reyes@example.test')
        ->set('form.custom_start', '07:00')
        ->set('form.custom_end', '16:00')
        ->set('form.custom_grace', '5')
        ->call('save')
        ->assertHasNoErrors();

    $shift = EmployeeProfile::query()->where('email', 'ana.reyes@example.test')->firstOrFail()->shift;
    expect(substr((string) $shift?->start_time, 0, 5))->toBe('07:00')
        ->and(substr((string) $shift?->end_time, 0, 5))->toBe('16:00')
        ->and($shift?->grace_minutes)->toBe(5)
        ->and($shift?->name)->toBe('7:00 AM – 4:00 PM');

    // Same hours on another record: the same shift, no duplicate.
    $other = employee();
    Livewire::actingAs($hr)->test(EmployeeDetail::class, ['profile' => $other->employeeProfile()->firstOrFail()])
        ->call('edit')
        ->set('form.custom_start', '07:00')->set('form.custom_end', '16:00')->set('form.custom_grace', '5')
        ->call('save')->assertHasNoErrors();

    expect($other->employeeProfile()->firstOrFail()->shift_id)->toBe($shift?->id)
        ->and(Shift::query()->where('name', '7:00 AM – 4:00 PM')->count())->toBe(1);
});

it('adds a second employee with other typed hours (every new shift was "Custom" and the second crashed)', function () {
    $hr = staff(Role::Hr);
    // What the pilot had: an old "Custom" shift, then HR typed different hours.
    Shift::query()->create(['name' => 'Custom', 'start_time' => '08:00', 'end_time' => '18:00', 'grace_minutes' => 5]);

    foreach ([['Ana Reyes', '09:00', '18:00', '5'], ['Ben Cruz', '22:00', '07:00', '5'], ['Cora Diaz', '09:00', '18:00', '10']] as [$name, $start, $end, $grace]) {
        Livewire::actingAs($hr)->test(Employees::class)->call('create')
            ->set('form.hire_date', '2024-06-01')->set('form.birth_province', 'PAM')
            ->set('form.name', $name)->set('form.email', str($name)->slug('.').'@example.test')
            ->set('form.custom_start', $start)->set('form.custom_end', $end)->set('form.custom_grace', $grace)
            ->call('save')->assertHasNoErrors();
    }

    expect(Shift::query()->orderBy('id')->pluck('name')->all())
        ->toBe(['Custom', '9:00 AM – 6:00 PM', '10:00 PM – 7:00 AM', '9:00 AM – 6:00 PM · 10 min grace']);
});

it('opens a record on its current hours, and keeps its shift when they\'re unchanged', function () {
    $dayDuty = Shift::query()->create(['name' => 'Day Duty', 'start_time' => '06:00', 'end_time' => '15:00', 'grace_minutes' => 10]);
    $profile = employee()->employeeProfile()->firstOrFail();
    $profile->forceFill(['shift_id' => $dayDuty->id])->save();

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->assertSet('form.custom_start', '06:00')->assertSet('form.custom_end', '15:00')
        ->set('form.contact_number', '0917 000 0000')
        ->call('save')->assertHasNoErrors();

    expect($profile->fresh()?->shift_id)->toBe($dayDuty->id)
        ->and(Shift::query()->count())->toBe(1);
});

it('asks for both times, and refuses a start equal to the end', function () {
    $profile = employee()->employeeProfile()->firstOrFail();

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->set('form.custom_start', '')->set('form.custom_end', '')
        ->call('save')->assertHasErrors(['form.custom_start' => 'required', 'form.custom_end' => 'required'])
        ->set('form.custom_start', '08:00')->set('form.custom_end', '08:00')
        ->call('save')->assertHasErrors(['form.custom_end' => 'different']);
});
