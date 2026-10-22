<?php

namespace App\Actions\Complaints;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ComplaintCategory;
use App\Models\ComplaintSubject;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR's list of common complaint subjects per category: add one, rename it, or
 * switch it off. Never deleted: complaints copy the words, so history doesn't change.
 */
final class SaveComplaintSubject
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, ?ComplaintSubject $subject, ComplaintCategory $category, string $label, bool $active = true): ComplaintSubject
    {
        if (! $actor->can(Ability::HandleComplaints->value)) {
            throw new AuthorizationException;
        }

        $label = trim($label);

        if (mb_strlen($label) < 3 || mb_strlen($label) > 150) {
            throw ValidationException::withMessages(['subjectLabel' => 'From 3 to 150 characters.']);
        }

        $duplicate = ComplaintSubject::query()->where('category', $category)->whereRaw('lower(label) = ?', [mb_strtolower($label)])
            ->when($subject !== null, fn ($query) => $query->whereKeyNot($subject?->id))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['subjectLabel' => "\"{$label}\" is already under {$category->label()}."]);
        }

        $subject ??= new ComplaintSubject;
        $creating = ! $subject->exists;

        $subject->forceFill([
            'category' => $category,
            'label' => $label,
            'is_active' => $active,
            // New ones go last in their category.
            'sort' => $creating ? (int) ComplaintSubject::query()->where('category', $category)->max('sort') + 1 : $subject->sort,
        ])->save();

        $this->audit->record(AuditArea::Complaints, $creating ? 'complaint_subject.created' : 'complaint_subject.updated', ($creating ? 'Added' : 'Changed')." the complaint subject \"{$label}\" ({$category->label()}".($active ? '' : ', off').')', $subject, causer: $actor);

        return $subject;
    }
}
