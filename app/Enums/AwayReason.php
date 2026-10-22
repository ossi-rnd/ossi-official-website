<?php

namespace App\Enums;

/**
 * Why someone left their post (or the office) during work. The person says how
 * long they'll be (nothing is suggested, so a "usual" time doesn't become the
 * plan) and can add time while away. There is no meal break here: it's already in the duty.
 */
enum AwayReason: string
{
    case Patrol = 'patrol';
    case OfficialBusiness = 'official_business';
    case GovernmentOffice = 'government_office';
    case ClientSiteVisit = 'client_site_visit';
    case ClientErrand = 'client_errand';
    case SupervisorCalled = 'supervisor_called';
    case Appointment = 'appointment';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Patrol => 'Checking other posts',
            self::OfficialBusiness => 'Official business',
            self::GovernmentOffice => 'Government office',
            self::ClientSiteVisit => 'Client site visit',
            self::ClientErrand => 'Errand for the client',
            self::SupervisorCalled => 'Called by supervisor',
            self::Appointment => 'Appointment',
            self::Personal => 'Personal',
        };
    }

    /**
     * The reasons a guard picks from.
     *
     * @return list<self>
     */
    public static function forField(): array
    {
        return [self::Patrol, self::ClientErrand, self::SupervisorCalled, self::Appointment, self::Personal];
    }

    /**
     * The reasons office staff pick from. Official business: the bank, a client
     * meeting. Client site visit: a post or detachment (inspecting guards, delivering
     * uniforms or payroll, an incident). Government office: SSS, PhilHealth, Pag-IBIG,
     * BIR, DOLE, PNP-SOSIA. Each its own reason because it takes longer than the bank,
     * and the President or Vice President reads (and filters) them differently.
     *
     * @return list<self>
     */
    public static function forOffice(): array
    {
        return [self::OfficialBusiness, self::ClientSiteVisit, self::GovernmentOffice, self::Appointment, self::Personal];
    }

    /**
     * What the note asks for. Work trips: where and what for, which is what the approver
     * needs. Personal ones: where only. A medical appointment's purpose is sensitive
     * personal information (RA 10173), and nobody needs it to approve a trip out.
     * "Who" only where someone sent them.
     */
    public function noteLabel(): string
    {
        return match ($this) {
            self::OfficialBusiness => 'Where, and what for',
            self::ClientSiteVisit => 'Which site, and what for',
            self::GovernmentOffice => 'Which office, and what for',
            self::Appointment => 'Where (no need to say what for)',
            self::Personal => 'Where',
            self::ClientErrand => 'Who asked, and what for',
            self::SupervisorCalled => 'Who called you',
            self::Patrol => 'Which posts',
        };
    }

    public function noteExample(bool $isOfficeStaff): string
    {
        return 'e.g. '.match ($this) {
            self::OfficialBusiness => 'BDO Ortigas, depositing payroll',
            self::ClientSiteVisit => 'Makati CBD, inspecting Gate 2',
            self::GovernmentOffice => 'SSS Pasig, remitting contributions',
            self::Appointment => $isOfficeStaff ? 'Robinsons Galleria' : 'Glorietta, Makati',
            self::Personal => $isOfficeStaff ? 'ATM at SM Megamall' : 'Store across the street',
            self::ClientErrand => 'Building admin, receiving a delivery',
            self::SupervisorCalled => 'Sgt. Reyes, at the detachment',
            self::Patrol => 'Gate 2 and Parking B',
        };
    }

    /** Where and why must be written down: everything but a guard's routine reasons. */
    public function needsNote(): bool
    {
        return ! in_array($this, [self::Patrol, self::ClientErrand, self::SupervisorCalled], true);
    }

    /**
     * Personal time rather than work: past SUGGEST_LEAVE_AFTER_MINUTES it's leave.
     * A full day at a site or at PNP-SOSIA is still work, so official reasons never get the hint.
     */
    public function isPersonal(): bool
    {
        return in_array($this, [self::Appointment, self::Personal], true);
    }

    /**
     * @return list<self>
     */
    public static function for(bool $isOfficeStaff): array
    {
        return $isOfficeStaff ? self::forOffice() : self::forField();
    }

    /**
     * The usual length: a fallback only, for absences recorded without a time
     * (older rows, LeavePost called without one). Never shown when stepping out:
     * "usually 2 hrs" turned a 20-minute errand into 2 hours. Set for Metro Manila
     * travel and queues.
     */
    public function usualMinutes(): int
    {
        return match ($this) {
            self::GovernmentOffice, self::ClientSiteVisit => 180,
            self::OfficialBusiness, self::Appointment => 120,
            self::ClientErrand => 60,
            self::Patrol, self::SupervisorCalled, self::Personal => 30,
        };
    }

    /**
     * Past this, a personal reason is time off rather than a trip out: the dialog suggests filing leave.
     */
    public const SUGGEST_LEAVE_AFTER_MINUTES = 240;

    /** "30 min", "1 hr", "1 hr 30 min", "2 hrs" */
    public static function durationLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$minutes} min",
            $rest === 0 => $hours === 1 ? '1 hr' : "{$hours} hrs",
            default => ($hours === 1 ? '1 hr' : "{$hours} hrs")." {$rest} min",
        };
    }
}
