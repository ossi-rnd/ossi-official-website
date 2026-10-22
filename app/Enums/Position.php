<?php

namespace App\Enums;

enum Position: string
{
    case SecurityGuard = 'security_guard';
    case SecurityOfficer = 'security_officer';
    case ShiftInCharge = 'shift_in_charge';
    case DetachmentCommander = 'detachment_commander';
    case SecuritySupervisor = 'security_supervisor';
    case ChiefSecurityOfficer = 'chief_security_officer';
    case AdminStaff = 'admin_staff';
    case HrStaff = 'hr_staff';
    case AccountingStaff = 'accounting_staff';
    case ItStaff = 'it_staff';
    // Kept so older records still read, but no longer offered (Position::for). Security Manager
    // (2026-10-22): no role does that job; office jobs follow the roles (Admin, HR, Accounting,
    // IT, Operation HQ, Satellite Operation). The President and Vice President (2026-10-21) are
    // roles on accounts without an HR record, not jobs on one.
    case SecurityManager = 'security_manager';
    case President = 'president';
    case VicePresident = 'vice_president';
    case OperationsHead = 'operations_head';
    case SatelliteOperationsOfficer = 'satellite_operations_officer';

    /**
     * The positions that fit the staff type: guard ranks for field staff,
     * office jobs for the office. The form only offers these. A position is
     * the job (it sets the company rate); access comes from the Role.
     *
     * @return list<self>
     */
    public static function for(bool $isOfficeStaff): array
    {
        return $isOfficeStaff
            ? [self::AdminStaff, self::HrStaff, self::AccountingStaff, self::ItStaff, self::OperationsHead, self::SatelliteOperationsOfficer]
            : [self::SecurityGuard, self::SecurityOfficer, self::ShiftInCharge, self::DetachmentCommander, self::SecuritySupervisor, self::ChiefSecurityOfficer];
    }

    /**
     * Every position that can be given today (office and field), without the retired ones.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [...self::for(true), ...self::for(false)];
    }

    /** Every position that isn't a guard rank (including the retired President and Vice President). */
    public function isOffice(): bool
    {
        return ! in_array($this, self::for(false), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ShiftInCharge => 'Shift-in-Charge',
            // Office jobs without "Staff": the form already says "Office staff".
            self::AdminStaff => 'Admin',
            self::HrStaff => 'HR',
            self::AccountingStaff => 'Accounting',
            self::ItStaff => 'IT',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }
}
