<?php

namespace App\Enums;

/**
 * The documents of an employee's 201 file. Ported from the old system's
 * TwoOOneDocument enum, with "GKE" renamed to "RAE" (V2 item 9).
 *
 * The values are stored in employee_requirements.requirement, so renaming a
 * LABEL is safe at any time; renaming a VALUE needs a data migration.
 */
enum Requirement: string
{
    case PersonalDataSheet = 'personal_data_sheet';
    case DataPrivacyAcknowledgement = 'data_privacy_acknowledgement';
    case BirthCertificate = 'birth_certificate';
    case Picture2x2 = 'picture_2x2';
    case DiplomaTor = 'diploma_tor';
    case CertificateOfEmployment = 'certificate_of_employment';
    case PoliceClearance = 'police_clearance';
    case NbiClearance = 'nbi_clearance';
    case BarangayClearance = 'barangay_clearance';
    case SssE1 = 'sss_e1';
    case PhilhealthMdr = 'philhealth_mdr';
    case PagibigMdr = 'pagibig_mdr';
    case TinId = 'tin_id';
    case SecurityLicense = 'security_license';
    case NeuroPsychTest = 'neuro_psych_test';
    case DrugTest = 'drug_test';
    case PreEmploymentMedical = 'pre_employment_medical';
    case TrainingCertificate = 'training_certificate';
    case RaeReport = 'rae_report';
    case FiringCertificate = 'firing_certificate';
    case SosiaCertificate = 'sosia_certificate';

    public function label(): string
    {
        return match ($this) {
            self::PersonalDataSheet => 'Personal Data Sheet',
            self::DataPrivacyAcknowledgement => 'Data Privacy Notice Acknowledgement (RA 10173)',
            self::BirthCertificate => 'Birth Certificate (PSA)',
            self::Picture2x2 => '2pcs 2x2 Picture (Type A Uniform & White Background)',
            self::DiplomaTor => 'Diploma / TOR',
            self::CertificateOfEmployment => 'Certificate of Employment',
            self::PoliceClearance => 'Police Clearance',
            self::NbiClearance => 'NBI Clearance',
            self::BarangayClearance => 'Barangay Clearance',
            self::SssE1 => 'SSS (E-1 Form)',
            self::PhilhealthMdr => 'PhilHealth (MDR)',
            self::PagibigMdr => 'Pag-IBIG (MDR)',
            self::TinId => 'TIN ID',
            self::SecurityLicense => 'Security License',
            self::NeuroPsychTest => 'Neuro & Psychiatric Test Result',
            self::DrugTest => 'Drug Test Result',
            self::PreEmploymentMedical => 'Pre-Employment Medical Examination',
            self::TrainingCertificate => 'Training Certificate',
            self::RaeReport => 'RAE Opening and Closing Report',
            self::FiringCertificate => 'Firing Certificate',
            self::SosiaCertificate => 'SOSIA Certificate',
        };
    }

    /**
     * Documents with a validity period. HR records the expiry date when the
     * document is filed, and the daily alert check warns before it lapses.
     */
    public function expires(): bool
    {
        return in_array($this, [
            self::SecurityLicense, self::NbiClearance, self::PoliceClearance, self::BarangayClearance,
            self::NeuroPsychTest, self::DrugTest, self::PreEmploymentMedical,
        ], true);
    }

    /**
     * Security-guard documents. Office staff are not asked for them.
     */
    public function isGuardOnly(): bool
    {
        return in_array($this, [
            self::SecurityLicense, self::NeuroPsychTest, self::TrainingCertificate,
            self::RaeReport, self::FiringCertificate, self::SosiaCertificate,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function for(bool $isOfficeStaff): array
    {
        return array_values(array_filter(self::cases(), fn (self $requirement) => ! ($isOfficeStaff && $requirement->isGuardOnly())));
    }
}
