<?php

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Models\User;
use Livewire\Livewire;

it('offers Trainee as an employment status, first in the list, and counts trainees as active', function () {
    expect(EmploymentStatus::cases()[0])->toBe(EmploymentStatus::Trainee)
        ->and(EmploymentStatus::Trainee->label())->toBe('Trainee')
        ->and(EmploymentStatus::Trainee->isActive())->toBeTrue();

    $profile = employee()->employeeProfile()->firstOrFail();

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->assertSee('Trainee')
        ->set('form.employment_status', 'trainee')
        ->call('save')->assertHasNoErrors();

    // Active: they can still sign in, and they're on payroll.
    expect($profile->fresh()?->employment_status)->toBe(EmploymentStatus::Trainee)
        ->and(User::query()->onPayroll()->whereKey($profile->user_id)->exists())->toBeTrue();
});
