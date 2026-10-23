<?php

/*
 * Filipino version of lang/en/acknowledgements.php: the same points, in plain
 * Filipino. Have HR (or counsel) read it before go-live; the English and Filipino
 * must say the same thing. :handler is "HR" or "Executive".
 */
return [
    'ui' => [
        'read' => 'Nabasa ko at naiintindihan ko ang mga ito',
        'certify' => 'Pinatutunayan kong totoo ang mga ito',
        'authorize' => 'Pinahihintulutan ko ito',
        'received' => 'Natanggap ko ito',
        'language' => 'Basahin sa',
    ],

    'resignation' => [
        'free_will' => 'Nagbibitiw ako nang kusang-loob. Walang pumilit sa akin.',
        'true' => 'Totoo ang sinasabi ko sa aking sulat tungkol sa nangyari, at maaari akong tanungin ng :handler tungkol dito.',
        'notice' => 'Magbibigay ako ng abiso nang hindi bababa sa 30 araw, o hihilingin ko sa :handler na huwag nang hingin ang natitira. Kung aalis ako nang mas maaga nang walang pahintulot, maaari akong managot sa danyos (Labor Code Art. 300(a)).',
        'withdraw' => 'Maaari ko lamang itong bawiin hangga\'t hindi pa ito tinatanggap ng :handler.',
        'return' => 'Bago ang aking huling araw, isasauli ko ang ipinahiram ng kumpanya (ID, uniporme, kagamitan) at ipapasa ko ang aking puwesto o trabaho.',
        'deduct' => 'Pinahihintulutan ko ang kumpanya na ibawas ang aking natitirang utang (hindi pa bayad na loan, hiniram na load, hindi naisauling gamit) mula sa aking final pay at cash bond (Labor Code Art. 113).',
        'final_pay' => 'Ibibigay ang aking final pay sa loob ng 30 araw mula sa aking huling araw, at maaari akong humingi ng Certificate of Employment na ibibigay sa loob ng 3 araw (DOLE Labor Advisory No. 06, s. 2020).',
        'account' => 'Isasara ang aking account dito sa araw pagkatapos ng aking huling araw.',
    ],

    'complaint' => [
        'true' => 'Totoo ang isinulat ko rito, sa abot ng aking kaalaman. Ang reklamong alam kong hindi totoo ay maaari ring magresulta sa disiplina.',
        'due_process' => 'Pananatilihing kumpidensyal ito ng :handler, ngunit maaari nitong ipaalam sa taong sangkot ang sinabi, para maibigay nila ang kanilang panig (due process).',
        'no_retaliation' => 'Walang maaaring magparusa sa akin dahil sa reklamong ginawa nang may mabuting loob. Kung mangyari iyon, maaari ko rin itong i-report dito.',
    ],

    'reimbursement' => [
        'genuine' => 'Totoo ang resibo, at ginastos ko ang perang ito para sa trabaho ng kumpanya.',
        'for_guard_genuine' => 'Totoo ang resibo, at ginastos ito ng guard para sa deployment na iniutos o kinumpirma ko.',
        'once' => 'Hindi pa ito naibabalik sa akin sa ibang paraan, at hindi ko na ito muling ike-claim.',
        'false_claim' => 'Ang pekeng claim ay hindi tapat at maaaring magresulta sa pagkatanggal sa trabaho (Labor Code Art. 297).',
    ],

    'load' => 'Pinahihintulutan ko ang kumpanya na ibawas ang ₱:amount mula sa aking susunod na payslip para sa load na ito (Labor Code Art. 113).',

    'deduction' => [
        'with_total' => 'Pinahihintulutan ko ang kumpanya na ibawas ang :each sa bawat payslip para sa aking :what hanggang mabayaran ang :total (Labor Code Art. 113).',
        'recurring' => 'Pinahihintulutan ko ang kumpanya na ibawas ang :each sa bawat payslip para sa :what (Labor Code Art. 113).',
    ],

    'equipment' => [
        'company' => 'Natanggap ko ang :what nang nasa maayos na kondisyon. Ari-arian pa rin ito ng kumpanya: iingatan ko ito, gagamitin lamang sa trabaho, at isasauli kapag hiningi o kapag ako ay umalis. Kung mawala o masira ito dahil sa aking kapabayaan, maaari akong singilin ng kumpanya, ngunit pagkatapos lamang akong mapakinggan, at hindi hihigit sa tunay na halaga ng nawala.',
        'charged' => 'Natanggap ko ang :what, na nagkakahalaga ng :total. Babayaran ko ito ayon sa aking pahintulot sa My Payslips.',
    ],
];
