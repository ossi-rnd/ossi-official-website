<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\PatrolCheckpoint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo patrol checkpoints at the sites with a roving post, and the roster's
 * "Roving" duties marked as patrol duties.
 *
 * Safe on a database that already has data (`php artisan db:seed
 * --class=PatrolSeeder`): sites that already have checkpoints are skipped.
 */
class PatrolSeeder extends Seeder
{
    /** [checkpoint, metres north, metres east] from the site's coordinates. */
    private const CHECKPOINTS = [
        ['Main gate', 40, 0],
        ['Parking B1 ramp', -30, 50],
        ['Lobby guard desk', 0, -20],
        ['Fire exit (back)', -60, -40],
    ];

    public function run(): void
    {
        DutyAssignment::query()->whereRaw('lower(post) like ?', ['%roving%'])->update(['patrols' => true]);

        $sites = Client::query()
            ->whereHas('employeeProfiles', fn ($query) => $query->whereRaw('lower(post) like ?', ['%roving%']))
            ->whereDoesntHave('checkpoints')
            ->get();

        foreach ($sites as $site) {
            foreach (self::CHECKPOINTS as $index => [$name, $north, $east]) {
                $checkpoint = new PatrolCheckpoint;
                $checkpoint->forceFill([
                    'client_id' => $site->id,
                    'name' => $name,
                    'code' => Str::random(32),
                    // ~111,320 m per degree of latitude; good enough for a demo offset.
                    'latitude' => $site->hasGeofence() ? (float) $site->latitude + $north / 111320 : null,
                    'longitude' => $site->hasGeofence() ? (float) $site->longitude + $east / (111320 * cos(deg2rad((float) $site->latitude))) : null,
                    'sort_order' => ($index + 1) * 10,
                ])->save();
            }
        }
    }
}
