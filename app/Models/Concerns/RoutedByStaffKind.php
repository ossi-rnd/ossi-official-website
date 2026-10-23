<?php

namespace App\Models\Concerns;

use App\Enums\Ability;
use App\Models\User;
use App\Support\Heads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A request (leave, overtime) that goes to different reviewers depending on who files it.
 *
 *   a guard's          the field gate (reviewAbilities()['field']): HR / Operations
 *   office staff's     their head (App\Support\Heads, the owner's chain): HR for Admin and others;
 *                      Operation HQ for a Satellite; the President or VP for HR, Operation HQ, Accounting
 *
 * Office staff's requests are seen by their head, by HR (every non-HR staffer's), and by the
 * President or VP and the Super Admin, who monitor them all. Everyone who monitors sees the guards'.
 *
 * @phpstan-require-extends Model
 */
trait RoutedByStaffKind
{
    /**
     * @return array{office: Ability, field: Ability}
     */
    abstract public static function reviewAbilities(): array;

    /** Whether this viewer decides a request filed by this person (never their own). */
    public static function canDecideFor(User $viewer, User $requester): bool
    {
        $requester->loadMissing('employeeProfile');

        if ($viewer->is($requester)) {
            return false;
        }

        return $requester->isOfficeStaff()
            ? Heads::isHeadOf($viewer, $requester)
            : $viewer->can(static::reviewAbilities()['field']->value) && $viewer->canSeeEmployee($requester);
    }

    /**
     * The requests this viewer may see in the list: within their region; office staff's only
     * for their head, HR, and the President or VP and the Super Admin (who monitor).
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereHas('user', fn (Builder $user) => $user->visibleTo($viewer));

        if (! Heads::seesAllOfficeStaff($viewer)) {
            $query->whereHas('user', fn (Builder $user) => $user->where(fn (Builder $who) => $who->fieldStaff()
                ->orWhere(fn (Builder $office) => Heads::constrainOfficeStaffSeenBy($office, $viewer))));
        }
    }

    /**
     * Pending requests this viewer can decide (their sidebar badge): not their own; guards'
     * where the field gate allows, and office staff's they head.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAwaitingReviewBy(Builder $query, User $viewer): void
    {
        $field = $viewer->can(static::reviewAbilities()['field']->value);

        $query->pending()->where('user_id', '!=', $viewer->id)->visibleTo($viewer)
            ->whereHas('user', fn (Builder $user) => $user->where(fn (Builder $who) => $who
                ->when($field, fn (Builder $who) => $who->fieldStaff(), fn (Builder $who) => $who->whereRaw('1 = 0'))
                ->orWhere(fn (Builder $office) => Heads::constrainOfficeStaffHeadedBy($office, $viewer))));
    }
}
