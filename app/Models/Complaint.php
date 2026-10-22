<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Support\Acknowledgements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Confidential: only the employee who filed it and its handler ever see it:
 * HR (handle-complaints), or the President or Vice President when an HR member filed it
 * (for_executive, handle-hr-complaints). The audit log records that it was
 * filed or updated, never what it says. Nothing is fillable: the Actions set
 * every column.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $resignation_id opened by a resignation for a just cause (Art. 300(b))
 * @property bool $for_executive
 * @property bool $asks_for_help "I need help now": alerted by email too, top of the handler's list
 * @property ComplaintCategory $category
 * @property int|null $complaint_subject_id the common subject picked from HR's list; null when typed ("Other")
 * @property string $subject
 * @property string $details
 * @property string|null $against
 * @property string|null $witnesses who saw it, when there's nothing on paper
 * @property CarbonImmutable|null $incident_on
 * @property ComplaintStatus $status
 * @property int|null $handled_by
 * @property string|null $resolution
 * @property CarbonImmutable|null $closed_at
 * @property list<string>|null $acknowledgement what they confirmed before sending, word for word
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $proof_requested_at the handler asked for proof; cleared when the complainant adds some
 * @property string|null $proof_request_note what the handler asked for
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $handler
 * @property-read Resignation|null $resignation
 * @property-read Collection<int, ComplaintFile> $files
 */
class Complaint extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'for_executive' => false,
        'asks_for_help' => false,
        'handled_by' => null,
        'resolution' => null,
        'closed_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ComplaintCategory::class,
            'status' => ComplaintStatus::class,
            'for_executive' => 'boolean',
            'asks_for_help' => 'boolean',
            'incident_on' => 'date',
            'closed_at' => 'datetime',
            'acknowledgement' => 'array',
            'acknowledged_at' => 'datetime',
            'proof_requested_at' => 'datetime',
        ];
    }

    /**
     * Proof the complainant attached (optional).
     *
     * @return HasMany<ComplaintFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ComplaintFile::class)->orderBy('id');
    }

    /** Asked for help and not closed yet: shown first and flagged to the handler. */
    public function needsHelpNow(): bool
    {
        return $this->asks_for_help && ! $this->status->isClosed();
    }

    /** The handler asked for proof and none has come since, on an open case. */
    public function awaitsProof(): bool
    {
        return $this->proof_requested_at !== null && ! $this->status->isClosed();
    }

    /** Who may open its files: the complainant and its handler. */
    public function filesVisibleTo(User $user): bool
    {
        return $user->id === $this->user_id || self::query()->handledBy($user)->whereKey($this->id)->exists();
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
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return BelongsTo<Resignation, $this>
     */
    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
    }

    /** Who may see and handle it. */
    public function handlerAbility(): Ability
    {
        return $this->for_executive ? Ability::HandleHrComplaints : Ability::HandleComplaints;
    }

    /** "HR" or "the President or Vice President", for the employee's screens and notices. */
    public function handlerLabel(): string
    {
        return $this->for_executive ? 'the President or Vice President' : 'HR';
    }

    /** HR, or the President or Vice President (for HR staff's): opens the Complaints page. */
    public static function handlesAny(User $user): bool
    {
        return $user->canAny([Ability::HandleComplaints->value, Ability::HandleHrComplaints->value]);
    }

    /** HR staff's complaints go to the President or Vice President; everyone else's to HR. */
    public static function goesToExecutive(User $complainant): bool
    {
        return $complainant->hasRole(Role::Hr);
    }

    /**
     * Only the complaints this person handles: HR never sees one filed by HR staff.
     *
     * @param  Builder<Complaint>  $query
     */
    public function scopeHandledBy(Builder $query, User $user): void
    {
        $hr = $user->can(Ability::HandleComplaints->value);
        $executive = $user->can(Ability::HandleHrComplaints->value);

        match (true) {
            $hr && $executive => null,
            $hr => $query->where('for_executive', false),
            $executive => $query->where('for_executive', true),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * What the complainant confirms before sending: it's true, the person involved
     * may be told (due process), and they're protected from retaliation.
     * $handler: "HR" or "the President or Vice President".
     *
     * @return list<string>
     */
    public static function acknowledgementLines(string $handler, string $locale = 'en'): array
    {
        $replace = ['handler' => Acknowledgements::handler($handler, $locale)];

        return array_map(fn (string $key) => Acknowledgements::line('complaint.'.$key, $locale, $replace), ['true', 'due_process', 'no_retaliation']);
    }

    /** "CMP-0007": what HR and the employee refer to it by. */
    public function reference(): string
    {
        return sprintf('CMP-%04d', $this->id);
    }
}
