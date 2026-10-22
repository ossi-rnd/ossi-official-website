<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section of the Company Policy page, read by every account. The text is
 * shown as plain paragraphs; a signed or official copy can be attached as a PDF.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property int $sort_order
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $editor
 */
#[Fillable(['title', 'body', 'sort_order'])]
class CompanyPolicy extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'file_path' => null,
        'file_name' => null,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
