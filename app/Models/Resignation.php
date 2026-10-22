<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\ClearanceItem;
use App\Enums\ResignationReason;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use App\Support\Acknowledgements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;

/**
 * Someone leaving of their own accord, from the letter to the last day and
 * clearance. Handled by HR, or by the President or Vice President when an HR member resigns
 * (for_executive, set when it's filed). Nothing is fillable: the Actions set
 * every column.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $for_executive
 * @property ResignationReason $reason the main one: a just cause if one is ticked, else the first
 * @property list<string>|null $reasons every one ticked (the checklist); null only before 2026-10-17
 * @property string|null $reason_other
 * @property string $letter
 * @property list<string>|null $acknowledgement what they confirmed before sending, word for word (null: a paper letter)
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable $requested_last_day
 * @property bool $asks_waiver
 * @property CarbonImmutable|null $deduction_consent_at written consent (Art. 113) to deduct what's owed from final pay
 * @property-read FinalPay|null $finalPay
 * @property-read Complaint|null $complaint the case opened for a just cause
 * @property ResignationStatus $status
 * @property CarbonImmutable|null $last_day
 * @property bool $notice_waived
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property array<string, array{at: string, by: string}>|null $clearance
 * @property CarbonImmutable|null $separated_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $recorded_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $decider
 * @property-read User|null $recorder
 */
class Resignation extends Model
{
    /** Labor Code Art. 300(a): written notice at least this many days before the last day. */
    public const int NOTICE_DAYS = 30;

    /** DOLE Labor Advisory 06-20: final pay within this many days of separation. */
    public const int FINAL_PAY_DAYS = 30;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'for_executive' => false,
        'asks_waiver' => false,
        'status' => 'pending',
        'notice_waived' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'for_executive' => 'boolean',
            'reason' => ResignationReason::class,
            'reasons' => 'array',
            'acknowledgement' => 'array',
            'acknowledged_at' => 'datetime',
            'requested_last_day' => 'date',
            'asks_waiver' => 'boolean',
            'deduction_consent_at' => 'datetime',
            'status' => ResignationStatus::class,
            'last_day' => 'date',
            'notice_waived' => 'boolean',
            'decided_at' => 'datetime',
            'clearance' => 'array',
            'separated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return HasOne<FinalPay, $this>
     */
    public function finalPay(): HasOne
    {
        return $this->hasOne(FinalPay::class);
    }

    /**
     * The complaint case opened for a just cause (Art. 300(b)), if any.
     *
     * @return HasOne<Complaint, $this>
     */
    public function complaint(): HasOne
    {
        return $this->hasOne(Complaint::class);
    }

    /** HR staff's resignations go to the President or Vice President; everyone else's to HR. */
    public static function goesToExecutive(User $employee): bool
    {
        return $employee->hasRole(Role::Hr);
    }

    /** Who may see and handle it. */
    public function handlerAbility(): Ability
    {
        return $this->for_executive ? Ability::HandleHrResignations : Ability::HandleResignations;
    }

    /** HR, or the President or Vice President (for HR staff's): opens the Resignations page. */
    public static function handlesAny(User $user): bool
    {
        return $user->canAny([Ability::HandleResignations->value, Ability::HandleHrResignations->value]);
    }

    /** "HR" or "the President or Vice President", for the employee's screens and notices. */
    public function handlerLabel(): string
    {
        return $this->for_executive ? 'the President or Vice President' : 'HR';
    }

