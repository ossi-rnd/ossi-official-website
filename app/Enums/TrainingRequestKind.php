<?php

namespace App\Enums;

/** What a guard asks to be trained in (My Training). */
enum TrainingRequestKind: string
{
    case FirearmsRefresher = 'firearms_refresher';
    case LicenseRenewal = 'license_renewal';
    case PreLicensing = 'pre_licensing';
    case FirstAid = 'first_aid';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FirearmsRefresher => 'Firearms refresher',
            self::LicenseRenewal => 'Training for license renewal',
            self::PreLicensing => 'Pre-licensing course',
            self::FirstAid => 'First aid',
            self::Other => 'Other',
        };
    }
}
