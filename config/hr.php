<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Probation
    |--------------------------------------------------------------------------
    |
    | Probation may not exceed six months from the start of work (Labor Code
    | Art. 296). HR is told `remind_days_before` the end and again on the day,
    | so the decision (regular or not) is made before the deadline passes.
    |
    */

    'probation' => [
        'months' => 6,
        'remind_days_before' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | 201 file documents
    |--------------------------------------------------------------------------
    |
    | Scans of NBI clearances and licenses are personal data (RA 10173), so
    | they share the PRIVATE disk of punch selfies unless told otherwise. On
    | Laravel Cloud that must be the bucket: the app disk is wiped on deploy.
    |
    | Expiry alerts go out this many days before a document lapses, and once
    | more on the day. Each one is sent once per expiry date.
    |
    */

    'documents' => [
        // `?:`, not a default: "HR_DOCUMENTS_DISK=" (empty) must also fall back.
        'disk' => env('HR_DOCUMENTS_DISK') ?: env('ATTENDANCE_PHOTO_DISK', 'local'),
        'max_kilobytes' => 10 * 1024,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'expiry_alert_days' => [60, 30, 7, 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Referrals (hiring perks)
    |--------------------------------------------------------------------------
    |
    | Starting values only: HR sets the real ones on the Referrals page
    | (SaveReferralTerms, audited). The bonus is paid on the referrer's payslip
    | once the person they referred has stayed this many days from the hire date.
    | Both numbers are placeholders, not company policy: set them in the app.
    |
    */

    'referrals' => [
        'bonus_amount' => 1000,
        'days_to_stay' => 90,
    ],

];
