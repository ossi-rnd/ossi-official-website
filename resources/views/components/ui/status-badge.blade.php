@props(['status'])

@php
    $status = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;

    [$tone, $label] = [
        'on_time' => ['green', 'On Time'],
        'late' => ['amber', 'Late'],
        'undertime' => ['orange', 'Undertime'],
        'late_and_undertime' => ['red', 'Late & Undertime'],
        'incomplete' => ['gray', 'Incomplete'],
        'pending' => ['blue', 'Pending'],
        'not_in' => ['red', 'Not in yet'],
        'absent' => ['red', 'Absent'],
        'not_hired' => ['gray', 'Not yet hired'],
        'upcoming' => ['gray', 'Upcoming'],
        'on_leave' => ['gray', 'On Leave'],
        'holiday' => ['indigo', 'Holiday'],
        'draft' => ['gray', 'Draft'],
        'processing' => ['amber', 'Processing'],
        'approved' => ['green', 'Approved'],
        'paid' => ['green', 'Paid'],
        'rejected' => ['red', 'Rejected'],
        'cancelled' => ['gray', 'Cancelled'],
    ][$status] ?? ['gray', ucfirst(str_replace('_', ' ', $status))];
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-ui.badge>
