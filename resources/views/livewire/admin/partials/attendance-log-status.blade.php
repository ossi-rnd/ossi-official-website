{{-- A shift's status: On duty / Not clocked out (by the shift's end) / its time-log status. Needs $log, $notClockedOut. --}}
@if ($log->isOpen() && ! $notClockedOut)
    <x-ui.badge tone="green">On duty</x-ui.badge>
@elseif ($notClockedOut)
    <x-ui.badge tone="amber">Not clocked out</x-ui.badge>
    <span class="mt-0.5 block text-xs whitespace-nowrap text-gray-500">Shift ended {{ $this->shiftEnd($log)->format('g:i A') }}</span>
@else
    <x-ui.status-badge :status="$log->status" />
@endif
@if ($log->isManual())
    <span class="mt-0.5 block text-xs text-gray-500">Entered by Operations</span>
@endif
