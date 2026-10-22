<?php

namespace App\Support;

use App\Enums\ComplaintCategory;
use App\Models\Complaint;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The complaint rules HR sets in the app (Complaints → Rules, SaveComplaintRules):
 * how many complaints one employee can file in 7 days. Company policy, not law.
 * Until HR changes it, the starting value in config/complaints.php applies.
 */
final class ComplaintRules
{
    public const SETTING = 'complaints';

    /** Complaints one employee can file in 7 days; 0 = no limit. */
    public static function perWeek(): int
    {
        $saved = Setting::value(self::SETTING);

        return (int) ($saved['per_week'] ?? config('complaints.per_week'));
    }

    /**
     * Where this employee stands, like a token balance: the limit, how many are
     * left in the rolling 7 days, and when the next one frees up. One count for the
     * form's counter and FileComplaint's check, so they can't disagree.
     * Help requests, harassment and resignation cases are never counted.
     *
     * @return array{limit: int, used: Collection<int, Complaint>, left: int|null, nextAt: CarbonImmutable|null}
     */
    public static function allowanceFor(User $employee): array
    {
        $limit = self::perWeek();

        if ($limit === 0) {
            return ['limit' => 0, 'used' => new Collection, 'left' => null, 'nextAt' => null];
        }

        $used = Complaint::query()->where('user_id', $employee->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->where('asks_for_help', false)
            ->where('category', '!=', ComplaintCategory::Harassment)
            ->whereNull('resignation_id')
            ->oldest()
            ->get(['id', 'created_at']);

        $left = max(0, $limit - $used->count());
        // With none left, the oldest counted one frees a token 7 days after it was filed.
        $nextAt = $left === 0 ? $used->first()?->created_at->addDays(7) : null;

        return ['limit' => $limit, 'used' => $used, 'left' => $left, 'nextAt' => $nextAt];
    }
}
