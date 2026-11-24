<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Booking> */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [];
    }

    /** A confirmed booking tomorrow at noon (IST) on the station's first charger. */
    public function forStation(Station $station, User $customer): static
    {
        return $this->state(function () use ($station, $customer) {
            $charger = $station->chargers()->first();
            $tariff = $station->currentTariff($charger->type->value, $charger->id);
            $start = now('Asia/Kolkata')->addDay()->setTime(12, 0)->utc();

            return [
                'company_id' => $station->company_id,
                'user_id' => $customer->id,
                'station_id' => $station->id,
                'charger_id' => $charger->id,
                'tariff_id' => $tariff->id,
                'connector' => $charger->connectors[0],
                'start_at' => $start,
                'end_at' => $start->copy()->addHour(),
                'blocked_until' => $start->copy()->addMinutes(70),
                'status' => BookingStatus::Confirmed,
                'battery_from' => 20,
                'battery_to' => 80,
                'est_kwh' => '20.000',
                'rate_per_kwh' => $tariff->rate_per_kwh,
                'energy_amount' => '400.00',
                'session_fee' => '20.00',
                'taxable_amount' => '420.00',
                'gst_rate' => '18.00',
                'cgst_amount' => '37.80',
                'sgst_amount' => '37.80',
                'igst_amount' => '0.00',
                'total_amount' => '495.60',
                'sac_code' => '998714',
                'place_of_supply' => $station->state_code,
                'supplier_state_code' => $station->state_code,
                'customer_snapshot' => ['name' => $customer->name, 'email' => $customer->email, 'phone' => '9876543210', 'gstin' => null, 'state_code' => $station->state_code],
                'vehicle_snapshot' => ['make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'DL01AB1234', 'battery_kwh' => '40'],
                'rule_set_id' => RuleSet::activeFor($station)->id,
                'confirmed_at' => now(),
            ];
        });
    }
}
