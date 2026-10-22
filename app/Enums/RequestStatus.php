<?php

namespace App\Enums;

/**
 * Shared by leave and overtime requests. Only leave can be Cancelled
 * (by the employee, while still pending).
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Pending, self::Approved];
    }
}
