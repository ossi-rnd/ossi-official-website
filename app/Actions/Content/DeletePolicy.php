<?php

namespace App\Actions\Content;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\CompanyPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeletePolicy
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CompanyPolicy $policy, User $actor): void
    {
        if (! $actor->can(Ability::ManagePolicies->value)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($policy, $actor) {
            $this->audit->record(AuditArea::Content, 'policy.deleted', "Deleted company policy \"{$policy->title}\"", $policy, ['old' => ['title' => $policy->title]], causer: $actor);
            $policy->delete();
        });

        $this->uploads->delete((string) config('documents.disk'), $policy->file_path);
    }
}
