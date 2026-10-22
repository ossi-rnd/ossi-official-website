<?php

namespace App\Enums;

/**
 * The `log_name` column of activity_log: which part of the system an entry belongs to.
 */
enum AuditArea: string
{
    case Auth = 'auth';
    case Roles = 'roles';
    case People = 'people';
    case Attendance = 'attendance';
    case Leave = 'leave';
    case Overtime = 'overtime';
    case Payroll = 'payroll';
    case Settings = 'settings';
    case Content = 'content';
    case Load = 'load';
    case Operations = 'operations';
    case Documents = 'documents';
    case Training = 'training';
    case Complaints = 'complaints';
    // Petty cash funds, expenses and replenishments.
    case Finance = 'finance';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
