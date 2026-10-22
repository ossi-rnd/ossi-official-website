<?php

namespace App\Enums;

/**
 * The roles. Stored as strings in `user_roles.role`; a user may hold several.
 *
 * Presentation (label, colour, description) lives here too, so a new role is a
 * one-file change. Access itself is decided by Ability, never by checking a
 * role name in a view or component.
 */
enum Role: string
{
    case SuperAdmin = 'super-admin';
    // The company's leadership (was one "Executive" role until 2026-10-20). Same access for both:
    // either one approves, so a request doesn't wait while one is away. See leadership().
    case President = 'president';
    case VicePresident = 'vice-president';
    case Admin = 'admin';
    case Hr = 'hr';
    case Accounting = 'accounting';
    case OperationsHq = 'operations-hq';
    case SatelliteOperations = 'satellite-operations';
    case Developer = 'developer';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::President => 'President',
            self::VicePresident => 'Vice President',
            self::Admin => 'Admin',
            self::Hr => 'HR',
            self::Accounting => 'Accounting',
            self::OperationsHq => 'Operation HQ',
            self::SatelliteOperations => 'Satellite Operation',
            self::Developer => 'Developer',
            self::Employee => 'Employee',
        };
    }

    /**
     * A tone name for <x-ui.badge>. Tailwind classes stay in Blade, because
     * Tailwind only scans the views for class names.
     */
    public function tone(): string
    {
        return match ($this) {
            self::SuperAdmin => 'purple',
            self::President, self::VicePresident => 'orange',
            self::Admin => 'blue',
            self::Hr => 'emerald',
            self::Accounting => 'indigo',
            self::OperationsHq => 'sky',
            self::SatelliteOperations => 'teal',
            self::Developer => 'amber',
            self::Employee => 'gray',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full system control: every feature, system settings, holidays, clients, roles and the audit log.',
            self::President => 'Leads the company: monitors everything, approves office staff\'s leave, overtime and reimbursements, signs final pay. Paid outside this payroll.',
            self::VicePresident => 'The same access as the President, so either one can approve. Paid outside this payroll.',
            self::Admin => 'Sets up clients; company documents, licenses, client and legal documents, guard training, holidays and the employee list.',
            self::Hr => 'Onboards employees, keeps their records, approves guards\' leave, and handles complaints.',
            self::Accounting => 'Runs payroll, daily rates and 13th month pay, approves borrowed load, and views the employee list.',
            self::OperationsHq => 'Head of operations: sets up clients, monitors leave, approves overtime, and finds relievers for absent guards.',
            self::SatelliteOperations => 'One region: monitors it, assigns detachments and posts, changes shifts, finds relievers, approves overtime.',
            self::Developer => 'Reads the audit log for support and debugging. No HR or payroll access.',
            self::Employee => 'Clocks in and out, files leave and overtime, and views their own payslips.',
        };
    }

    /**
     * Whether holding this role shows every employee. Satellite Operation
     * sees only its own region (set on Roles & Access); Developer and
     * Employee see no one else's records at all.
     */
    public function seesWholeCompany(): bool
    {
        return ! in_array($this, [self::SatelliteOperations, self::Developer, self::Employee], true);
    }

    /**
     * Roles paid outside this payroll (the owner/director level): no payslip,
     * no attendance, no DTR row, no daily rate needed, even with an HR record.
     *
     * @return list<self>
     */
    public static function paidOutsidePayroll(): array
    {
        return self::withoutEmployeeRecord();
    }

    /**
     * Accounts that aren't employees here: no HR record, no employee number, no payroll,
     * attendance or headcount (company decision, 2026-10-21). The Super Admin is a system
     * account; the President and Vice President are paid outside this payroll. Someone who
     * is also on payroll keeps a separate, ordinary account for that.
     *
     * @return list<self>
     */
    public static function withoutEmployeeRecord(): array
    {
        return [self::SuperAdmin, ...self::leadership()];
    }

    /**
     * The President and the Vice President: the same access everywhere (they were one
     * "Executive" role). Use this wherever either may act, e.g. `[...Role::leadership(), Role::SuperAdmin]`.
     *
     * @return list<self>
     */
    public static function leadership(): array
    {
        return [self::President, self::VicePresident];
    }

    /** "the President or Vice President": how the screens name who decides. */
    public const LEADERSHIP_LABEL = 'the President or Vice President';
}
