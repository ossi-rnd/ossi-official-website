@props(['user' => null, 'profile' => null, 'profileId' => null, 'name' => null])

@php
    // A person's name that opens their employee record, for viewers who may
    // see records (view-people; a Satellite's region is checked by the page).
    // Pass a User with employeeProfile eager-loaded, an EmployeeProfile, or a
    // profile id + name. No record (e.g. a Super Admin): plain text.
    $profileId ??= $profile?->id ?? $user?->employeeProfile?->id;
    $label = $name ?? $user?->name ?? $profile?->name;
    $canOpen = $profileId !== null && (auth()->user()?->can(\App\Enums\Ability::ViewPeople->value) ?? false);
@endphp

@if ($canOpen)
    <a href="{{ route('employees.show', $profileId) }}" wire:navigate title="View {{ $label }}'s profile"
        {{ $attributes->class('font-medium text-gray-800 underline decoration-gray-400 decoration-dotted underline-offset-4 hover:text-blue-600 hover:decoration-blue-600 dark:text-gray-100 dark:decoration-gray-600 dark:hover:text-blue-400') }}>{{ $label }}</a>
@else
    <span {{ $attributes->class('font-medium text-gray-800 dark:text-gray-100') }}>{{ $label }}</span>
@endif
