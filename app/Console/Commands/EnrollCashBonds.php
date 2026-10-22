<?php

namespace App\Console\Commands;

use App\Actions\Payroll\EnrollCashBond;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Run once when the cash bond starts in the system: every guard still working
 * here with an account gets the ₱100-a-cut-off deduction. Safe to run again:
 * someone already enrolled is skipped. Starting balances (what they paid in
 * before) are entered on the Cash Bonds page.
 */
final class EnrollCashBonds extends Command
{
    protected $signature = 'app:enroll-cash-bonds';

    protected $description = 'Start the ₱100-a-cut-off cash bond for every guard not yet enrolled';

    public function handle(EnrollCashBond $enroll): int
    {
        $started = 0;

        User::query()->onPayroll()->fieldStaff()->orderBy('id')->each(function (User $guard) use ($enroll, &$started) {
            $started += $enroll->handle($guard) ? 1 : 0;
        });

        $this->info("Started {$started} cash ".str('bond')->plural($started).'.');

        return self::SUCCESS;
    }
}