    /**
     * Only the resignations this person handles: HR never sees one from HR staff.
     *
     * @param  Builder<Resignation>  $query
     */
    public function scopeHandledBy(Builder $query, User $user): void
    {
        $hr = $user->can(Ability::HandleResignations->value);
        $executive = $user->can(Ability::HandleHrResignations->value);

        match (true) {
            $hr && $executive => null,
            $hr => $query->where('for_executive', false),
            $executive => $query->where('for_executive', true),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  Builder<Resignation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ResignationStatus::Pending, ResignationStatus::Accepted]);
    }

    /**
     * Every reason ticked, just causes first.
     *
     * @return list<ResignationReason>
     */
    public function reasonList(): array
    {
        $reasons = array_values(array_filter(array_map(fn (string $value) => ResignationReason::tryFrom($value), $this->reasons ?? [])));

        if ($reasons === []) {
            return [$this->reason];
        }

        usort($reasons, fn (ResignationReason $a, ResignationReason $b) => (int) $b->skipsNotice() <=> (int) $a->skipsNotice());

        return $reasons;
    }

    /** A just cause (Art. 300(b)) is among the reasons: no notice is needed. */
    public function skipsNotice(): bool
    {
        return collect($this->reasonList())->contains(fn (ResignationReason $reason) => $reason->skipsNotice());
    }

    /** "Another job, Pay and benefits", with the words given for "Others". */
    public function reasonLabel(): string
    {
        return collect($this->reasonList())
            ->map(fn (ResignationReason $reason) => $reason === ResignationReason::Others && filled($this->reason_other) ? (string) $this->reason_other : $reason->labelForOthers())
            ->implode(', ');
    }

    /**
     * What the employee confirms before sending, in plain words: shown on the form
     * and saved with the resignation. A just cause isn't "of my own free will"
     * and needs no notice, so those lines change. $handler: "HR" or "the President or Vice President".
     *
     * @return list<string>
     */
    public static function acknowledgementLines(bool $justCause, string $handler, string $locale = 'en'): array
    {
        $line = fn (string $key) => Acknowledgements::line('resignation.'.$key, $locale, ['handler' => Acknowledgements::handler($handler, $locale)]);

        return array_values(array_filter([
            $line($justCause ? 'true' : 'free_will'),
            $justCause ? null : $line('notice'),
            $line('withdraw'),
            $line('return'),
            // Art. 113: a deduction for what's owed needs the employee's written authorization; this is it.
            $line('deduct'),
            $line('final_pay'),
            $line('account'),
        ]));
    }

    /**
     * A plain letter from what they ticked, for them to edit ("Use a sample letter").
     * What only they can say (what happened, for a just cause) is left in [brackets],
     * and the form refuses to send it until it's replaced.
     *
     * @param  list<ResignationReason>  $reasons
     */
    public static function sampleLetter(string $name, ?string $position, array $reasons, ?string $other, ?CarbonImmutable $lastDay, string $handler): string
    {
        $words = fn (ResignationReason $reason) => $reason === ResignationReason::Others
            ? (filled($other) ? trim((string) $other) : null)
            : lcfirst($reason->label());

        $justCauses = array_values(array_filter(array_map($words, array_filter($reasons, fn (ResignationReason $r) => $r->skipsNotice()))));
        $personal = array_values(array_filter(array_map($words, array_filter($reasons, fn (ResignationReason $r) => ! $r->skipsNotice()))));

        $why = match (true) {
            // No period after the brackets: whatever they write ends the sentence.
            $justCauses !== [] => 'I am leaving for a just cause under Labor Code Art. 300(b): '.Arr::join($justCauses, ', ', ' and ').'. What happened: [when, where, who, and what was said or done]',
            $personal !== [] => 'I am leaving because of '.Arr::join($personal, ', ', ' and ').'.',
            default => null,
        };

        return implode("\n", array_filter([
            'To '.$handler.':',
            '',
            'I am resigning from '.($position !== null ? "my position as {$position}" : 'my position').'. My last day of work will be '.($lastDay?->format('l, F j, Y') ?? '[my last day]').'.',
            $why !== null ? '' : null,
            $why,
            '',
            'Before my last day, I will return the company property in my care and hand over my duties. Thank you for the opportunity to work with the company.',
            '',
            'Respectfully,',
            $name,
        ], fn (?string $line) => $line !== null));
    }

    /** Days of notice from filing to a given last day (the requested one by default). */
    public function noticeDays(?CarbonImmutable $lastDay = null): int
    {
        return (int) $this->created_at->startOfDay()->diffInDays($lastDay ?? $this->requested_last_day, false);
    }

    /** The last day in force: HR's, or the requested one until HR accepts. */
    public function effectiveLastDay(): CarbonImmutable
    {
        return $this->last_day ?? $this->requested_last_day;
    }

    /** Final pay is due 30 days after the last day (DOLE Labor Advisory 06-20). */
    public function finalPayDue(): CarbonImmutable
    {
        return $this->effectiveLastDay()->addDays(self::FINAL_PAY_DAYS);
    }

    /** A guard: also returns a firearm and post equipment. */
    public function isGuard(): bool
    {
        return $this->user->employeeProfile?->hasDeployment() ?? false;
    }

    /**
     * @return list<ClearanceItem>
     */
    public function clearanceItems(): array
    {
        return ClearanceItem::for($this->isGuard());
    }

    public function isCleared(ClearanceItem $item): bool
    {
        return isset(($this->clearance ?? [])[$item->value]);
    }

    /** Every item for this person ticked. */
    public function fullyCleared(): bool
    {
        foreach ($this->clearanceItems() as $item) {
            if (! $this->isCleared($item)) {
                return false;
            }
        }

        return true;
    }

    /** "4 of 7". */
    public function clearedCount(): int
    {
        return count(array_filter($this->clearanceItems(), fn (ClearanceItem $item) => $this->isCleared($item)));
    }
}
