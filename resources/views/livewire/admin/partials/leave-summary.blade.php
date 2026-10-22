{{-- A leave request and the way up so far (endorsements), for whoever endorses or decides it. --}}
<div class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-800/50">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p><x-person-link :user="$leave->user" /></p>
            <p class="text-xs text-gray-500">{{ $leave->user->employeeProfile?->assignmentLabel() ?? $leave->user->email }}</p>
        </div>
        <x-ui.badge>{{ $leave->type->label() }}</x-ui.badge>
    </div>
    <p class="mt-2 flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><x-icon name="calendar" class="h-3.5 w-3.5" /> {{ $leave->dateRangeLabel() }} · {{ $leave->calendarDaysLabel() }}</p>
    @if ($leave->type->entitlement())
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $leave->type->law() }}: {{ $leave->type->entitlement() }}.@if ($leave->exceedsEntitlement()) <span class="font-medium text-gray-700 dark:text-gray-200">This asks for more ({{ $leave->calendarDaysLabel() }}).</span>@endif</p>
    @endif
    <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $leave->reason }}</p>

    {{-- The proof: opened only by the employee and whoever decides (LeaveRequest::documentsVisibleTo). --}}
    @if ($leave->documentNeeded() || $leave->documents->isNotEmpty())
        <div class="mt-3 border-t border-gray-200 pt-2 text-xs dark:border-gray-700">
            <p class="font-medium text-gray-700 dark:text-gray-200">{{ $leave->documentNeeded() ?? 'Documents' }}</p>
            @if ($leave->documents->isEmpty())
                <p class="mt-0.5 text-gray-500 dark:text-gray-400">Not attached yet.</p>
            @elseif ($leave->documentsVisibleTo(auth()->user()))
                @foreach ($leave->documents as $document)
                    <a href="{{ route('leave.document', $document) }}" target="_blank" class="mt-0.5 flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="arrow-top-right-on-square" class="h-3.5 w-3.5" /> {{ $document->name ?? 'Document' }}</a>
                @endforeach
            @else
                <p class="mt-0.5 text-gray-500 dark:text-gray-400">Attached ({{ $leave->documents->count() }}). Only whoever decides it can open it.</p>
            @endif
        </div>
    @endif
</div>

@if ($leave->endorsements->isNotEmpty())
    {{-- The way up so far, like the Leave Requests table: the verdict as a word, no icon;
         red on "Not recommended" alone. --}}
    <ol class="mt-3 space-y-2.5">
        @foreach ($leave->endorsements as $endorsement)
            <li class="text-sm leading-tight">
                <span class="block text-gray-800 dark:text-gray-100">{{ $endorsement->stage->label() }} · {{ $endorsement->endorser->name }}</span>
                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                    @if ($endorsement->recommends)
                        Endorsed
                    @else
                        <span class="font-medium text-red-600 dark:text-red-400">Not recommended</span>
                    @endif
                    · {{ $endorsement->created_at->format('M j, g:i A') }}{{ $endorsement->note ? ' · '.$endorsement->note : '' }}
                </span>
            </li>
        @endforeach
    </ol>
@endif
