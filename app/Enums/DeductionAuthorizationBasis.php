<?php

namespace App\Enums;

/**
 * Why the company may take a deduction from pay (Labor Code Art. 113): the
 * employee's written authorization (in the app, or a signed paper), the law
 * itself (SSS and Pag-IBIG salary loans, which the employer must deduct), or a
 * company item lost or damaged through their fault, decided after they were heard
 * (DOLE's rule on deductions for loss or damage: shown responsible, heard, never
 * more than the actual loss, at most 20% of a week's wages).
 */
enum DeductionAuthorizationBasis: string
{
    // Asked in the app: nothing is taken until they tick "I authorize".
    case Employee = 'employee';
    case Paper = 'paper';
    case Law = 'law';
    // Only from DecideEquipmentIncident, never picked by hand.
    case LossOrDamage = 'loss_or_damage';

    /**
     * What Accounting can pick when adding one by hand.
     *
     * @return list<self>
     */
    public static function choices(): array
    {
        return [self::Employee, self::Paper, self::Law];
    }

    /** What Accounting picks when adding one. */
    public function choice(): string
    {
        return match ($this) {
            self::Employee => 'Ask them to authorize it in the app',
            self::Paper => 'They signed an authorization on paper',
            self::Law => 'Government loan (SSS or Pag-IBIG): deducted by law',
            self::LossOrDamage => 'Company item lost or damaged through their fault, after they were heard',
        };
    }

    /** The same, as the employee reads it on My Loans. */
    public function labelForEmployee(): string
    {
        return match ($this) {
            self::Employee => 'You authorized it in the app',
            self::Paper => 'You signed an authorization on paper',
            self::Law => 'Government loan, deducted by law',
            self::LossOrDamage => 'For a company item lost or damaged, decided after you were heard',
        };
    }

    /** What the record says, once authorized. */
    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Authorized by them in the app',
            self::Paper => 'Signed on paper',
            self::Law => 'Government loan, by law',
            self::LossOrDamage => 'Loss or damage, after due process',
        };
    }
}
