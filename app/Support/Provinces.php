<?php

namespace App\Support;

/**
 * Province of birth → the 3-letter code in an employee number (260627PAM-0950).
 *
 * The official ISO 3166-2:PH province codes: 82 provinces (ISO newsletter of
 * 2023-11-23: Maguindanao del Norte / del Sur). Metro Manila is a region with no
 * ISO letter code (PH-00), so it uses NCR. ISO keeps a code when a province is
 * renamed: Cotabato is NCO (North Cotabato), Davao de Oro is COM (Compostela
 * Valley), Samar is WSA (Western Samar).
 */
final class Provinces
{
    /** @var array<string, string> code => name */
    public const ALL = [
        'ABR' => 'Abra',
        'AGN' => 'Agusan del Norte',
        'AGS' => 'Agusan del Sur',
        'AKL' => 'Aklan',
        'ALB' => 'Albay',
        'ANT' => 'Antique',
        'APA' => 'Apayao',
        'AUR' => 'Aurora',
        'BAS' => 'Basilan',
        'BAN' => 'Bataan',
        'BTN' => 'Batanes',
        'BTG' => 'Batangas',
        'BEN' => 'Benguet',
        'BIL' => 'Biliran',
        'BOH' => 'Bohol',
        'BUK' => 'Bukidnon',
        'BUL' => 'Bulacan',
        'CAG' => 'Cagayan',
        'CAN' => 'Camarines Norte',
        'CAS' => 'Camarines Sur',
        'CAM' => 'Camiguin',
        'CAP' => 'Capiz',
        'CAT' => 'Catanduanes',
        'CAV' => 'Cavite',
        'CEB' => 'Cebu',
        'NCO' => 'Cotabato',
        'COM' => 'Davao de Oro',
        'DAV' => 'Davao del Norte',
        'DAS' => 'Davao del Sur',
        'DVO' => 'Davao Occidental',
        'DAO' => 'Davao Oriental',
        'DIN' => 'Dinagat Islands',
        'EAS' => 'Eastern Samar',
        'GUI' => 'Guimaras',
        'IFU' => 'Ifugao',
        'ILN' => 'Ilocos Norte',
        'ILS' => 'Ilocos Sur',
        'ILI' => 'Iloilo',
        'ISA' => 'Isabela',
        'KAL' => 'Kalinga',
        'LUN' => 'La Union',
        'LAG' => 'Laguna',
        'LAN' => 'Lanao del Norte',
        'LAS' => 'Lanao del Sur',
        'LEY' => 'Leyte',
        'MGN' => 'Maguindanao del Norte',
        'MGS' => 'Maguindanao del Sur',
        'MAD' => 'Marinduque',
        'MAS' => 'Masbate',
        'NCR' => 'Metro Manila',
        'MDC' => 'Mindoro Occidental',
        'MDR' => 'Mindoro Oriental',
        'MSC' => 'Misamis Occidental',
        'MSR' => 'Misamis Oriental',
        'MOU' => 'Mountain Province',
        'NEC' => 'Negros Occidental',
        'NER' => 'Negros Oriental',
        'NSA' => 'Northern Samar',
        'NUE' => 'Nueva Ecija',
        'NUV' => 'Nueva Vizcaya',
        'PLW' => 'Palawan',
        'PAM' => 'Pampanga',
        'PAN' => 'Pangasinan',
        'QUE' => 'Quezon',
        'QUI' => 'Quirino',
        'RIZ' => 'Rizal',
        'ROM' => 'Romblon',
        'WSA' => 'Samar',
        'SAR' => 'Sarangani',
        'SIG' => 'Siquijor',
        'SOR' => 'Sorsogon',
        'SCO' => 'South Cotabato',
        'SLE' => 'Southern Leyte',
        'SUK' => 'Sultan Kudarat',
        'SLU' => 'Sulu',
        'SUN' => 'Surigao del Norte',
        'SUR' => 'Surigao del Sur',
        'TAR' => 'Tarlac',
        'TAW' => 'Tawi-Tawi',
        'ZMB' => 'Zambales',
        'ZAN' => 'Zamboanga del Norte',
        'ZAS' => 'Zamboanga del Sur',
        'ZSI' => 'Zamboanga Sibugay',
    ];

    public static function isCode(string $code): bool
    {
        return array_key_exists($code, self::ALL);
    }

    public static function name(string $code): ?string
    {
        return self::ALL[$code] ?? null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }
}
