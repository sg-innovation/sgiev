<?php

namespace Database\Seeders;

use App\Enums\ChargerType;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEMO: a second, fictional charging company on the marketplace, to show multi-company (SaaS) behaviour.
 * GSTINs are demo-format, not real registrations.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $company = Company::query()->updateOrCreate(['slug' => 'greengrid-demo'], [
            'legal_name' => 'GreenGrid Charging Private Limited (DEMO)',
            'trade_name' => 'GreenGrid',
            'gstin' => '27AAAAA0000A1Z5',
            'pan' => 'AAAAA0000A',
            'address_line1' => 'Demo Tower, Baner Road',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'state_code' => '27',
            'pincode' => '411045',
            'email' => 'hello@greengrid.test',
            'support_email' => 'support@greengrid.test',
            'support_phone' => '+91 00000 00000',
            'invoice_prefix' => 'GG',
            'sabpaisa_env' => 'staging',
        ]);
        $company->forceFill(['is_platform' => false, 'status' => 'active', 'onboarded_at' => $company->onboarded_at ?? now(), 'plan_id' => \App\Models\Plan::where('slug', 'free')->value('id')])->save();

        $stations = [
            ['code' => 'GG-PUN-BNR', 'slug' => 'greengrid-baner-pune', 'name' => 'GreenGrid – Baner', 'address' => 'Baner Road, near Balewadi High Street',
                'city' => 'Pune', 'pincode' => '411045', 'lat' => 18.5590, 'lng' => 73.7868, 'chargers' => [['DC', 60], ['AC', 22]]],
            ['code' => 'GG-MUM-AND', 'slug' => 'greengrid-andheri-mumbai', 'name' => 'GreenGrid – Andheri East', 'address' => 'Andheri-Kurla Road, Marol',
                'city' => 'Mumbai', 'pincode' => '400059', 'lat' => 19.1136, 'lng' => 72.8697, 'chargers' => [['DC', 30], ['AC', 7.4]]],
        ];

        foreach ($stations as $d) {
            $station = Station::query()->withTrashed()->firstOrNew(['code' => $d['code']]);
            $station->fill([
                'company_id' => $company->id, 'name' => $d['name'], 'slug' => $d['slug'], 'code' => $d['code'],
                'description' => 'Fast charging by GreenGrid, a demo operator on the Trans EV marketplace.',
                'address_line1' => $d['address'], 'city' => $d['city'], 'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => $d['pincode'],
                'latitude' => $d['lat'], 'longitude' => $d['lng'], 'contact_phone' => '+91 00000 00000', 'contact_email' => 'support@greengrid.test',
                'is_24x7' => false, 'slot_minutes' => 60, 'buffer_minutes' => 10, 'amenities' => ['CCTV', 'Café'], 'photos' => [], 'is_published' => true,
            ]);
            $station->forceFill(['approval_status' => 'approved', 'approved_at' => $station->approved_at ?? now()])->save();

            $station->operatingHours()->delete();
            foreach (range(0, 6) as $day) {
                $station->operatingHours()->create(['weekday' => $day, 'opens_at' => '07:00', 'closes_at' => '22:00', 'is_closed' => false]);
            }
            $station->chargers()->forceDelete();
            foreach ($d['chargers'] as $i => [$type, $kw]) {
                $station->chargers()->create([
                    'code' => sprintf('%s-%s%02d', $d['code'], $type, $i + 1), 'name' => "{$type} {$kw} kW", 'oem' => 'Delta',
                    'type' => $type, 'power_kw' => $kw, 'connectors' => $type === 'DC' ? ['ccs2'] : ['type2'],
                ]);
            }
            $station->tariffs()->delete();
            foreach ([ChargerType::DC->value => '19.50', ChargerType::AC->value => '13.00'] as $type => $rate) {
                $station->tariffs()->create([
                    'charger_type' => $type, 'name' => $type === 'DC' ? 'GreenGrid DC' : 'GreenGrid AC', 'rate_per_kwh' => $rate,
                    'session_fee' => '15.00', 'idle_fee_per_min' => '2.00', 'idle_grace_minutes' => 10, 'gst_rate' => '18.00', 'sac_code' => '998714',
                    'effective_from' => now()->startOfDay()->subDays(60),
                ]);
            }
        }

        $password = env('SEED_DEMO_PASSWORD');
        if (filled($password)) {
            $owner = User::query()->firstOrCreate(['email' => 'owner@greengrid.test'], ['name' => 'GreenGrid Owner', 'password' => $password, 'email_verified_at' => now()]);
            $owner->forceFill(['company_id' => $company->id, 'email_verified_at' => $owner->email_verified_at ?? now()])->save();
            $owner->syncRoles([Role::CompanyOwner->value]);
        }
    }
}
