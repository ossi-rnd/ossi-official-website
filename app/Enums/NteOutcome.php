<?php

namespace App\Enums;

/**
 * The possible penalty named in a Notice to Explain, and the decision in the
 * second notice (which can also be "cleared").
 */
enum NteOutcome: string
{
    case Cleared = 'cleared';
    case Reprimand = 'reprimand';
    case Suspension = 'suspension';
    case Dismissal = 'dismissal';

    public function label(): string
    {
        return match ($this) {
            self::Cleared => 'Cleared: no violation',
            self::Reprimand => 'Written reprimand',
            self::Suspension => 'Suspension without pay',
            self::Dismissal => 'Dismissal',
        };
    }

    /**
     * What a notice can warn of: everything but "cleared".
     *
     * @return list<self>
     */
    public static function penalties(): array
    {
        return [self::Reprimand, self::Suspension, self::Dismissal];
    }

    /** A tone name; Blade owns the classes. */
    public function tone(): string
    {
        return match ($this) {
            self::Cleared => 'blue',
            self::Reprimand => 'gray',
            self::Suspension, self::Dismissal => 'amber',
        };
    }
}
