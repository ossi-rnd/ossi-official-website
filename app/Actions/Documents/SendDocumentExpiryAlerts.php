<?php

namespace App\Actions\Documents;

use App\Enums\Ability;
use App\Models\CompanyDocument;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\DocumentDueNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run daily: licenses and other documents nearing their due date, to Admin
 * (manage-documents). Same rules as the 201 alerts: only the nearest
 * threshold is sent, once per document and due date, so a renewal (a new row
 * with a new date) gets its own alerts and a missed week never sends two.
 */
final class SendDocumentExpiryAlerts
{
    /**
     * @return int how many alerts were sent
     */
    public function handle(CarbonImmutable $today): int
    {
        /** @var list<int> $thresholds */
        $thresholds = config('documents.expiry_alert_days');

        if ($thresholds === []) {
            return 0;
        }

        sort($thresholds);

        $recipients = User::query()->notifiable(Ability::ManageDocuments->roles())->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $documents = CompanyDocument::query()
            ->current()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->addDays((int) max($thresholds))->toDateString())
            ->get();

        $sent = 0;

        foreach ($documents as $document) {
            $daysLeft = (int) $document->daysToExpiry($today);
            $threshold = collect($thresholds)->first(fn (int $days) => $daysLeft <= $days) ?? (int) end($thresholds);
            $alert = sprintf('document.expiry.%d.%s', $threshold, $document->expires_on?->format('Ymd'));

            $sent += DB::transaction(function () use ($alert, $document, $daysLeft, $threshold, $recipients) {
                if (! SentAlert::claim($alert, $document)) {
                    return 0;
                }

                Notification::send($recipients, new DocumentDueNotice($document, $daysLeft, email: $threshold <= 30));

                return 1;
            });
        }

        return $sent;
    }
}
