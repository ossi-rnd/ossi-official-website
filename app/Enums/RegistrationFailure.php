<?php

namespace App\Enums;

/**
 * Why a registration attempt was refused. Written to the audit log for HR,
 * NEVER shown to the person registering: telling a stranger "that employee
 * number exists but the email is wrong" would help them guess real numbers.
 */
enum RegistrationFailure: string
{
    case UnknownEmployeeNumber = 'unknown_employee_number';
    case EmailMismatch = 'email_mismatch';
    case AlreadyClaimed = 'already_claimed';
    case NotActive = 'not_active';
    case EmailTaken = 'email_taken';
}
