<?php

namespace App\Models;

use App\Enums\LoadRequestStatus;
use App\Support\Acknowledgements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mobile load on credit. Nothing is fillable: SubmitLoadRequest creates it,
 * ReviewLoadRequest approves (creating the payroll deduction) or rejects it,
 * and CancelLoadRequest withdraws it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $network
 * @property string $mobile_number
 * @property int $amount
 * @property LoadRequestStatus $status
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $employee_deduction_id
 * @property string|null $proof_path
 * @property string|null $proof_mime
 * @property int|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $deduction_authorization the written authorization (Art. 113) they ticked, word for word
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read EmployeeDeduction|null $deduction
 */
class LoadRequest extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'approved_by' => null,
        'approved_at' => null,
        'employee_deduction_id' => null,
        'proof_path' => null,
        'proof_mime' => null,
        'rejected_by' => null,
        'rejected_at' => null,
        'rejection_reason' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => LoadRequestStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'authorized_at' => 'datetime',
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
     * The payroll deduction an approval created.
     *
     * @return BelongsTo<EmployeeDeduction, $this>
     */
    public function deduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class, 'employee_deduction_id');
    }

    /**
     * @param  Builder<LoadRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', LoadRequestStatus::open());
    }

    /** The written authorization (Labor Code Art. 113) the employee ticks to borrow; saved with the request. */
    public static function authorizationText(int $amount, string $locale = 'en'): string
    {
        return Acknowledgements::line('load', $locale, ['amount' => number_format($amount)]);
    }

    public function networkLabel(): string
    {
        $networks = (array) config('load.networks');

        return (string) ($networks[$this->network] ?? strtoupper($this->network));
    }

    /**
     * 0917 123 4567: easier to read back over the phone.
     */
    public function formattedNumber(): string
    {
        return substr($this->mobile_number, 0, 4).' '.substr($this->mobile_number, 4, 3).' '.substr($this->mobile_number, 7);
    }

    /**
     * The approver's screenshot of the load confirmation: the employee's receipt.
     */
    public function hasProof(): bool
    {
        return $this->proof_path !== null;
    }

    /**
     * How the deduction appears on the payslip.
     */
    public function deductionLabel(): string
    {
        return "Borrowed load: ₱{$this->amount} {$this->networkLabel()} to {$this->formattedNumber()}";
    }
}
