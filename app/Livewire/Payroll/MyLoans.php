<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithUser;
use App\Queries\Payroll\CashBondBalanceQuery;
use App\Queries\Payroll\MyLoansQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * My Loans (roadmap 13): every loan, cash advance and company deduction, how much
 * is left, how it was authorized (Art. 113), and what each payslip took; plus the
 * cash bond held. Read-only: Accounting adds them; the employee authorizes them on
 * My Payslips.
 *
 * @phpstan-import-type Loan from MyLoansQuery
 * @phpstan-import-type CashBond from CashBondBalanceQuery
 *
 * @property-read list<Loan> $loans
 * @property-read CashBond|null $cashBond
 */
#[Title('My Loans')]
class MyLoans extends Component
{
    use InteractsWithUser;

    /**
     * @return list<Loan>
     */
    #[Computed]
    public function loans(): array
    {
        return app(MyLoansQuery::class)->handle($this->currentUser());
    }

    /**
     * @return CashBond|null
     */
    #[Computed]
    public function cashBond(): ?array
    {
        $bond = app(CashBondBalanceQuery::class)->handle([$this->currentUser()->id])[$this->currentUser()->id] ?? null;

        return $bond !== null && ($bond['balance'] > 0 || $bond['per_cutoff'] > 0) ? $bond : null;
    }

    public function render(): View
    {
        return view('livewire.payroll.my-loans');
    }
}
