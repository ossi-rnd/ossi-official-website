<?php

use App\Enums\Role;
use App\Livewire\Admin\AttendanceLog;
use App\Livewire\Admin\Employees;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Operations looks after guards: Operation HQ (no view-office-staff) sees no office staff
// anywhere, the Employees list, its filter, the Attendance Log or a record by its link.
// HR (and everyone with view-office-staff) still sees both.

beforeEach(function () {
    // The account and the HR record carry the same name, as in real life.
    $this->guard = employee(['name' => 'Gina Guard']);
    $this->guard->forceFill(['name' => 'Gina Guard'])->save();
    $this->clerk = staff(Role::Accounting);
    $this->clerk->forceFill(['name' => 'Olive Office'])->save();
    $this->clerk->employeeProfile?->forceFill(['name' => 'Olive Office'])->save();
});

it('shows Operation HQ guards only, and HR both', function () {
    $hq = staff(Role::OperationsHq);

    Livewire::actingAs($hq)->test(Employees::class)
        ->assertSee('Gina Guard')->assertDontSee('Olive Office')->assertDontSee('Office staff (no client)');
    $this->actingAs($hq)->get(route('employees.show', $this->clerk->employeeProfile))->assertForbidden();

    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)
        ->assertSee('Gina Guard')->assertSee('Olive Office')->assertSee('Office staff (no client)');
});

it('keeps office staff\'s clock-ins off Operation HQ\'s Attendance Log', function () {
    foreach ([$this->guard, $this->clerk] as $person) {
        TimeLog::factory()->on(CarbonImmutable::today())->create(['user_id' => $person->id]);
    }

    Livewire::actingAs(staff(Role::OperationsHq))->test(AttendanceLog::class)->assertSee('Gina Guard')->assertDontSee('Olive Office');
    Livewire::actingAs(staff(Role::Hr))->test(AttendanceLog::class)->assertSee('Gina Guard')->assertSee('Olive Office');
});
