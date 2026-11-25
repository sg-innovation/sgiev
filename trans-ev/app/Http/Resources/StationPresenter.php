<?php

namespace App\Http\Resources;

use App\Enums\ChargerType;
use App\Enums\ConnectorType;
use App\Enums\StationStatus;
use App\Models\OperatingHour;
use App\Models\Station;
use App\Models\Tariff;

/** Public-facing shapes for station data (only what customers need; no internal ids beyond slug). */
final class StationPresenter
{
    public static function card(Station $station): array
    {
        $chargers = $station->chargers;
        $online = $chargers->where('status', StationStatus::Online);
        $open = $station->isOpenAt();
        $tariffs = $station->relationLoaded('tariffs') ? $station->tariffs : collect();
        $minRate = $tariffs->filter(fn (Tariff $t) => $t->isCurrent())->min('rate_per_kwh');

        return [
            'slug' => $station->slug,
            'name' => $station->name,
            'code' => $station->code,
            'operator' => [
                'name' => $station->company?->trade_name,
                'isPlatform' => (bool) $station->company?->is_platform,
            ],
            'address' => trim("{$station->address_line1}, {$station->city}"),
            'city' => $station->city,
            'state' => $station->state,
            'lat' => (float) $station->latitude,
            'lng' => (float) $station->longitude,
            'distanceKm' => isset($station->distance_km) ? (float) $station->distance_km : null,
            'status' => $station->status->value,
            'statusLabel' => $station->status->getLabel(),
            'statusReason' => $station->status === StationStatus::Online ? null : $station->status_reason,
            'statusUntil' => $station->status_until?->toIso8601String(),
            'isOpen' => $open,
            'is24x7' => $station->is_24x7,
            'todayHours' => self::todayHours($station),
            'bookable' => $station->status->isBookable() && $open && $online->isNotEmpty(),
            'chargersTotal' => $chargers->count(),
            'chargersOnline' => $online->count(),
            'guns' => self::connectorCounts($station),
            'maxPowerKw' => (float) $chargers->max('power_kw'),
            'types' => $chargers->pluck('type')->unique()->map(fn (ChargerType $t) => $t->value)->values(),
            'connectors' => $chargers->pluck('connectors')->flatten()->unique()->values()
                ->map(fn ($c) => ['value' => $c, 'label' => ConnectorType::tryFrom($c)?->getLabel() ?? $c]),
            'fromRate' => $minRate !== null ? (float) $minRate : null,
            'amenities' => $station->amenities ?? [],
            'rating' => $station->rating_count > 0 ? ['avg' => round((float) $station->rating_avg, 1), 'count' => (int) $station->rating_count] : null,
        ];
    }

    public static function detail(Station $station): array
    {
        $tariffs = $station->tariffs->filter(fn (Tariff $t) => $t->isCurrent())->values();

        return self::card($station) + [
            'description' => $station->description,
            'landmark' => $station->landmark,
            'fullAddress' => collect([$station->address_line1, $station->address_line2, $station->landmark, "{$station->city}, {$station->state} {$station->pincode}"])->filter()->implode(', '),
            'phone' => $station->contact_phone ?: $station->company?->support_phone,
            'email' => $station->contact_email ?: $station->company?->support_email,
            'operatorLegalName' => $station->company?->legal_name,
            'slotMinutes' => $station->slot_minutes,
            'photos' => collect($station->photos ?? [])->filter(fn ($p) => is_string($p) && $p !== '')
                ->map(fn (string $p) => str_starts_with($p, 'http') || str_starts_with($p, '/') ? $p : \Illuminate\Support\Facades\Storage::disk('public')->url($p))->values(),
            'parking' => $station->parking_available ? [
                'type' => \App\Models\Lookup::label('parking_type', $station->parking_type),
                'note' => $station->parking_note,
            ] : null,
            'hours' => $station->is_24x7 ? [] : $station->operatingHours->map(fn (OperatingHour $h) => [
                'day' => $h->day_name,
                'weekday' => $h->weekday,
                'closed' => $h->is_closed,
                'opens' => $h->opens_at ? substr($h->opens_at, 0, 5) : null,
                'closes' => $h->closes_at ? substr($h->closes_at, 0, 5) : null,
            ])->values(),
            'holidays' => $station->holidayOverrides->filter(fn ($h) => $h->date->gte(today()))->take(5)->map(fn ($h) => [
                'date' => $h->date->toDateString(),
                'label' => $h->label,
                'closed' => $h->is_closed,
                'opens' => $h->opens_at ? substr($h->opens_at, 0, 5) : null,
                'closes' => $h->closes_at ? substr($h->closes_at, 0, 5) : null,
            ])->values(),
            'chargers' => $station->chargers->map(fn ($c) => [
                'units' => $c->relationLoaded('connectorPoints') ? $c->connectorPoints->map(fn ($k) => [
                    'no' => $k->connector_no, 'type' => $k->type->getLabel(), 'live' => $k->live_status->value, 'liveLabel' => $k->live_status->getLabel(),
                ])->values() : [],
                'code' => $c->code,
                'name' => $c->name,
                'electrical' => $c->electricalSummary(),
                'standard' => $c->standard,
                'type' => $c->type->value,
                'powerKw' => (float) $c->power_kw,
                'connectors' => collect($c->connectors)->map(fn ($v) => ConnectorType::tryFrom($v)?->getLabel() ?? $v)->values(),
                'status' => $c->status->value,
                'statusLabel' => $c->status->getLabel(),
            ])->values(),
            'tariffs' => $tariffs->map(fn (Tariff $t) => [
                'name' => $t->name,
                'appliesTo' => $t->charger?->code ?? ($t->charger_type?->value ?? 'All'),
                'ratePerKwh' => (float) $t->rate_per_kwh,
                'ratePerKwhInclGst' => (float) $t->ratePerKwhInclGst(),
                'bands' => $t->bandsSummary(),
                'parkingFee' => (float) $t->parking_fee,
                'parkingFeePerHour' => (float) $t->parking_fee_per_hour,
                'sessionFee' => (float) $t->session_fee,
                'idleFeePerMin' => (float) $t->idle_fee_per_min,
                'idleGraceMinutes' => $t->idle_grace_minutes,
                'gstRate' => (float) $t->gst_rate,
                'sacCode' => $t->sac_code,
            ])->values(),
        ];
    }

    /** Live connector counts for the marketplace (estimates come later from OCPP data). */
    private static function connectorCounts(Station $station): array
    {
        $units = $station->chargers->flatMap(fn ($c) => $c->relationLoaded('connectorPoints') ? $c->connectorPoints->each(fn ($k) => $k->setRelation('charger', $c)) : collect());

        return [
            'total' => $units->count(),
            'available' => $units->filter(fn ($k) => $k->isBookable() && $k->live_status === \App\Enums\ConnectorLiveStatus::Available)->count(),
            'busy' => $units->filter(fn ($k) => $k->live_status->isBusy())->count(),
            'reserved' => $units->filter(fn ($k) => $k->live_status === \App\Enums\ConnectorLiveStatus::Reserved)->count(),
            'down' => $units->filter(fn ($k) => ! $k->isBookable())->count(),
        ];
    }

    private static function todayHours(Station $station): ?string
    {
        if ($station->is_24x7) {
            return 'Open 24 hours';
        }
        $window = $station->hoursFor(now());

        return $window ? $window[0]->format('g:i A').' – '.$window[1]->format('g:i A') : 'Closed today';
    }
}
