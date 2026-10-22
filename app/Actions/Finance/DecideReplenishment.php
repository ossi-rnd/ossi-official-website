<?php

namespace App\Actions\Finance;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ReplenishmentStatus;
use App\Models\PettyCashExpense;
use App\Models\PettyCashReplenishment;
use App\Models\User;
use App\Notifications\PettyCashNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting releases a replenishment (the cash is given back: the fund is whole again)
 * or returns it with a reason (its expenses go back to the custodian's list, to fix or
 * remove and ask again). Never the custodian deciding their own. The custodian is told.
 */
final class DecideReplenishment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PettyCashReplenishment $request, bool $release, ?string $note, User $actor): void
    {
        if (! $actor->can(Ability::ManagePettyCash->value) || $request->requested_by === $actor->id) {
            throw new AuthorizationException;
        }

        if (! $release && blank($note)) {
            throw ValidationException::withMessages(['decisionNote' => 'Say what to fix, so the custodian can ask again.']);
        }

        DB::transaction(function () use ($request, $release, $note, $actor) {
            $locked = PettyCashReplenishment::query()->with(['fund', 'requester'])->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== ReplenishmentStatus::Pending) {
                throw ValidationException::withMessages(['decisionNote' => 'This request was already decided.']);
            }

            $locked->forceFill([
                'status' => $release ? ReplenishmentStatus::Released : ReplenishmentStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            // Returned: its expenses are free again, to fix or remove and ask for again.
            if (! $release) {
                PettyCashExpense::query()->where('petty_cash_replenishment_id', $locked->id)->update(['petty_cash_replenishment_id' => null]);
            }

            $amount = '₱'.number_format((float) $locked->amount, 2);
            $this->audit->record(AuditArea::Finance, $release ? 'petty_cash.replenishment_released' : 'petty_cash.replenishment_returned',
                ($release ? "Released {$amount} to replenish {$locked->fund->name}" : "Returned the {$amount} request for {$locked->fund->name}: {$note}"), $locked, causer: $actor);

            $locked->requester->notify(new PettyCashNotice($locked, $release ? PettyCashNotice::RELEASED : PettyCashNotice::RETURNED));
        });
    }
}
