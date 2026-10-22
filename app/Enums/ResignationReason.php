<?php

namespace App\Enums;

/**
 * Why someone resigns: a checklist, so HR can see patterns (pay, distance, the
 * work). The personal reasons need the 30 days' notice (Labor Code Art. 300(a));
 * the just causes are Art. 300(b)'s own list, and the employee may leave at once.
 * "Others" needs the reason in words.
 */
enum ResignationReason: string
{
    case AnotherJob = 'another_job';
    case PayAndBenefits = 'pay_and_benefits';
    case WorkEnvironment = 'work_environment';
    case Schedule = 'schedule';
    case Health = 'health';
    case Family = 'family';
    case Relocation = 'relocation';
    case Studies = 'studies';
    case Others = 'others';

    // Art. 300(b), by the employer or its representative.
    case SeriousInsult = 'serious_insult';
    case InhumanTreatment = 'inhuman_treatment';
    case CrimeAgainstMe = 'crime_against_me';
    case AnalogousCause = 'analogous_cause';

    /** Before the checklist (2026-10-17): one "just cause" for all four. Kept for those records; not offered. */
    case JustCause = 'just_cause';

    public function label(): string
    {
        return match ($this) {
            self::AnotherJob => 'Another job',
            self::PayAndBenefits => 'Pay and benefits',
            self::WorkEnvironment => 'Work environment',
            self::Schedule => 'Schedule or shifts',
            self::Health => 'Health',
            self::Family => 'Family reasons',
            self::Relocation => 'Moving away',
            self::Studies => 'Going back to school',
            self::Others => 'Others',
            self::SeriousInsult => 'Serious insult on my honor and person',
            self::InhumanTreatment => 'Inhuman and unbearable treatment',
            self::CrimeAgainstMe => 'A crime or offense against me or my immediate family',
            self::AnalogousCause => 'Another cause like these',
            self::JustCause => 'Just cause (leave at once)',
        };
    }

    /** The label as HR reads or enters it: "their" for the employee's "my". */
    public function labelForOthers(): string
    {
        return match ($this) {
            self::SeriousInsult => 'Serious insult on their honor and person',
            self::CrimeAgainstMe => 'A crime or offense against them or their immediate family',
            default => $this->label(),
        };
    }

    /** Art. 300(b)(1) to (4): the employee may leave without serving the notice. */
    public function skipsNotice(): bool
    {
        return in_array($this, [self::SeriousInsult, self::InhumanTreatment, self::CrimeAgainstMe, self::AnalogousCause, self::JustCause], true);
    }

    /** "Art. 300(b)(2)", for a just cause. */
    public function article(): ?string
    {
        return match ($this) {
            self::SeriousInsult => 'Art. 300(b)(1)',
            self::InhumanTreatment => 'Art. 300(b)(2)',
            self::CrimeAgainstMe => 'Art. 300(b)(3)',
            self::AnalogousCause => 'Art. 300(b)(4)',
            self::JustCause => 'Art. 300(b)',
            default => null,
        };
    }

    /**
     * The checklist's first group: leaving for their own reasons, with notice.
     *
     * @return list<self>
     */
    public static function personal(): array
    {
        return [self::AnotherJob, self::PayAndBenefits, self::WorkEnvironment, self::Schedule, self::Health, self::Family, self::Relocation, self::Studies, self::Others];
    }

    /**
     * The checklist's second group: Art. 300(b)'s just causes.
     *
     * @return list<self>
     */
    public static function justCauses(): array
    {
        return [self::SeriousInsult, self::InhumanTreatment, self::CrimeAgainstMe, self::AnalogousCause];
    }

    /**
     * What the form offers (everything but the old single "just cause").
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [...self::personal(), ...self::justCauses()];
    }
}
