<?php

namespace Database\Seeders;

use App\Models\Lookup;
use Illuminate\Database\Seeder;

/** First-install lists & options. Existing rows are left as edited in admin. */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            'kyc_document' => [
                ['gst_certificate', 'GST registration certificate', ['required' => false, 'on_registration' => true]],
                ['pan_card', 'Company PAN card', ['required' => true, 'on_registration' => true]],
                ['address_proof', 'Business address proof', ['required' => true, 'on_registration' => true]],
                ['cancelled_cheque', 'Cancelled cheque / bank letter', ['required' => true, 'on_registration' => true]],
                ['incorporation', 'Certificate of incorporation', ['required' => false, 'on_registration' => false]],
                ['other', 'Other document', ['required' => false, 'on_registration' => false]],
            ],
            'amenity' => collect(['Restroom', 'Café', 'Wi-Fi', 'CCTV', 'Covered parking', 'Food court', 'Lounge', 'Security', 'Shopping', 'Restaurants'])
                ->map(fn ($a) => [\Illuminate\Support\Str::slug($a), $a, null])->all(),
            'parking_type' => [
                ['free', 'Free while charging', null], ['paid', 'Paid parking', null],
                ['customers', 'Customers only', null], ['street', 'Street parking', null],
            ],
            'contact_topic' => [
                ['booking', 'Booking or charging session', null], ['payment', 'Payment, refund or invoice', null],
                ['station', 'Station issue or fault', null], ['fleet', 'Fleet / business charging', null],
                ['partner', 'Host a charger / partnership', null], ['feedback', 'Feedback or suggestion', null], ['other', 'Something else', null],
            ],
            'ev_model' => [
                ['tata-nexon-ev', 'Tata Nexon EV', ['make' => 'Tata', 'model' => 'Nexon EV', 'battery_kwh' => 40.5, 'connector' => 'ccs2']],
                ['tata-punch-ev', 'Tata Punch EV', ['make' => 'Tata', 'model' => 'Punch EV', 'battery_kwh' => 35, 'connector' => 'ccs2']],
                ['mg-zs-ev', 'MG ZS EV', ['make' => 'MG', 'model' => 'ZS EV', 'battery_kwh' => 50.3, 'connector' => 'ccs2']],
                ['mahindra-xuv400', 'Mahindra XUV400', ['make' => 'Mahindra', 'model' => 'XUV400', 'battery_kwh' => 39.4, 'connector' => 'ccs2']],
                ['hyundai-creta-electric', 'Hyundai Creta Electric', ['make' => 'Hyundai', 'model' => 'Creta Electric', 'battery_kwh' => 51.4, 'connector' => 'ccs2']],
                ['byd-atto-3', 'BYD Atto 3', ['make' => 'BYD', 'model' => 'Atto 3', 'battery_kwh' => 60.5, 'connector' => 'ccs2']],
            ],
        ];

        foreach ($rows as $type => $items) {
            foreach ($items as $i => [$value, $label, $meta]) {
                Lookup::query()->firstOrCreate(['type' => $type, 'value' => $value], ['label' => $label, 'meta' => $meta, 'sort' => $i + 1, 'is_active' => true]);
            }
        }
        Lookup::flush();
    }
}
