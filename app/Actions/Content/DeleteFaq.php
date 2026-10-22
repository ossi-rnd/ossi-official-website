<?php

namespace App\Actions\Content;

use App\Enums\AuditArea;
use App\Models\Faq;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class DeleteFaq
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Faq $faq, User $actor): void
    {
        DB::transaction(function () use ($faq, $actor) {
            $this->audit->record(AuditArea::Content, 'faq.deleted', "Deleted FAQ \"{$faq->question}\"", $faq, ['old' => ['question' => $faq->question]], causer: $actor);

            $faq->delete();
        });
    }
}
