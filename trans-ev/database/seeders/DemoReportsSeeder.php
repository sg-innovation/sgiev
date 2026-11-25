<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Adds report data to an already-seeded demo database: charging sessions with meter readings for completed
 * bookings that have none, and monthly electricity costs per station. Safe to run more than once. Local only.
 *
 *   php artisan db:seed --class=DemoReportsSeeder
 */
class DemoReportsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoReportsSeeder only runs in the local environment.');
        }
        mt_srand(2027);
        $demo = new DemoActivitySeeder;
        $demo->demoSessions();
        $demo->demoEnergyCosts();
        $this->command?->info('Demo charging sessions and electricity costs added.');
    }
}
