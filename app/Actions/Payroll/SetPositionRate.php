<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\Position;
use App\Models\PositionRate;
use App\Models\User;
use App\Queries\Payroll\FrozenPayrollUntilQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sets the company's daily rate for a position from a date. It
 * applies to everyone in that position without a personal rate. The same
 * date again replaces that row (a typo fix); a date inside an already
 * generated period is refused, so frozen payslips stay true.
 */
final class SetPositionRate
{
    public function __construct(
        private readonly FrozenPayrollUntilQuery $frozen,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Position $position, float $dailyRate, CarbonImmutable $effectiveFrom, ?string $note, User $actor): PositionRate
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if ($dailyRate <= 0 || $dailyRate > 100000) {
            throw ValidationException::withMessages(['rateAmount' => 'Enter a daily rate between ₱1 and ₱100,000.']);
        }

        if (($tooEarly = $this->frozen->tooEarly($effectiveFrom)) !== null) {
            throw ValidationException::withMessages(['rateEffectiveFrom' => $tooEarly]);
        }

        return DB::transaction(function () use ($position, $dailyRate, $effectiveFrom, $note, $actor) {
            $rate = PositionRate::query()
                ->where('position', $position->value)
                ->whereDate('effective_from', $effectiveFrom->toDateString())
                ->lockForUpdate()
                ->first() ?? new PositionRate;

            $previous = $rate->exists ? (float) $rate->daily_rate : null;

            $rate->forceFill([
                'position' => $position,
                'daily_rate' => number_format($dailyRate, 2, '.', ''),
                'effective_from' => $effectiveFrom->toDateString(),
                'note' => filled($note) ? $note : null,
                'set_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'position_rate.set', "{$position->label()} rate: ₱".number_format($dailyRate, 2)." from {$effectiveFrom->format('M j, Y')}", $rate, [
                'old' => ['daily_rate' => $previous],
                'new' => ['position' => $position->value, 'daily_rate' => $dailyRate, 'effective_from' => $effectiveFrom->toDateString()],
                'note' => $rate->note,
            ], causer: $actor);

            return $rate;
        });
    }
}
