<?php

namespace Database\Seeders;

use App\Enums\Region;
use App\Models\Client;
use App\Models\EmployeeProfile;
use Illuminate\Database\Seeder;

/**
 * Sample client sites by Region → Province → Client, each with a geofence
 * (approximate coordinates, 150 m), and links guards whose typed detachment
 * matches a client's name.
 *
 * Safe on a database that already has data (`php artisan db:seed
 * --class=ClientSeeder`): existing clients and links are left alone.
 */
class ClientSeeder extends Seeder
{
    /** [region, province, client, site address, latitude, longitude] (approximate demo coordinates) */
    private const CLIENTS = [
        [Region::Ncr, 'Mandaluyong', 'SM Megamall', 'EDSA cor. Doña Julia Vargas Ave., Ortigas Center', 14.5850, 121.0566],
        [Region::Ncr, 'Taguig', 'BGC Tower One', '5th Ave. cor. 32nd St., Bonifacio Global City', 14.5509, 121.0503],
        [Region::Ncr, 'Pasig', 'Ortigas CBD', 'ADB Ave., Ortigas Center', 14.5869, 121.0614],
        [Region::Ncr, 'Makati', 'Makati CBD', 'Ayala Ave., Makati', 14.5547, 121.0244],
        [Region::Ncr, 'Muntinlupa', 'Alabang Town Center', 'Alabang–Zapote Rd., Ayala Alabang', 14.4235, 121.0300],
        [Region::Ncr, 'Quezon City', 'Eastwood City', 'Eastwood Ave., Bagumbayan', 14.6091, 121.0806],
        [Region::Calabarzon, 'Cavite', 'Metrobank – Imus', 'Aguinaldo Hwy., Imus', 14.4297, 120.9367],
        [Region::Calabarzon, 'Cavite', 'Metrobank – Dasmariñas', 'Aguinaldo Hwy., Dasmariñas', 14.3294, 120.9367],
        [Region::Calabarzon, 'Laguna', 'SM City Sta. Rosa', 'Old National Hwy., Santa Rosa', 14.3123, 121.1114],
        [Region::CentralVisayas, 'Cebu', 'Ayala Center Cebu', 'Cebu Business Park, Cebu City', 10.3181, 123.9050],
    ];

    public function run(): void
    {
        foreach (self::CLIENTS as [$region, $province, $name, $address, $latitude, $longitude]) {
            $client = Client::query()->firstOrCreate(
                ['region' => $region->value, 'province' => $province, 'name' => $name],
                ['site_address' => $address],
            );

            // A geofence for the demo, without overwriting one set on the Clients page.
            if (! $client->hasGeofence()) {
                $client->forceFill(['latitude' => $latitude, 'longitude' => $longitude])->save();
            }

            EmployeeProfile::query()->whereNull('client_id')->where('detachment', $name)->update(['client_id' => $client->id]);
        }
    }
}
