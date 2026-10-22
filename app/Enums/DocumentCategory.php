<?php

namespace App\Enums;

/**
 * Admin's document library, one tab each. Any of them may carry an expiry
 * date; licenses are the ones that usually do (PNP-SOSIA license to operate,
 * business permit, firearms registration).
 */
enum DocumentCategory: string
{
    case Company = 'company';
    case License = 'license';
    case ClientLegal = 'client_legal';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company documents',
            self::License => 'Licenses',
            self::ClientLegal => 'Client & legal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Company => 'Registration papers, certificates and other company files.',
            self::License => 'Licenses and permits with their due date. Alerts go out 60, 30 and 7 days before, and on the day.',
            self::ClientLegal => 'Client contracts, service agreements and legal documents.',
        };
    }

    /** What "Add" says on the tab. */
    public function singular(): string
    {
        return match ($this) {
            self::Company => 'document',
            self::License => 'license',
            self::ClientLegal => 'client or legal document',
        };
    }
}
