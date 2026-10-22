<?php

namespace App\Queries\People;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * The people picker in "Give a merit or demerit" and "Issue a notice": up to 8
 * people this viewer may act on (the $canAct rule), by name or employee number.
 * With nothing typed yet it suggests some: the people at the client site of the
 * viewer's last record (the usual case is the same post again), or else the
 * first of the people they supervise (a Satellite Operation: its region's).
 */
final class PeopleToActOnQuery
{
    public const LIMIT = 8;

    /**
     * @param  Closure(User): bool  $canAct  e.g. fn (User $user) => MeritRecord::canGive($viewer, $user)
     * @param  int|null  $lastClientId  the client of the viewer's last record, for suggestions
     * @return Collection<int, User>
     */
    public function handle(User $viewer, string $search, Closure $canAct, ?int $lastClientId = null): Collection
    {
        $people = $this->find($viewer, trim($search), $canAct, $lastClientId);

        // Nobody left at that site to suggest: the usual list instead.
        return $people->isEmpty() && $lastClientId !== null && mb_strlen(trim($search)) < 2
            ? $this->find($viewer, '', $canAct, null)
            : $people;
    }

    /**
     * @param  Closure(User): bool  $canAct
     * @return Collection<int, User>
     */
    private function find(User $viewer, string $search, Closure $canAct, ?int $lastClientId): Collection
    {
        $typed = mb_strlen($search) >= 2;

        return User::query()
            ->onPayroll()
            ->visibleTo($viewer)
            ->whereKeyNot($viewer->id)
            ->when($typed, fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('employeeProfile', fn ($profile) => $profile->where('employee_number', 'like', "%{$search}%"))))
            ->when(! $typed && $lastClientId !== null, fn ($query) => $query->whereHas('employeeProfile', fn ($profile) => $profile->where('client_id', $lastClientId)))
            ->with(['employeeProfile.client', 'roles'])
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->filter($canAct)
            ->take(self::LIMIT)
            ->values();
    }
}
