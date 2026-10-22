<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin's document library
    |--------------------------------------------------------------------------
    |
    | Company documents, licenses and client / legal documents. Files go on a
    | PRIVATE disk and are only streamed to manage-documents after a check.
    | On Laravel Cloud that must be the bucket: the app disk is wiped on deploy.
    |
    */

    'disk' => env('COMPANY_DOCUMENTS_DISK') ?: env('HR_DOCUMENTS_DISK') ?: env('ATTENDANCE_PHOTO_DISK', 'local'),

    'max_kilobytes' => 10 * 1024,

    // Due-date alerts to Admin, in days before the date (0 = on the day).
    // Only the nearest is sent, once per document and due date; 30 days or
    // less also goes by email. An empty list switches them off.
    'expiry_alert_days' => [60, 30, 7, 0],

];
