<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "I received this": the employee confirms getting the notice, or the decision.
 * Receiving isn't agreeing; it's the proof of service due process needs. Once each.
 */
final class AcknowledgeNotice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, NoticeToExplain $notice): NoticeToExplain
    {
        if ($notice->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        $column = $notice->isDecided() ? 'decision_acknowledged_at' : 'acknowledged_at';

        if ($notice->{$column} === null) {
            $notice->forceFill([$column => CarbonImmutable::now()])->save();
            $this->audit->record(AuditArea::People, $notice->isDecided() ? 'nte.decision_received' : 'nte.received', "{$employee->name} received ".($notice->isDecided() ? 'the decision on' : 'the Notice to Explain:')." {$notice->subject}", $notice, causer: $employee);
        }

        return $notice;
    }
}
