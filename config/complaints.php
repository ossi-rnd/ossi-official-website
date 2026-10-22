<?php

return [
    /*
     * Proof attached to complaints (screenshots, photos, documents): confidential,
     * on a private disk. `?:`, not a default: an empty COMPLAINT_FILES_DISK= must
     * also fall back. On Laravel Cloud this is the bucket, like attendance photos.
     */
    'files_disk' => env('COMPLAINT_FILES_DISK') ?: env('ATTENDANCE_PHOTO_DISK', 'local'),

    /* How many files one complaint can carry. Several pages go in one PDF. */
    'max_files' => 5,

    /*
     * Starting value only: HR sets it in the app (Complaints → Rules,
     * SaveComplaintRules), read through App\Support\ComplaintRules. How many
     * complaints one employee can file in 7 days (the roadmap's "1 complaint per
     * week"); 0 = no limit. Never counts help requests, harassment, or cases
     * opened by a resignation.
     */
    'per_week' => 1,
];
