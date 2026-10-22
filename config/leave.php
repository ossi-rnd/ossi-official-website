<?php

return [
    /*
     * Only the STARTING values: HR sets them on Leave Requests (Rules), saved as
     * the "leave" Setting and audited (App\Support\LeaveRules).
     *
     *  - sick_certificate_from_days: sick leave this long needs a medical certificate before approval
     *  - file_after_days: sick and emergency leave can be filed up to this many days after it started
     */
    'sick_certificate_from_days' => 2,
    'file_after_days' => 7,

    /*
     * Leave documents (medical certificates, birth certificates, VAWC orders):
     * sensitive personal information, on a private disk. `?:`, not a default:
     * an empty LEAVE_DOCUMENTS_DISK= must also fall back.
     */
    'documents_disk' => env('LEAVE_DOCUMENTS_DISK') ?: env('ATTENDANCE_PHOTO_DISK', 'local'),
];
