<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\ThirteenthMonthStatus;
use App\Models\ThirteenthMonthRun;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft 13th month run and computes it. One draft per year at a
 * time, and a balance run must cover more of the year than the approved ones.
 */
final class CreateThirteenthMonthRun
{
    public function __construct(
        private readonly RecomputeThirteenthMonthRun $recompute,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(int $year, CarbonImmutable $coversUntil, CarbonImmutable $payDate, User $actor): ThirteenthMonthRun
    {
        if ($coversUntil->year !== $year) {
            throw ValidationException::withMessages(['coversUntil' => "Pick a date in {$year}."]);
        }

        return DB::transaction(function () use ($year, $coversUntil, $payDate, $actor) {
            $runs = ThirteenthMonthRun::query()->where('year', $year)->lockForUpdate()->get();

            if ($runs->contains(fn (ThirteenthMonthRun $run) => $run->status === ThirteenthMonthStatus::Draft)) {
                throw ValidationException::withMessages(['year' => "There is already a draft for {$year}. Approve or delete it first."]);
            }

            $lastCovered = $runs->max(fn (ThirteenthMonthRun $run) => $run->covers_until->toDateString());

            if (is_string($lastCovered) && $coversUntil->toDateString() <= $lastCovered) {
                throw ValidationException::withMessages(['coversUntil' => 'An approved run already covers up to '.CarbonImmutable::parse($lastCovered)->format('M j, Y').'. A balance run must cover later than that.']);
            }

            $run = new ThirteenthMonthRun;
            $run->forceFill(['year' => $year, 'covers_until' => $coversUntil->toDateString(), 'pay_date' => $payDate->toDateString(), 'created_by' => $actor->id])->save();

            $count = $this->recompute->fill($run);

            $this->audit->record(AuditArea::Payroll, 'thirteenth_month.created', "Created {$run->label()} for {$count} employee(s)", $run, [
                'total' => (float) $run->payouts()->sum('amount'),
            ], causer: $actor);

            return $run;
        });
    }
}
