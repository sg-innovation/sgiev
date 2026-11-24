<?php

namespace App\Services;

use App\Models\Charger;
use App\Models\Station;
use App\Models\Tariff;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Pre-payment estimate for a booking. The customer pays this estimate up front;
 * Phase 4 adds the post-session true-up on actual kWh.
 */
class PricingService
{
    public function __construct(private readonly GstService $gst) {}

    /** The tariff in force at the slot start (a future price change applies to slots after it takes effect). */
    public function tariffFor(Station $station, Charger $charger, ?CarbonInterface $at = null): Tariff
    {
        return $station->currentTariff($charger->type->value, $charger->id, $at)
            ?? throw new RuntimeException('No active tariff for this charger.');
    }

    /**
     * @return array<string, mixed>
     */
    public function estimate(Station $station, Charger $charger, string $batteryKwh, int $from, int $to, int $durationMinutes, ?CarbonInterface $at = null, ?\App\Models\Coupon $coupon = null, ?string $actualKwh = null): array
    {
        $tariff = $this->tariffFor($station, $charger, $at);

        // Energy the battery needs, grossed up for charging losses.
        $needed = bcdiv(bcmul($batteryKwh, bcdiv((string) max(0, $to - $from), '100', 6), 6), (string) \App\Support\BusinessRules::get('pricing.charging_efficiency'), 6);
        // Energy the charger can deliver in the slot.
        $deliverable = bcmul(bcmul((string) $charger->power_kw, (string) \App\Support\BusinessRules::get('pricing.power_utilisation'), 6), bcdiv((string) $durationMinutes, '60', 6), 6);
        $kwh = bccomp($needed, $deliverable, 6) <= 0 ? $needed : $deliverable;
        // Walk-in bills use the energy actually delivered.
        if ($actualKwh !== null) {
            $kwh = $actualKwh;
            $needed = $deliverable = $actualKwh;
        }
        $kwh = GstService::round($kwh, 3);

        // Energy is shared across the slot's time bands (peak / off-peak) in proportion to the minutes in each.
        // Without a slot time yet, the standard rate is shown.
        $lines = [];
        $segments = $at ? $tariff->segments($at, $durationMinutes) : [['name' => 'Standard', 'rate' => number_format((float) $tariff->rate_per_kwh, 2, '.', ''), 'minutes' => $durationMinutes]];
        $total = max(1, array_sum(array_column($segments, 'minutes')));
        $energy = '0.00';
        $kwhLeft = $kwh;
        foreach ($segments as $i => $seg) {
            $share = $i === array_key_last($segments) ? $kwhLeft : GstService::round(bcdiv(bcmul($kwh, (string) $seg['minutes'], 6), (string) $total, 6), 3);
            $kwhLeft = bcsub($kwhLeft, $share, 3);
            $amount = GstService::round(bcmul($share, $seg['rate'], 6));
            $energy = bcadd($energy, $amount, 2);
            $lines[] = ['type' => 'energy', 'label' => count($segments) > 1 || $seg['name'] !== 'Standard' ? "Energy · {$seg['name']}" : 'Energy',
                'qty' => $share, 'unit' => 'kWh', 'rate' => $seg['rate'], 'amount' => $amount, 'sac' => $tariff->sac_code, 'minutes' => $seg['minutes']];
        }
        $sessionFee = (string) $tariff->session_fee;
        if (bccomp($sessionFee, '0', 2) > 0) {
            $lines[] = ['type' => 'session_fee', 'label' => 'Booking fee', 'qty' => '1', 'unit' => 'Nos', 'rate' => $sessionFee, 'amount' => $sessionFee, 'sac' => $tariff->sac_code];
        }
        $parking = bcadd((string) $tariff->parking_fee, GstService::round(bcmul((string) $tariff->parking_fee_per_hour, bcdiv((string) $durationMinutes, '60', 6), 6)), 2);
        if (bccomp($parking, '0', 2) > 0) {
            $lines[] = ['type' => 'parking', 'label' => 'Parking', 'qty' => bcdiv((string) $durationMinutes, '60', 2), 'unit' => 'hours', 'rate' => (string) $tariff->parking_fee_per_hour,
                'amount' => $parking, 'sac' => $tariff->parking_sac ?: '996743'];
        }
        $subtotal = bcadd(bcadd($energy, $sessionFee, 2), $parking, 2);
        // A discount shown on the invoice at the time of supply reduces the GST taxable value.
        $discount = $coupon ? $coupon->discountOn($subtotal) : '0.00';
        $taxable = bcsub($subtotal, $discount, 2);

        $supplier = $this->gst->supplierStateCode($station);
        $pos = $this->gst->placeOfSupply($station);
        $tax = $this->gst->split($taxable, (string) $tariff->gst_rate, $supplier, $pos);

        $minutesNeeded = (int) ceil((float) bcmul(bcdiv($needed, bcmul((string) $charger->power_kw, (string) \App\Support\BusinessRules::get('pricing.power_utilisation'), 6), 6), '60', 2));

        return [
            'tariff' => $tariff,
            'kwh' => $kwh,
            'capped_by_slot' => bccomp($needed, $deliverable, 6) > 0,
            'minutes_needed' => $minutesNeeded,
            // Blended rate across bands (each band is listed in 'lines').
            'rate_per_kwh' => bccomp($kwh, '0', 3) > 0 ? GstService::round(bcdiv($energy, $kwh, 6)) : (string) $tariff->rate_per_kwh,
            'time_of_day' => $tariff->hasTimeBands(),
            'lines' => $lines,
            'parking_amount' => $parking,
            'energy_amount' => $energy,
            'session_fee' => $sessionFee,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'coupon_code' => $coupon?->code,
            'coupon_headline' => $coupon?->headline(),
            'discount_funded_by' => $coupon?->fundedBy(),
            'coupon' => $coupon,
            'taxable_amount' => $taxable,
            'gst_rate' => (string) $tariff->gst_rate,
            'cgst_amount' => $tax['cgst'],
            'sgst_amount' => $tax['sgst'],
            'igst_amount' => $tax['igst'],
            'total_tax' => $tax['total_tax'],
            'intra_state' => $tax['intra_state'],
            'total_amount' => bcadd($taxable, $tax['total_tax'], 2),
            'sac_code' => $tariff->sac_code,
            'place_of_supply' => $pos,
            'supplier_state_code' => $supplier,
            'idle_fee_per_min' => (string) $tariff->idle_fee_per_min,
            'idle_grace_minutes' => $tariff->idle_grace_minutes,
        ];
    }

    /** JSON-safe version for the frontend. */
    public static function present(array $e): array
    {
        // Keep state codes like "06" as strings; convert money/energy strings to numbers for the UI.
        $e['lines'] = collect($e['lines'] ?? [])->map(fn ($l) => array_merge($l, ['qty' => (float) $l['qty'], 'rate' => (float) $l['rate'], 'amount' => (float) $l['amount']]))->all();

        return collect($e)->except(['tariff', 'coupon'])->map(fn ($v, $k) => is_string($v) && is_numeric($v) && ! in_array($k, ['place_of_supply', 'supplier_state_code', 'sac_code', 'coupon_code'], true) ? (float) $v : $v)
            ->put('tariff_name', $e['tariff']->name)->all();
    }
}
