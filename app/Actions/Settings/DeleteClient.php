<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteClient
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Client $client, User $actor): void
    {
        $assigned = $client->employeeProfiles()->count();

        if ($assigned > 0) {
            throw ValidationException::withMessages(['client' => "{$assigned} employee(s) are deployed to {$client->name}. Move them to another client first."]);
        }

        DB::transaction(function () use ($client, $actor) {
            $this->audit->record(AuditArea::Settings, 'client.deleted', "Deleted client {$client->name} ({$client->locationLabel()})", $client, [
                'old' => [...$client->only(['province', 'name']), 'region' => $client->region->value],
            ], causer: $actor);

            $client->delete();
        });
    }
}
