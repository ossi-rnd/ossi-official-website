<?php

namespace App\Actions\Auth;

use App\Enums\AuditArea;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Records that the user was shown the current notice. "Acknowledged", never
 * "consented": the legal basis is the employment contract and the law, so
 * this proves the user was informed (RA 10173 sec. 16(a)). See config/privacy.php.
 */
final class AcknowledgePrivacyNotice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user): void
    {
        $version = (string) config('privacy.version');

        DB::transaction(function () use ($user, $version) {
            // forceFill: these columns are the outcome of an event, not user input.
            $user->forceFill([
                'privacy_notice_version' => $version,
                'privacy_acknowledged_at' => now(),
            ])->save();

            $this->audit->record(AuditArea::Auth, 'privacy.acknowledged', "Acknowledged privacy notice {$version}", $user, ['version' => $version], causer: $user);
        });
    }
}
