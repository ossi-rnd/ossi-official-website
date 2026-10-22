<?php

namespace App\Actions\Content;

use App\Enums\AuditArea;
use App\Models\Faq;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class SaveFaq
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{question: string, answer: string, sort_order: int, is_published: bool}  $data
     */
    public function handle(?Faq $faq, array $data, User $actor): Faq
    {
        return DB::transaction(function () use ($faq, $data, $actor) {
            $isNew = $faq === null;
            $faq ??= new Faq;
            $fields = ['question', 'answer', 'sort_order', 'is_published'];
            $before = $isNew ? [] : $faq->only($fields);

            $faq->fill($data)->save();

            $this->audit->record(AuditArea::Content, $isNew ? 'faq.created' : 'faq.updated', "Saved FAQ \"{$faq->question}\"", $faq, AuditLogger::diff($before, $faq->only($fields)), causer: $actor);

            return $faq;
        });
    }
}
