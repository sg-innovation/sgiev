<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\StationStatus;
use App\Models\Booking;
use App\Models\RuleAcceptance;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LOCAL DEMO DATA ONLY: ~45 days of fake booking history so the admin dashboard charts have something to show.
 * Customers use @demo.transevrecharge.test emails and every booking is tagged in customer_snapshot['demo'].
 * Refuses to run outside the local environment. Remove with: php artisan migrate:fresh --seed
 */
class DemoActivitySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoActivitySeeder only runs in the local environment.');
        }

        mt_srand(2026);
        $pricing = app(PricingService::class);
        $names = ['Aarav Sharma', 'Priya Singh', 'Rohan Gupta', 'Ananya Verma', 'Vikram Rao', 'Sneha Iyer', 'Kunal Mehta', 'Neha Joshi',
            'Arjun Nair', 'Pooja Kumari', 'Rahul Das', 'Isha Patel', 'Siddharth Jain', 'Kavya Reddy', 'Aditya Mishra', 'Riya Sinha'];
        $vehicles = [['Tata', 'Nexon EV', '40.50'], ['MG', 'ZS EV', '50.30'], ['Mahindra', 'XUV400', '39.40'], ['Tata', 'Punch EV', '35.00'], ['Hyundai', 'Creta Electric', '51.40'], ['BYD', 'Atto 3', '60.48']];

        $customers = collect($names)->map(function ($name, $i) {
            $u = User::query()->firstOrCreate(
                ['email' => Str::slug($name, '.').'@demo.transevrecharge.test'],
                ['name' => $name, 'password' => Hash::make(Str::random(32)), 'phone' => '98'.str_pad((string) (10000000 + $i * 7919), 8, '0', STR_PAD_LEFT), 'email_verified_at' => now()],
            );
            $u->syncRoles([Role::Customer->value]);

            return $u;
        });

        $stations = Station::query()->with(['chargers', 'operatingHours', 'holidayOverrides'])->get()
            ->filter(fn (Station $s) => $s->status === StationStatus::Online);
        $rules = RuleSet::query()->where('is_active', true)->whereNull('station_id')->firstOrFail();
        // Busier stations get more weight.
        $weights = $stations->mapWithKeys(fn ($s) => [$s->id => $s->chargers->where('type.value', 'DC')->count() * 3 + $s->chargers->count()]);

        $peakHours = [7, 8, 9, 10, 12, 13, 17, 18, 19, 20, 21, 11, 14, 15, 16, 18, 19, 20];
        $created = 0;

        for ($dayOffset = 45; $dayOffset >= -3; $dayOffset--) {
            $day = now(Station::TIMEZONE)->startOfDay()->subDays($dayOffset);
            $perDay = $dayOffset < 0 ? mt_rand(2, 5) : mt_rand(6, 14) + (int) round((45 - $dayOffset) / 6); // gentle growth

            for ($n = 0; $n < $perDay; $n++) {
                $station = $this->weighted($stations, $weights);
                $charger = $station->chargers->random();
                $hour = $station->is_24x7 ? $peakHours[array_rand($peakHours)] : max(7, min(21, $peakHours[array_rand($peakHours)]));
                $start = $day->copy()->setTime($hour, 0)->utc();
                $end = $start->copy()->addMinutes($station->slot_minutes);
                $blocked = $end->copy()->addMinutes($station->buffer_minutes);

                if (Booking::query()->where('charger_id', $charger->id)->where('start_at', '<', $blocked)->where('blocked_until', '>', $start)->exists()) {
                    continue;
                }

                [$make, $model, $battery] = $vehicles[array_rand($vehicles)];
                $connector = in_array('ccs2', $charger->connectors, true) ? 'ccs2' : $charger->connectors[0];
                $from = mt_rand(10, 45);
                $to = mt_rand(70, 95);
                try {
                    $e = $pricing->estimate($station, $charger, $battery, $from, $to, $station->slot_minutes);
                } catch (RuntimeException) {
                    continue;
                }

                $status = $dayOffset < 0 ? BookingStatus::Confirmed : $this->pickStatus();
                $customer = $customers->random();

                $booking = Booking::create([
                    'company_id' => $station->company_id, 'user_id' => $customer->id, 'station_id' => $station->id, 'charger_id' => $charger->id, 'tariff_id' => $e['tariff']->id,
                    'connector' => $connector, 'start_at' => $start, 'end_at' => $end, 'blocked_until' => $blocked,
                    'status' => $status, 'hold_expires_at' => $start->copy()->subHours(2),
                    'battery_from' => $from, 'battery_to' => $to, 'est_kwh' => $e['kwh'], 'rate_per_kwh' => $e['rate_per_kwh'],
                    'energy_amount' => $e['energy_amount'], 'session_fee' => $e['session_fee'], 'taxable_amount' => $e['taxable_amount'],
                    'gst_rate' => $e['gst_rate'], 'cgst_amount' => $e['cgst_amount'], 'sgst_amount' => $e['sgst_amount'], 'igst_amount' => $e['igst_amount'],
                    'total_amount' => $e['total_amount'], 'sac_code' => $e['sac_code'], 'place_of_supply' => $e['place_of_supply'],
                    'supplier_state_code' => $e['supplier_state_code'],
                    'customer_snapshot' => ['name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'gstin' => null, 'state_code' => $station->state_code, 'demo' => true],
                    'vehicle_snapshot' => ['make' => $make, 'model' => $model, 'registration_no' => strtoupper($station->state_code === '10' ? 'BR01' : 'MH12').'DM'.mt_rand(1000, 9999), 'battery_kwh' => $battery, 'connector' => $connector],
                    'rule_set_id' => $rules->id,
                    'confirmed_at' => in_array($status, [BookingStatus::Expired], true) ? null : $start->copy()->subHours(3),
                    'cancelled_at' => $status === BookingStatus::Cancelled ? $start->copy()->subHours(1) : null,
                    'cancel_reason' => $status === BookingStatus::Cancelled ? 'Cancelled by customer' : null,
                    'created_at' => $start->copy()->subHours(mt_rand(3, 30)),
                ]);

                RuleAcceptance::create([
                    'user_id' => $customer->id, 'booking_id' => $booking->id, 'rule_set_id' => $rules->id, 'version' => $rules->version,
                    'content_hash' => hash('sha256', $rules->content), 'ip' => '127.0.0.1', 'user_agent' => 'demo-seeder', 'accepted_at' => $booking->created_at,
                ]);
                self::demoPayment($booking);
                $created++;
            }
        }

        $this->command?->info("Created {$created} demo bookings (local only).");
    }

    /**
     * Simulator payment for a demo booking that was paid (so receipts and payment screens work in the demo).
     * Marked environment=simulator, so receipts print the TEST PAYMENT banner.
     */
    public static function demoPayment(Booking $booking): void
    {
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled], true)
            || $booking->payments()->exists()) {
            return;
        }
        $paidAt = ($booking->confirmed_at ?? $booking->created_at)->copy();
        $modes = ['UPI', 'UPI', 'UPI', 'Debit card', 'Credit card', 'Net banking'];
        \App\Models\Payment::query()->forceCreate([
            'company_id' => $booking->company_id, 'booking_id' => $booking->id, 'user_id' => $booking->user_id,
            'gateway' => 'sabpaisa', 'environment' => 'simulator',
            'merchant_txn_id' => 'DEMO'.$booking->id.strtoupper(\Illuminate\Support\Str::random(6)),
            'gateway_txn_id' => 'SIM'.mt_rand(100000000, 999999999),
            'amount' => $booking->total_amount, 'paid_amount' => $booking->total_amount, 'currency' => 'INR',
            'status' => \App\Models\Payment::SUCCESS, 'gateway_status' => 'SUCCESS',
            'payment_mode' => $modes[array_rand($modes)],
            'bank_txn_id' => 'BNK'.mt_rand(10000000, 99999999), 'bank_rrn' => (string) mt_rand(100000000000, 999999999999),
            'paid_at' => $paidAt, 'verified_via' => 'simulator',
            'created_at' => $paidAt->copy()->subMinutes(2), 'updated_at' => $paidAt,
        ]);
    }

    private function pickStatus(): BookingStatus
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 78 => BookingStatus::Completed,
            $r <= 88 => BookingStatus::Cancelled,
            $r <= 95 => BookingStatus::Expired,
            default => BookingStatus::NoShow,
        };
    }

    private function weighted($stations, $weights): Station
    {
        $total = $weights->sum();
        $pick = mt_rand(1, max(1, $total));
        foreach ($stations as $s) {
            $pick -= $weights[$s->id];
            if ($pick <= 0) {
                return $s;
            }
        }

        return $stations->first();
    }
}
