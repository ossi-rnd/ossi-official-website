<?php

/*
 * What employees confirm or authorize before sending a form, word for word.
 * The record keeps the lines in the language they read (lang/fil for Filipino).
 * :handler is "HR" or "the President or Vice President"; :Handler is the same, capitalized.
 */
return [
    'ui' => [
        'read' => 'I\'ve read these, and I understand them',
        'certify' => 'I certify these are true',
        'authorize' => 'I authorize this',
        'language' => 'Read in',
    ],

    'resignation' => [
        'free_will' => 'I am resigning of my own free will. No one forced me to.',
        'true' => 'What I say in my letter about what happened is true, and :handler may ask me about it.',
        'notice' => 'I give at least 30 days\' notice, or ask :handler to waive the rest. Leaving earlier without that can make me liable for damages (Labor Code Art. 300(a)).',
        'withdraw' => 'I can take this back only until :handler accepts it.',
        'return' => 'Before my last day, I return what the company lent me (ID, uniform, equipment) and hand over my post or work.',
        'deduct' => 'I authorize the company to deduct what I still owe (unpaid loans, borrowed load, unreturned property) from my final pay and cash bond (Labor Code Art. 113).',
        'final_pay' => 'My final pay is released within 30 days of my last day, and I can ask for a Certificate of Employment, issued within 3 days (DOLE Labor Advisory No. 06, s. 2020).',
        'account' => 'My account here closes the day after my last day.',
    ],

    'complaint' => [
        'true' => 'What I write here is true, as far as I know. A complaint I know to be false can itself lead to discipline.',
        'due_process' => ':Handler keeps it confidential, but may tell the person involved what is said, so they can give their side (due process).',
        'no_retaliation' => 'No one may punish me for a complaint made in good faith. If that happens, I can report it here too.',
    ],

    'reimbursement' => [
        'genuine' => 'The receipt is genuine, and I spent this money on company business.',
        'for_guard_genuine' => 'The receipt is genuine, and the guard spent this money on a deployment I ordered or confirmed.',
        'once' => 'I haven\'t been paid back for it any other way, and I won\'t claim it again.',
        'false_claim' => 'A false claim is dishonest and can lead to dismissal (Labor Code Art. 297).',
    ],

    'load' => 'I authorize the company to deduct ₱:amount from my next payslip for this load (Labor Code Art. 113).',

    'deduction' => [
        'with_total' => 'I authorize the company to deduct :each from each payslip for my :what until :total is paid (Labor Code Art. 113).',
        'recurring' => 'I authorize the company to deduct :each from each payslip for :what (Labor Code Art. 113).',
    ],
];
