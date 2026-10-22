<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePayrollRun
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $payDate, User $actor): PayrollRun
    {
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['endDate' => 'The period must end on or after its start.']);
        }

        if ($payDate->lt($end)) {
            throw ValidationException::withMessages(['payDate' => 'The pay date cannot be before the period ends.']);
        }

        $overlaps = PayrollRun::query()
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['startDate' => 'This period overlaps an existing payroll run.']);
        }

        return DB::transaction(function () use ($start, $end, $payDate, $actor) {
            $run = PayrollRun::create([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'pay_date' => $payDate->toDateString(),
            ]);

            $this->audit->record(AuditArea::Payroll, 'run.created', "Created payroll run {$run->periodLabel()}", $run, causer: $actor);

            return $run;
        });
    }
}
