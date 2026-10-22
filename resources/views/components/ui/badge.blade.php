@props(['tone' => 'gray'])

@php
    // Enums return a tone name (Role::tone()); the classes live here, where Tailwind scans.
    $tones = [
        'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        'blue' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
        'green' => 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'orange' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300',
        'red' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'teal' => 'bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300',
        'indigo' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
        'purple' => 'bg-purple-50 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300',
    ];
@endphp

<span {{ $attributes->class('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap '.($tones[$tone] ?? $tones['gray'])) }}>{{ $slot }}</span>
