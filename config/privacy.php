<?php

/**
 * Privacy notice served at /privacy-notice and acknowledged once per version.
 *
 * Written against Republic Act No. 10173 (Data Privacy Act of 2012), its
 * Implementing Rules and Regulations, and the issuances of the National Privacy
 * Commission.
 *
 * WHY THIS IS AN ACKNOWLEDGEMENT AND NOT A CONSENT FORM
 * -----------------------------------------------------
 * The obligation being met here is the data subject's right to be informed
 * (sec. 16(a)), which requires notice at or before entry into the processing
 * system. The lawful criteria actually relied on are:
 *
 *   Personal information (sec. 12)
 *     12(b) necessary and related to the fulfilment of a contract with the
 *           data subject — the employment contract;
 *     12(c) necessary for compliance with a legal obligation;
 *     12(f) necessary for the legitimate interests of the controller.
 *
 *   Sensitive personal information (sec. 13) — processing is PROHIBITED unless
 *   an exception applies. The ones relied on are:
 *     13(b) provided for by existing laws and regulations;
 *     13(f) necessary for the establishment, exercise or defence of legal claims.
 *
 * Consent (sec. 12(a) / 13(a)) is deliberately NOT relied on. Consent given as
 * a condition of keeping a job is not freely given, so labelling this a consent
 * form would be both weaker than the criteria actually available and an
 * invitation to withdraw permission for processing the company is legally
 * required to perform. The wording throughout says "acknowledge". Keep it that
 * way — tests/Feature/Privacy/PrivacyNoticeTest.php asserts it.
 *
 * WHAT THIS SYSTEM ACTUALLY HOLDS
 * -------------------------------
 * Sensitive personal information under sec. 3(l): health data (blood type,
 * declared medical conditions, medical examination dates), government-issued
 * identifiers (SSS, PhilHealth, Pag-IBIG, TIN), and the date of birth (it reveals age).
 *
 * Personal information: a facial photograph and a GPS fix captured on every
 * attendance punch, together with the resolved street address.
 *
 * Note the cross-border transfer disclosed in the sharing section: those
 * coordinates are sent to OpenStreetMap's Nominatim service, outside the
 * Philippines, on every punch. Under sec. 21 the company
 * remains accountable for personal information transferred to a third party, so
 * this has to be declared rather than treated as an implementation detail.
 *
 * REVISING THE NOTICE
 * -------------------
 * Edit the sections, then bump `version`. Everyone is re-prompted on their next
 * page load — that is the point of the version forming part of the stored
 * acknowledgement rather than a bare boolean. Run `php artisan config:clear`
 * (or re-cache) after editing in a deployed environment.
 *
 * NOT LEGAL ADVICE. The statutory citations below are given so counsel or the
 * DPO can check them quickly, not as a substitute for that review. The company
 * details, the retention periods and the NPC address in particular must be
 * confirmed before this goes in front of employees.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Notice version
    |--------------------------------------------------------------------------
    */

    'version' => env('PRIVACY_NOTICE_VERSION', '2026-10-22.1'),

    /*
    |--------------------------------------------------------------------------
    | Personal Information Controller
    |--------------------------------------------------------------------------
    |
    | Sec. 16(a) requires the identity of the controller to be disclosed. Set
    | these to the company's registered name and address.
    |
    */

    'controller' => [
        // env(), not config('app.name'): calling config() from inside a config
        // file depends on load order and behaves differently once the config is
        // cached. config/app.php reads APP_NAME the same way.
        'name' => env('PRIVACY_CONTROLLER_NAME', env('APP_NAME', 'Laravel')),
        'address' => env('PRIVACY_CONTROLLER_ADDRESS', 'Registered office address — set PRIVACY_CONTROLLER_ADDRESS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Protection Officer
    |--------------------------------------------------------------------------
    |
    | Required, and required to be contactable — NPC Advisory No. 2017-01.
    |
    */

    'officer' => [
        'name' => env('PRIVACY_OFFICER_NAME', 'Data Protection Officer'),
        'email' => env('PRIVACY_OFFICER_EMAIL', 'privacy@example.com'),
        'phone' => env('PRIVACY_OFFICER_PHONE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | National Privacy Commission
    |--------------------------------------------------------------------------
    |
    | Shown so a data subject can escalate independently. VERIFY these details
    | against privacy.gov.ph before publishing — the Commission has moved office
    | before and a wrong address in a privacy notice is its own small failure.
    |
    */

    'commission' => [
        'name' => 'National Privacy Commission',
        'address' => env('PRIVACY_NPC_ADDRESS', '5th Floor, Delegation Building, PICC Complex, Vicente Sotto Ave., Pasay City 1307'),
        'email' => env('PRIVACY_NPC_EMAIL', 'complaints@privacy.gov.ph'),
        'website' => env('PRIVACY_NPC_WEBSITE', 'https://www.privacy.gov.ph'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notice body
    |--------------------------------------------------------------------------
    |
    | Plain text only — rendered escaped, so this is safe for a non-developer to
    | edit and cannot inject markup into the page.
    |
    */

    'sections' => [
        [
            'heading' => 'About this notice',
            'body' => [
                'This notice is given under Section 16(a) of Republic Act No. 10173, the Data Privacy Act of 2012, which gives you the right to be informed about the collection and processing of your personal data.',
                'The company is the Personal Information Controller for the records described below. Its identity and address, and the contact details of its Data Protection Officer, appear at the end of this notice.',
                'We process your data according to the principles in Section 11 of the Act: transparency about what we do, a declared and legitimate purpose for doing it, and collection limited to what is proportionate to that purpose.',
            ],
        ],
        [
            'heading' => 'Personal information we collect',
            'body' => [
                'Identity and contact details: your name, email address, contact number and permanent address.',
                'Employment records: employee number, position, detachment and post assignment, hire date, employment status and shift schedule.',
                'Emergency contact details for the person you nominate.',
                'Attendance records: for every clock-in and clock-out, a photograph taken at that moment, your GPS coordinates, the street address those coordinates resolve to, and the time.',
                'The phone you clock in with: a random code the system stores on that phone\'s browser (a cookie), and the type of phone and browser (for example "Android · Chrome"). For security guards, who are issued a company phone, a clock-in or clock-out from a different phone is still recorded, and is shown to Operations and HR so they can check it was you.',
                'Time away during work: when you step away from your post or the office, the reason, your note, the times you left and came back, when you said you would be back and any time you added, and the GPS positions you share while away.',
                'Patrol rounds (roving guards): each checkpoint you scan, with the camera frame that read it, the time and your GPS position.',
                'Payroll records: pay rates, allowances, deductions, loan balances, reimbursements and generated payslips.',
                'Company property in your care: a two-way radio issued to you (its make and serial number), and, if you keep a petty cash fund, each payment you make from it with its receipt.',
                'Referrals: if you refer someone for hiring, the name and mobile number you give us, and what became of the referral. Please tell that person first that you are giving us their details; HR uses them only to contact them about the job.',
                'System records: your sign-in activity and the changes you make to records in this system.',
            ],
        ],
        [
            'heading' => 'Sensitive personal information we collect',
            'body' => [
                'Section 3(l) of the Act treats some categories as sensitive and prohibits processing them unless a specific exception in Section 13 applies. We hold three such categories.',
                'Government-issued identifiers: your SSS, PhilHealth, Pag-IBIG and TIN numbers.',
                'Your date of birth, which shows your age.',
                'Other identification you give HR, such as your security guard license, driver\'s license or passport: the name of each document and its number.',
                'Health information: blood type, declared medical conditions, and the dates and results status of your pre-employment and periodic medical examinations, including those required of licensed security personnel.',
                'We do not collect information about your race, ethnic origin, marital status, colour, religious or philosophical affiliations, political affiliations, genetic data, sexual life, or any proceeding for an offence, except where a document you submit for your 201 file contains it and the law requires us to keep that document.',
            ],
        ],
        [
            'heading' => 'Our legal basis for processing it',
            'body' => [
                'For personal information, we rely on Section 12(b) of the Act — processing necessary for and related to the fulfilment of your employment contract, which includes recording attendance, computing pay, and administering leave, overtime, complaints and claims.',
                'We also rely on Section 12(c) — compliance with a legal obligation — and on Section 12(f), our legitimate interest in verifying that the guard on record is the guard on post.',
                'For sensitive personal information, we rely on Section 13(b) — processing provided for by existing laws and regulations. Your SSS number is required under Republic Act No. 11199, your PhilHealth number under Republic Act No. 11223, your Pag-IBIG number under Republic Act No. 9679, and your TIN under the National Internal Revenue Code for withholding tax. A security guard\'s license is required by Republic Act No. 11917, the Private Security Services Industry Act. Any other identification you give HR is optional, and is processed under Section 12(f), the company\'s legitimate interest in verifying your identity and qualifications; HR removes it when you ask.',
                'Your date of birth is processed under Section 13(b): your SSS, PhilHealth and Pag-IBIG membership records require it under the same laws. HR, and Operations for security guards, are also reminded of your birthday on the day. That reminder shows only the day and month, never your age or year of birth, and rests on Section 12(f), the company\'s legitimate interest in recognising its people. You may object to it at any time: tell HR, and no one will be reminded.',
                'Health information is likewise processed under Section 13(b): Republic Act No. 5487 and its implementing rules require medical and neuro-psychiatric clearance of licensed security personnel, Republic Act No. 9165 requires drug testing, and Republic Act No. 11058 with DOLE Department Order No. 198-18 requires occupational health surveillance and the keeping of medical records.',
                'Where a dispute arises, we may also rely on Section 13(f) — processing necessary for the establishment, exercise or defence of a legal claim.',
                'We do not rely on your consent for any of the above. Processing it is necessary for your employment and required by law, so acknowledging this notice records that you were informed. It is not permission, and withdrawing it is not something the company can offer for records it is legally obliged to keep.',
            ],
        ],
        [
            'heading' => 'The attendance photograph and location',
            'body' => [
                'A GPS reading is captured only when you act: when you clock in or out (with a photograph), scan a patrol checkpoint, mark yourself away or back, or tap "Update my location" while away. While you are away the app reminds you to share your location, but it never reads it by itself. This system does not track you in the background or outside your duty.',
                'Your location while away is seen by those who supervise it: Operations for guards (a Satellite Operation for its own region only) and the Executive for office staff.',
                'They exist to confirm that the person clocking in is the guard assigned to that post at that time, which is the basis on which you are paid and on which the company reports to its clients and to the PNP Supervisory Office for Security and Investigation Agencies.',
                'To convert your coordinates into a readable street address, those coordinates are sent to an external mapping service. This is described in the next section.',
            ],
        ],
        [
            'heading' => 'Who we share it with',
            'body' => [
                'You, for your own records, at any time through this system.',
                'HR staff: employee records, leave and overtime.',
                'Accounting staff: payroll figures, deductions and reimbursements.',
                'Supervisors and administrators: attendance for the personnel they supervise.',
                'Government agencies where reporting is required by law: the Social Security System, PhilHealth, the Pag-IBIG Fund, the Bureau of Internal Revenue, the Department of Labor and Employment, and the PNP Supervisory Office for Security and Investigation Agencies.',
                'Client establishments, limited to the fact of your assignment to their post and the times you were on duty there, where the service contract requires it.',
                'Transfer outside the Philippines: when you clock in or out, and when a supervisor views where you were while away, your GPS coordinates are sent to the Nominatim service operated by the OpenStreetMap Foundation, in order to resolve a street address. Your name, employee number and photograph are not sent to it — only the coordinates. When a supervisor opens one of your recorded locations on a map, those coordinates are likewise sent to Google Maps, operated by Google LLC, again without your name or photograph. Under Section 21 of the Act the company remains accountable for personal data transferred to a third party, including one located abroad.',
                'We do not sell your personal information, and we do not share it for marketing or profiling.',
            ],
        ],
        [
            'heading' => 'How long we keep it',
            'body' => [
                'Employment and payroll records: at least three years, as required for employment records under the Omnibus Rules Implementing the Labor Code, and up to ten years for records forming part of the company books under Bureau of Internal Revenue Revenue Regulations No. 17-2013 as amended.',
                'Money claims under the Labor Code prescribe after three years, so records that may be needed to answer a claim are kept for at least that period after your employment ends.',
                'Records required of a licensed security agency are kept for the period prescribed by Republic Act No. 5487 and the rules of the PNP Supervisory Office for Security and Investigation Agencies.',
                'Medical and occupational health records are kept for the period required by Republic Act No. 11058 and DOLE Department Order No. 198-18.',
                'Attendance photographs and location records are kept for the period needed to support payroll disputes and regulatory inspection, and are then disposed of securely.',
                'Time away: the GPS positions are deleted automatically after '.env('ATTENDANCE_AWAY_LOCATION_RETENTION_DAYS', 90).' days. The summary (reason, note, times and approval) is kept with your attendance records, for the same period as employment records.',
                'Security event logs, including sign-in and account registration attempts, are kept for 90 days.',
            ],
        ],
        [
            'heading' => 'Your rights under the Data Privacy Act',
            'body' => [
                'To be informed — this notice, and any later revision of it, which you will be shown before you continue using the system.',
                'To object to processing, including where it is based on our legitimate interests, subject to the processing we are legally required to carry out.',
                'To access — to be told what data we hold about you, where it came from, who it has been disclosed to, and how it is being processed.',
                'To correct — to have inaccurate or outdated information about you rectified, and to have the corrected version passed on to anyone it was already disclosed to.',
                'To erasure or blocking — to have your data removed from our systems where it is incomplete, outdated, false, unlawfully obtained, used for an undisclosed purpose, or no longer necessary.',
                'To damages — to be indemnified for any harm caused by inaccurate, incomplete, outdated, false or unlawfully obtained use of your personal data.',
                'To data portability under Section 18 — to obtain a copy of the data you provided in a commonly used, electronic format.',
                'To lodge a complaint with the National Privacy Commission, independently of anything the company decides.',
                'These rights are not absolute. Where the law requires us to keep or report a record, we will explain that rather than silently decline.',
            ],
        ],
        [
            'heading' => 'How we protect it',
            'body' => [
                'Access is restricted by role, so staff can only reach the records their work requires.',
                'Changes to employee, leave, overtime and payroll records are written to an audit trail identifying who made the change and when.',
                'Attendance photographs are stored privately and cannot be reached by guessing a web address.',
                'Connections to this system are encrypted, and sessions sign out automatically after a period of inactivity.',
                'Passwords are stored hashed, sign-in attempts are rate limited, and staff can protect their accounts with two-factor authentication.',
                'Government ID numbers, your date of birth and medical information are encrypted in the database.',
            ],
        ],
        [
            'heading' => 'If something goes wrong',
            'body' => [
                'If a personal data breach occurs that is likely to give rise to a real risk of serious harm, the company will notify the National Privacy Commission and the affected employees within 72 hours of becoming aware of it, as required by NPC Circular No. 16-03.',
                'The notification will describe the nature of the breach, the data involved, the measures taken, and how you can reduce the risk to yourself.',
                'If you believe your data has been mishandled, contact the Data Protection Officer first. You may go to the National Privacy Commission at any time, whether or not you have contacted us.',
            ],
        ],
    ],

];
