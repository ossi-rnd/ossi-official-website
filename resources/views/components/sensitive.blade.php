{{--
    Wraps personal data (payslips, the 201 file). A web page cannot stop a
    screenshot or a second phone camera, so this makes leaks traceable and
    casual copying harder instead:

    1. A faint watermark with the viewer's name, ID and the time, so a
       screenshot shows who took it.
    2. Printing is blanked (see [data-sensitive] in app.css).
    3. The content is covered while the window is out of focus, which is
       what most desktop snipping tools cause.

    The covering is an overlay with backdrop-blur, never a filter on this
    element: a filter would break the position: fixed of any modal inside.
--}}
@php
    /** @var \App\Models\User $viewer */
    $viewer = auth()->user();
    $viewer->loadMissing('employeeProfile');
    $stamp = $viewer->name.' · '.($viewer->employeeProfile->employee_number ?? $viewer->email).' · '.now()->format('M j, Y g:i A');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="160"><text x="0" y="100" transform="rotate(-20 160 80)" '
        .'font-family="system-ui, sans-serif" font-size="12" fill="rgb(100,116,139)" fill-opacity="0.16">'.e($stamp).'</text></svg>';
@endphp

<div {{ $attributes->class('relative') }} data-sensitive
    x-data="{ covered: false }"
    x-on:blur.window="covered = true" x-on:focus.window="covered = false"
    x-on:visibilitychange.document="covered = document.hidden">
    {{ $slot }}

    <div aria-hidden="true" class="pointer-events-none absolute inset-0 z-10 select-none"
        style="background-image: url('data:image/svg+xml,{{ rawurlencode($svg) }}')"></div>

    <div x-show="covered" x-cloak x-on:click="covered = false"
        class="absolute inset-0 z-20 flex items-start justify-center rounded-xl bg-white/70 pt-24 backdrop-blur-md dark:bg-gray-950/70">
        <p class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-lg">Hidden while this window is not in focus. Click to show.</p>
    </div>
</div>
