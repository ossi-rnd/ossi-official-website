<?php

namespace App\Models\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A request (leave, overtime) that goes to different reviewers depending on
 * who files it: office staff's to the President or Vice President, guards' to HR / Operations.
 * Office staff's requests are seen only by those who decide them; everyone
 * who monitors sees the guards'.
 *
 * The model says which gate decides each kind in reviewAbilities().
 *
 * @phpstan-require-extends Model
 */
trait RoutedByStaffKind
{
    /**
     * @return array{office: Ability, field: Ability}
     */
    abstract public static function reviewAbilities(): array;

    /** The gate that decides a request filed by this person. */
    public static function reviewAbilityFor(User $requester): Ability
    {
        return static::reviewAbilities()[$requester->isOfficeStaff() ? 'office' : 'field'];
    }

    /**
     * The requests this viewer may see in the list: within their region, and
     * office staff's only for those who decide them (the President or Vice President).
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereHas('user', fn (Builder $user) => $user->visibleTo($viewer));

        if (! $viewer->can(static::reviewAbilities()['office']->value)) {
            $query->whereHas('user', fn (Builder $user) => $user->fieldStaff());
        }
    }

    /**
     * Pending requests this viewer can decide (their sidebar badge): not their
     * own, and only the kinds their gates cover.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAwaitingReviewBy(Builder $query, User $viewer): void
    {
        $office = $viewer->can(static::reviewAbilities()['office']->value);
        $field = $viewer->can(static::reviewAbilities()['field']->value);

        $query->pending()->where('user_id', '!=', $viewer->id)->visibleTo($viewer);

        match (true) {
            $office && $field => null,
            $office => $query->whereHas('user', fn (Builder $user) => $user->officeStaff()),
            $field => $query->whereHas('user', fn (Builder $user) => $user->fieldStaff()),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
