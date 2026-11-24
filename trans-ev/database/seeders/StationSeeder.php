<?php

namespace Database\Seeders;

use App\Enums\ChargerType;
use App\Enums\StationStatus;
use App\Models\Company;
use App\Models\Station;
use App\Services\StationStatusService;
use Illuminate\Database\Seeder;

/**
 * Demo network: 13 stations with real-world coordinates and fictional names.
 *
 * Reference public tariffs in India (2026, excl. GST) — confirm before launch:
 *   AC 3.3–7.4 kW ₹10–18 → seed ₹14 · AC 22 kW ₹14–18 → ₹16
 *   DC 30–60 kW ₹18–24 → ₹21 · DC 120 kW+ highway ₹22–26 → ₹24
 *   Session fee ₹20, idle fee ₹2/min after 10 min grace, GST 18% on SAC 998714 (confirm with CA).
 */
class StationSeeder extends Seeder
{
    private const STANDARD_HOURS = ['06:00', '23:00'];

    public function run(): void
    {
        $company = Company::query()->where('is_platform', true)->firstOrFail();

        foreach ($this->stations() as $data) {
            $station = Station::query()->withTrashed()->firstOrNew(['code' => $data['code']]);
            $station->fill([
                'company_id' => $company->id,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'code' => $data['code'],
                'description' => $data['description'],
                'address_line1' => $data['address'],
                'landmark' => $data['landmark'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'state_code' => $data['state_code'],
                'pincode' => $data['pincode'],
                'latitude' => $data['lat'],
                'longitude' => $data['lng'],
                'contact_phone' => '+91-9523095958',
                'contact_email' => 'support@transevrecharge.com',
                'is_24x7' => $data['is_24x7'] ?? false,
                'slot_minutes' => 60,
                'buffer_minutes' => 10,
                'amenities' => $data['amenities'],
                'photos' => [],
                'is_published' => true,
            ])->save();

            $station->operatingHours()->delete();
            foreach (range(0, 6) as $day) {
                [$open, $close] = $data['hours'] ?? self::STANDARD_HOURS;
                $station->operatingHours()->create([
                    'weekday' => $day,
                    'opens_at' => $open,
                    'closes_at' => $close,
                    'is_closed' => false,
                ]);
            }

            $station->chargers()->forceDelete();
            foreach ($data['chargers'] as $i => [$type, $kw, $connectors, $oem]) {
                $station->chargers()->create([
                    'code' => sprintf('%s-%s%02d', $data['code'], $type, $i + 1),
                    'name' => sprintf('%s %s kW #%d', $type, rtrim(rtrim(number_format($kw, 1), '0'), '.'), $i + 1),
                    'oem' => $oem,
                    'type' => $type,
                    'power_kw' => $kw,
                    'connectors' => $connectors,
                ]);
            }

            $station->tariffs()->delete();
            $maxAc = collect($data['chargers'])->where(0, 'AC')->max(1) ?? 0;
            $maxDc = collect($data['chargers'])->where(0, 'DC')->max(1) ?? 0;
            if ($maxAc > 0) {
                $this->tariff($station, ChargerType::AC, $maxAc >= 22 ? '16.00' : '14.00', $maxAc >= 22 ? 'AC 22 kW' : 'AC Standard');
            }
            if ($maxDc > 0) {
                $this->tariff($station, ChargerType::DC, $maxDc >= 120 ? '24.00' : '21.00', $maxDc >= 120 ? 'DC Ultra-fast' : 'DC Fast');
            }

            $status = $data['status'] ?? StationStatus::Online;
            if ($station->fresh()->status !== $status) {
                app(StationStatusService::class)->change(
                    $station,
                    $status,
                    $data['status_reason'] ?? null,
                    isset($data['status_days']) ? now()->addDays($data['status_days']) : null,
                    null,
                    'seeder',
                );
            }
        }
    }

    private function tariff(Station $station, ChargerType $type, string $rate, string $name): void
    {
        $station->tariffs()->create([
            'charger_type' => $type,
            'name' => $name,
            'rate_per_kwh' => $rate,
            'session_fee' => '20.00',
            'idle_fee_per_min' => '2.00',
            'idle_grace_minutes' => 10,
            'gst_rate' => '18.00',
            'sac_code' => '998714',
            'effective_from' => now()->startOfDay()->subDays(30),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function stations(): array
    {
        $ccs = ['ccs2'];
        $type2 = ['type2'];
        $bharatAc = ['bharat_ac001', 'type6'];

        return [
            [
                'code' => 'DEL-CP', 'slug' => 'trans-ev-hub-connaught-place', 'name' => 'Trans EV Hub – Connaught Place',
                'description' => 'Flagship city hub with fast DC and AC charging in the heart of New Delhi.',
                'address' => 'Outer Circle, Block N, Connaught Place', 'landmark' => 'Near Rajiv Chowk Metro Gate 7',
                'city' => 'New Delhi', 'state' => 'Delhi', 'state_code' => '07', 'pincode' => '110001',
                'lat' => 28.6315, 'lng' => 77.2167, 'is_24x7' => true,
                'amenities' => ['Restroom', 'Café', 'Wi-Fi', 'CCTV', 'Covered parking'],
                'chargers' => [['DC', 60, $ccs, 'Exicom'], ['DC', 30, $ccs, 'Delta'], ['AC', 22, $type2, 'Tata AutoComp'], ['AC', 7.4, $type2, 'Tata AutoComp']],
            ],
            [
                'code' => 'GGN-CH', 'slug' => 'trans-ev-cyber-hub-gurugram', 'name' => 'Trans EV – Cyber Hub',
                'description' => 'Office-district charging with dining options within walking distance.',
                'address' => 'DLF Cyber City, Phase 2', 'landmark' => 'Cyber Hub parking P2',
                'city' => 'Gurugram', 'state' => 'Haryana', 'state_code' => '06', 'pincode' => '122002',
                'lat' => 28.4950, 'lng' => 77.0890,
                'amenities' => ['Restroom', 'Restaurants', 'CCTV', 'Security'],
                'chargers' => [['DC', 60, $ccs, 'Exicom'], ['DC', 60, $ccs, 'Exicom'], ['AC', 7.4, $type2, 'Delta']],
            ],
            [
                'code' => 'NOI-S18', 'slug' => 'trans-ev-sector-18-noida', 'name' => 'Trans EV – Sector 18',
                'description' => 'Market-side charging for cars, two- and three-wheelers.',
                'address' => 'Sector 18 Market, Multi-level Parking', 'landmark' => 'Opposite Great India Place',
                'city' => 'Noida', 'state' => 'Uttar Pradesh', 'state_code' => '09', 'pincode' => '201301',
                'lat' => 28.5708, 'lng' => 77.3261,
                'amenities' => ['Restroom', 'Shopping', 'CCTV'],
                'chargers' => [['DC', 30, $ccs, 'Delta'], ['AC', 3.3, $bharatAc, 'Bharat EV'], ['AC', 3.3, $bharatAc, 'Bharat EV']],
            ],
            [
                'code' => 'MUM-BKC', 'slug' => 'trans-ev-bandra-kurla-complex', 'name' => 'Trans EV – Bandra Kurla Complex',
                'description' => 'Business-district fast charging close to the BKC offices.',
                'address' => 'G Block, Bandra Kurla Complex, Bandra (East)', 'landmark' => 'Near Jio World Drive',
                'city' => 'Mumbai', 'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '400051',
                'lat' => 19.0660, 'lng' => 72.8680,
                'hours' => ['07:00', '23:00'],
                'amenities' => ['Restroom', 'Café', 'Wi-Fi', 'Covered parking', 'CCTV'],
                'chargers' => [['DC', 120, $ccs, 'ABB'], ['DC', 60, $ccs, 'Exicom'], ['AC', 22, $type2, 'Delta']],
            ],
            [
                'code' => 'MUM-PWI', 'slug' => 'trans-ev-powai', 'name' => 'Trans EV – Powai',
                'description' => 'Lakeside neighbourhood charging point.',
                'address' => 'Hiranandani Gardens, Powai', 'landmark' => 'Near Galleria Mall',
                'city' => 'Mumbai', 'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '400076',
                'lat' => 19.1176, 'lng' => 72.9060,
                'amenities' => ['Restroom', 'Shopping', 'CCTV'],
                'chargers' => [['DC', 30, $ccs, 'Delta'], ['AC', 7.4, $type2, 'Tata AutoComp']],
                'status' => StationStatus::Maintenance, 'status_reason' => 'Scheduled transformer upgrade', 'status_days' => 2,
            ],
            [
                'code' => 'PUN-HJW', 'slug' => 'trans-ev-hinjewadi-pune', 'name' => 'Trans EV – Hinjewadi',
                'description' => 'IT-park charging hub for daily commuters.',
                'address' => 'Phase 1, Rajiv Gandhi Infotech Park, Hinjewadi', 'landmark' => 'Near Wipro Circle',
                'city' => 'Pune', 'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '411057',
                'lat' => 18.5913, 'lng' => 73.7389,
                'amenities' => ['Restroom', 'Café', 'CCTV'],
                'chargers' => [['DC', 60, $ccs, 'Exicom'], ['AC', 7.4, $type2, 'Delta'], ['AC', 3.3, $bharatAc, 'Bharat EV']],
            ],
            [
                'code' => 'PUN-EXP', 'slug' => 'trans-ev-expressway-hub-lonavala', 'name' => 'Trans EV Highway Hub – Mumbai–Pune Expressway',
                'description' => 'Ultra-fast highway hub, open 24×7, with food court and lounge.',
                'address' => 'Food Mall, Mumbai–Pune Expressway, near Lonavala', 'landmark' => 'Pune-bound side',
                'city' => 'Lonavala', 'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '410401',
                'lat' => 18.7530, 'lng' => 73.4060, 'is_24x7' => true,
                'amenities' => ['Restroom', 'Food court', 'Lounge', 'Wi-Fi', 'CCTV', 'Security'],
                'chargers' => [['DC', 180, $ccs, 'ABB'], ['DC', 120, $ccs, 'ABB'], ['DC', 60, $ccs, 'Exicom'], ['AC', 22, $type2, 'Delta']],
            ],
            [
                'code' => 'BLR-KRM', 'slug' => 'trans-ev-koramangala-bengaluru', 'name' => 'Trans EV – Koramangala',
                'description' => 'Neighbourhood charging close to cafés and start-up offices.',
                'address' => '80 Feet Road, 4th Block, Koramangala', 'landmark' => 'Near Forum Mall junction',
                'city' => 'Bengaluru', 'state' => 'Karnataka', 'state_code' => '29', 'pincode' => '560034',
                'lat' => 12.9352, 'lng' => 77.6245,
                'amenities' => ['Café', 'Wi-Fi', 'CCTV'],
                'chargers' => [['DC', 60, $ccs, 'Exicom'], ['AC', 7.4, $type2, 'Tata AutoComp'], ['AC', 3.3, $bharatAc, 'Bharat EV']],
            ],
            [
                'code' => 'BLR-WTF', 'slug' => 'trans-ev-whitefield-bengaluru', 'name' => 'Trans EV – Whitefield',
                'description' => 'Tech-corridor charging near ITPL.',
                'address' => 'ITPL Main Road, Whitefield', 'landmark' => 'Near Phoenix Marketcity',
                'city' => 'Bengaluru', 'state' => 'Karnataka', 'state_code' => '29', 'pincode' => '560066',
                'lat' => 12.9698, 'lng' => 77.7500,
                'amenities' => ['Restroom', 'Shopping', 'CCTV'],
                'chargers' => [['DC', 60, $ccs, 'Delta'], ['AC', 22, $type2, 'Delta']],
                'status' => StationStatus::Offline, 'status_reason' => 'Grid supply interruption',
            ],
            [
                'code' => 'HYD-HTC', 'slug' => 'trans-ev-hitec-city-hyderabad', 'name' => 'Trans EV – HITEC City',
                'description' => '24×7 fast charging in Hyderabad’s tech district.',
                'address' => 'Madhapur Main Road, HITEC City', 'landmark' => 'Near Cyber Towers',
                'city' => 'Hyderabad', 'state' => 'Telangana', 'state_code' => '36', 'pincode' => '500081',
                'lat' => 17.4435, 'lng' => 78.3772, 'is_24x7' => true,
                'amenities' => ['Restroom', 'Café', 'Wi-Fi', 'CCTV'],
                'chargers' => [['DC', 120, $ccs, 'ABB'], ['DC', 60, $ccs, 'Exicom'], ['AC', 7.4, $type2, 'Delta']],
            ],
            [
                'code' => 'CHN-OMR', 'slug' => 'trans-ev-omr-sholinganallur-chennai', 'name' => 'Trans EV – OMR Sholinganallur',
                'description' => 'IT-expressway charging on Old Mahabalipuram Road.',
                'address' => 'Rajiv Gandhi Salai (OMR), Sholinganallur', 'landmark' => 'Near Sholinganallur junction',
                'city' => 'Chennai', 'state' => 'Tamil Nadu', 'state_code' => '33', 'pincode' => '600119',
                'lat' => 12.9010, 'lng' => 80.2279,
                'amenities' => ['Restroom', 'Café', 'CCTV'],
                'chargers' => [['DC', 60, $ccs, 'Exicom'], ['AC', 7.4, $type2, 'Tata AutoComp']],
            ],
            [
                'code' => 'PAT-BLY', 'slug' => 'trans-ev-bailey-road-patna', 'name' => 'Trans EV – Bailey Road',
                'description' => 'Our home-city hub in Patna with fast DC and two-wheeler charging.',
                'address' => 'Bailey Road, Shastri Nagar', 'landmark' => 'Near Patna Zoo gate',
                'city' => 'Patna', 'state' => 'Bihar', 'state_code' => '10', 'pincode' => '800023',
                'lat' => 25.6120, 'lng' => 85.0880,
                'amenities' => ['Restroom', 'Café', 'CCTV', 'Security'],
                'chargers' => [['DC', 60, $ccs, 'Exicom'], ['AC', 7.4, $type2, 'Delta'], ['AC', 3.3, $bharatAc, 'Bharat EV']],
            ],
            [
                'code' => 'AMD-SGH', 'slug' => 'trans-ev-sg-highway-ahmedabad', 'name' => 'Trans EV – SG Highway',
                'description' => 'Highway-side fast charging in western Ahmedabad.',
                'address' => 'Sarkhej–Gandhinagar Highway, Bodakdev', 'landmark' => 'Near Iscon Cross Road',
                'city' => 'Ahmedabad', 'state' => 'Gujarat', 'state_code' => '24', 'pincode' => '380054',
                'lat' => 23.0300, 'lng' => 72.5070,
                'hours' => ['06:00', '22:00'],
                'amenities' => ['Restroom', 'Restaurants', 'CCTV'],
                'chargers' => [['DC', 120, $ccs, 'ABB'], ['DC', 30, $ccs, 'Delta'], ['AC', 7.4, $type2, 'Delta']],
            ],
        ];
    }
}
