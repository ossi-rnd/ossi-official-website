<?php

namespace App\Enums;

/**
 * Why the company may take a deduction from pay (Labor Code Art. 113): the
 * employee's written authorization (in the app, or a signed paper), or the law
 * itself (SSS and Pag-IBIG salary loans, which the employer must deduct).
 */
enum DeductionAuthorizationBasis: string
{
    // Asked in the app: nothing is taken until they tick "I authorize".
    case Employee = 'employee';
    case Paper = 'paper';
    case Law = 'law';

    /** What Accounting picks when adding one. */
    public function choice(): string
    {
        return match ($this) {
            self::Employee => 'Ask them to authorize it in the app',
            self::Paper => 'They signed an authorization on paper',
            self::Law => 'Government loan (SSS or Pag-IBIG): deducted by law',
        };
    }

    /** The same, as the employee reads it on My Loans. */
    public function labelForEmployee(): string
    {
        return match ($this) {
            self::Employee => 'You authorized it in the app',
            self::Paper => 'You signed an authorization on paper',
            self::Law => 'Government loan, deducted by law',
        };
    }

    /** What the record says, once authorized. */
    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Authorized by them in the app',
            self::Paper => 'Signed on paper',
            self::Law => 'Government loan, by law',
        };
    }
}
