<?php

namespace App\Enums;

/**
 * Philippine administrative regions, used for where CLIENTS are (the sites
 * guards are deployed to), never for anyone's home address.
 *
 * Provinces are listed here, so a client's province is always one of these
 * and "cavite" / "Cavite " can't split one province in two. NCR has no
 * provinces, so it lists its cities. Boundaries do change (Negros Island
 * Region, RA 12000 of 2024; Sulu leaving BARMM, SC 2024): edit the list here.
 */
enum Region: string
{
    case Ncr = 'ncr';
    case Car = 'car';
    case IlocosRegion = 'r1';
    case CagayanValley = 'r2';
    case CentralLuzon = 'r3';
    case Calabarzon = 'r4a';
    case Mimaropa = 'mimaropa';
    case Bicol = 'r5';
    case WesternVisayas = 'r6';
    case NegrosIsland = 'nir';
    case CentralVisayas = 'r7';
    case EasternVisayas = 'r8';
    case ZamboangaPeninsula = 'r9';
    case NorthernMindanao = 'r10';
    case Davao = 'r11';
    case Soccsksargen = 'r12';
    case Caraga = 'caraga';
    case Barmm = 'barmm';

    /** The short name people use: "Region IV-A", "NCR". */
    public function label(): string
    {
        return match ($this) {
            self::Ncr => 'NCR',
            self::Car => 'CAR',
            self::IlocosRegion => 'Region I',
            self::CagayanValley => 'Region II',
            self::CentralLuzon => 'Region III',
            self::Calabarzon => 'Region IV-A',
            self::Mimaropa => 'Region IV-B',
            self::Bicol => 'Region V',
            self::WesternVisayas => 'Region VI',
            self::NegrosIsland => 'NIR',
            self::CentralVisayas => 'Region VII',
            self::EasternVisayas => 'Region VIII',
            self::ZamboangaPeninsula => 'Region IX',
            self::NorthernMindanao => 'Region X',
            self::Davao => 'Region XI',
            self::Soccsksargen => 'Region XII',
            self::Caraga => 'Region XIII',
            self::Barmm => 'BARMM',
        };
    }

    public function officialName(): string
    {
        return match ($this) {
            self::Ncr => 'National Capital Region',
            self::Car => 'Cordillera Administrative Region',
            self::IlocosRegion => 'Ilocos Region',
            self::CagayanValley => 'Cagayan Valley',
            self::CentralLuzon => 'Central Luzon',
            self::Calabarzon => 'CALABARZON',
            self::Mimaropa => 'MIMAROPA',
            self::Bicol => 'Bicol Region',
            self::WesternVisayas => 'Western Visayas',
            self::NegrosIsland => 'Negros Island Region',
            self::CentralVisayas => 'Central Visayas',
            self::EasternVisayas => 'Eastern Visayas',
            self::ZamboangaPeninsula => 'Zamboanga Peninsula',
            self::NorthernMindanao => 'Northern Mindanao',
            self::Davao => 'Davao Region',
            self::Soccsksargen => 'SOCCSKSARGEN',
            self::Caraga => 'Caraga',
            self::Barmm => 'Bangsamoro (BARMM)',
        };
    }

    /** "Region IV-A · CALABARZON" */
    public function fullLabel(): string
    {
        return $this->label() === $this->officialName() ? $this->label() : $this->label().' · '.$this->officialName();
    }

    /** What the second level is called: NCR has cities, the rest provinces. */
    public function areaLabel(): string
    {
        return $this === self::Ncr ? 'City' : 'Province';
    }

    /**
     * @return list<string>
     */
    public function provinces(): array
    {
        return match ($this) {
            self::Ncr => ['Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Manila', 'Marikina', 'Muntinlupa', 'Navotas',
                'Parañaque', 'Pasay', 'Pasig', 'Pateros', 'Quezon City', 'San Juan', 'Taguig', 'Valenzuela'],
            self::Car => ['Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province'],
            self::IlocosRegion => ['Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan'],
            self::CagayanValley => ['Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino'],
            self::CentralLuzon => ['Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales'],
            self::Calabarzon => ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'],
            self::Mimaropa => ['Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon'],
            self::Bicol => ['Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon'],
            self::WesternVisayas => ['Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo'],
            self::NegrosIsland => ['Negros Occidental', 'Negros Oriental', 'Siquijor'],
            self::CentralVisayas => ['Bohol', 'Cebu'],
            self::EasternVisayas => ['Biliran', 'Eastern Samar', 'Leyte', 'Northern Samar', 'Samar', 'Southern Leyte'],
            self::ZamboangaPeninsula => ['Sulu', 'Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay'],
            self::NorthernMindanao => ['Bukidnon', 'Camiguin', 'Lanao del Norte', 'Misamis Occidental', 'Misamis Oriental'],
            self::Davao => ['Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental'],
            self::Soccsksargen => ['Cotabato', 'Sarangani', 'South Cotabato', 'Sultan Kudarat'],
            self::Caraga => ['Agusan del Norte', 'Agusan del Sur', 'Dinagat Islands', 'Surigao del Norte', 'Surigao del Sur'],
            self::Barmm => ['Basilan', 'Lanao del Sur', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Tawi-Tawi'],
        };
    }
}
