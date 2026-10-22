<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile load on credit
    |--------------------------------------------------------------------------
    |
    | An employee requests load; an Admin sends it from the company phone and
    | approves the request, which creates a "Load" deduction for the full
    | amount on the employee's next payslip. Nothing is paid up front.
    |
    | Networks and amounts are lists, so offering a new promo is a config
    | change. Amounts are whole pesos.
    |
    */

    'networks' => [
        'globe' => 'Globe',
        'tm' => 'TM',
        'smart' => 'Smart',
        'tnt' => 'TNT',
        'dito' => 'DITO',
        'gomo' => 'GOMO',
    ],

    'amounts' => [50, 100, 200, 300, 500, 1000],

    // Requests still waiting for approval that one employee may have at a time.
    'max_open_requests' => 3,

    // The approver's proof that the load was sent (a screenshot of the
    // carrier's confirmation): the employee's receipt. Images only.
    'proof_max_kilobytes' => 5 * 1024,

    'disk' => env('LOAD_PROOFS_DISK') ?: env('ATTENDANCE_PHOTO_DISK', 'local'),

];
