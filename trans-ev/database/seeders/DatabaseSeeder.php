<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BusinessRulesSeeder::class,
            CompanySeeder::class,
            StationSeeder::class,
            RuleSetSeeder::class,
        ]);

        // Local/demo accounts. Passwords come from .env (see .env.example); never use these in production.
        $password = env('SEED_DEMO_PASSWORD');
        if (app()->isProduction() || blank($password)) {
            $this->command?->warn('SEED_DEMO_PASSWORD not set (or production): demo users skipped.');
            $this->call([BlogSeeder::class, TicketSeeder::class]);

            return;
        }

        $platform = Company::query()->where('is_platform', true)->firstOrFail();
        $accounts = [
            // [email, name, role, belongs to the platform company?]
            ['admin@transevrecharge.test', 'Super Admin', Role::SuperAdmin, false],
            ['owner@transevrecharge.test', 'Trans EV Owner', Role::CompanyOwner, true],
            ['ops@transevrecharge.test', 'Operations Admin', Role::OperationsAdmin, true],
            ['finance@transevrecharge.test', 'Finance User', Role::Finance, true],
            ['manager@transevrecharge.test', 'Station Manager', Role::StationManager, true],
            ['auditor@transevrecharge.test', 'Auditor', Role::Auditor, true],
            ['customer@transevrecharge.test', 'Demo Customer', Role::Customer, false],
        ];

        foreach ($accounts as [$email, $name, $role, $staff]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['company_id' => $staff ? $platform->id : null])->save();
            $user->syncRoles([$role->value]);
        }

        // The demo station manager looks after the two Delhi NCR hubs.
        User::where('email', 'manager@transevrecharge.test')->first()
            ->stations()->sync(Station::whereIn('code', ['DEL-CP', 'NOI-S18'])->pluck('id'));

        $this->call([BlogSeeder::class, DemoCompanySeeder::class, CouponSeeder::class]);
        $this->call(TicketSeeder::class);
    }
}
