<?php

namespace App\Http\Resources;

use App\Enums\ConnectorType;
use App\Models\Booking;
use App\Models\Station;
use App\Support\IndianStates;

final class BookingPresenter
{
    public static function summary(Booking $b): array
    {
        $tz = Station::TIMEZONE;

        return [
            'code' => $b->code,
            'status' => $b->status->value,
            'statusLabel' => $b->status->getLabel(),
            'holdActive' => $b->isHoldActive(),
            'holdExpiresAt' => $b->hold_expires_at?->toIso8601String(),
            'station' => ['name' => $b->station->name, 'slug' => $b->station->slug, 'city' => $b->station->city],
            'charger' => ['code' => $b->charger->code, 'type' => $b->charger->type->value, 'powerKw' => (float) $b->charger->power_kw],
            'date' => $b->start_at->setTimezone($tz)->format('D, j M Y'),
            'time' => $b->start_at->setTimezone($tz)->format('g:i A').' – '.$b->end_at->setTimezone($tz)->format('g:i A'),
            'startAt' => $b->start_at->toIso8601String(),
            'total' => (float) $b->total_amount,
            'vehicle' => $b->vehicle_snapshot['registration_no'] ?? null,
        ];
    }

    /** Policy for display: tiers in customer language. */
    public static function policy(array $p): array
    {
        return [
            'name' => $p['name'] ?? null,
            'tiers' => collect($p['tiers'] ?? [])->map(fn ($t) => [
                'label' => $t['label'] ?: ((int) $t['refund_percent']).'% refund',
                'percent' => (int) $t['refund_percent'], 'fee' => (float) ($t['fee'] ?? 0), 'minutes' => (int) $t['min_minutes_before'],
            ])->values()->all(),
            'noShowPercent' => (int) ($p['no_show_refund_percent'] ?? 0),
            'note' => $p['customer_note'] ?? null,
        ];
    }

    private static function money(array $q): array
    {
        return ['paid' => (float) $q['paid'], 'refund' => (float) $q['refund'], 'retained' => (float) $q['retained'], 'percent' => $q['percent'], 'rule' => $q['rule']];
    }

    public static function detail(Booking $b): array
    {
        return self::summary($b) + [
            'stationAddress' => trim("{$b->station->address_line1}, {$b->station->city}, {$b->station->state} {$b->station->pincode}"),
            'lat' => (float) $b->station->latitude,
            'lng' => (float) $b->station->longitude,
            'connector' => ConnectorType::tryFrom($b->connector)?->getLabel() ?? $b->connector,
            'durationMinutes' => $b->start_at->diffInMinutes($b->end_at),
            'batteryFrom' => $b->battery_from,
            'batteryTo' => $b->battery_to,
            'vehicleSnapshot' => $b->vehicle_snapshot,
            'invoice' => ($inv = $b->invoice) ? [
                'number' => $inv->number,
                'date' => $inv->issued_at->setTimezone(\App\Models\Station::TIMEZONE)->format('j M Y'),
                'total' => (float) $inv->total,
                'url' => route('bookings.invoice', $b),
            ] : null,
            'creditNotes' => $b->creditNotes->map(fn (\App\Models\CreditNote $c) => [
                'title' => $c->title(),
                'number' => $c->number,
                'date' => $c->issued_at->setTimezone(\App\Models\Station::TIMEZONE)->format('j M Y'),
                'total' => (float) $c->total,
                'url' => route('credit-notes.show', $c),
            ])->values(),
            'customer' => $b->customer_snapshot,
            'amounts' => [
                'kwh' => (float) $b->est_kwh,
                'ratePerKwh' => (float) $b->rate_per_kwh,
                'energy' => (float) $b->energy_amount,
                'sessionFee' => (float) $b->session_fee,
                'discount' => (float) $b->discount_amount,
                'parking' => (float) $b->parking_amount,
                'lines' => collect($b->price_lines ?? [])->map(fn ($l) => ['label' => $l['label'], 'qty' => (float) $l['qty'], 'unit' => $l['unit'], 'rate' => (float) $l['rate'], 'amount' => (float) $l['amount'], 'type' => $l['type']])->values(),
                'couponCode' => $b->coupon_code,
                'taxable' => (float) $b->taxable_amount,
                'gstRate' => (float) $b->gst_rate,
                'cgst' => (float) $b->cgst_amount,
                'sgst' => (float) $b->sgst_amount,
                'igst' => (float) $b->igst_amount,
                'total' => (float) $b->total_amount,
                'sac' => $b->sac_code,
                'placeOfSupply' => $b->place_of_supply.' – '.IndianStates::name($b->place_of_supply),
            ],
            'rules' => $b->ruleAcceptance ? [
                'version' => $b->ruleAcceptance->version,
                'acceptedAt' => $b->ruleAcceptance->accepted_at->setTimezone(Station::TIMEZONE)->format('j M Y, g:i A'),
            ] : null,
            'finalBill' => $b->trueup_status !== null ? [
                'kwh' => (float) $b->final_kwh, 'total' => (float) $b->final_total, 'prepaid' => (float) $b->total_amount,
                'difference' => (float) $b->trueup_amount, 'status' => $b->trueup_status, 'balanceDue' => $b->balance_due !== null ? (float) $b->balance_due : null,
                'outcome' => \App\Services\StationLabels::trueUp($b),
            ] : null,
            'late' => $b->status->value === 'confirmed' ? (function () use ($b) {
                $svc = app(\App\Services\NoShowService::class);
                $holdUntil = $svc->noShowAt($b);

                return [
                    'canNotify' => now()->gte($b->start_at->copy()->subMinutes(60)) && now()->lt($holdUntil),
                    'holdUntil' => $holdUntil->timezone(Station::TIMEZONE)->format('g:i A'),
                    'eta' => $b->late_eta_at?->timezone(Station::TIMEZONE)->format('g:i A'),
                    'maxExtra' => \App\Support\BusinessRules::int('booking.late_extra_minutes'),
                ];
            })() : null,
            'noShow' => ($r = \App\Models\NoShowRecord::query()->where('booking_id', $b->id)->first()) ? [
                'at' => $r->marked_at->timezone(Station::TIMEZONE)->format('j M, g:i A'), 'grace' => $r->grace_minutes, 'percent' => $r->refund_percent,
                'refund' => (float) $r->refund_amount, 'retained' => (float) $r->retained_amount, 'auto' => $r->method === 'auto',
            ] : null,
            'reschedule' => $b->status->value === 'confirmed' && auth()->id() === $b->user_id ? (function () use ($b) {
                $svc = app(\App\Services\RescheduleService::class);
                $max = \App\Support\BusinessRules::int('reschedule.max_per_booking');

                return [
                    'blocker' => $svc->customerBlocker($b, auth()->user()), 'remaining' => max(0, $max - $svc->customerChanges($b)),
                    'noticeMinutes' => \App\Support\BusinessRules::int('reschedule.min_notice_minutes'),
                    'dates' => collect(app(\App\Services\AvailabilityService::class)->bookableDates())->map(fn ($d) => ['value' => $d, 'label' => \Illuminate\Support\Carbon::parse($d, Station::TIMEZONE)->format('D j M')])->values(),
                ];
            })() : null,
            'history' => \App\Models\BookingReschedule::query()->where('booking_id', $b->id)->with(['fromCharger', 'toCharger'])->orderBy('id')->get()->map(fn ($r) => [
                'at' => $r->created_at->timezone(Station::TIMEZONE)->format('j M, g:i A'), 'by' => $r->by_role === 'operator' ? 'Station' : 'You',
                'from' => $r->from_start->timezone(Station::TIMEZONE)->format('D j M, g:i A').' · '.$r->fromCharger?->code,
                'to' => $r->to_start->timezone(Station::TIMEZONE)->format('D j M, g:i A').' · '.$r->toCharger?->code, 'reason' => $r->reason,
            ])->values(),
            'review' => (function () use ($b) {
                $svc = app(\App\Services\ReviewService::class);
                $r = $b->review()->first();
                $why = $svc->blocker($b, auth()->user());
                if (! $r && $why) {
                    return null;
                }

                return [
                    'canEdit' => $why === null, 'tagOptions' => collect(\App\Services\ReviewService::tags())->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                    'existing' => $r ? ['stationRating' => $r->station_rating, 'chargerRating' => $r->charger_rating, 'tags' => $r->tags ?? [], 'body' => $r->body,
                        'status' => $r->status, 'statusLabel' => \App\Models\Review::STATUSES[$r->status] ?? $r->status, 'reply' => $r->owner_reply] : null,
                ];
            })(),
            'canCancel' => $b->canBeCancelledByCustomer(),
            'canPay' => $b->isHoldActive(),
            'cancellationPolicy' => self::policy(\App\Services\Payments\RefundService::policyOf($b)),
            'cancelQuote' => $b->canBeCancelledByCustomer() ? self::money(app(\App\Services\Payments\RefundService::class)->quote($b)) : null,
            'payment' => ($p = $b->payments()->where('purpose', 'booking')->whereIn('status', ['success', 'pending'])->first()) ? [
                'status' => $p->status,
                'amount' => (float) ($p->paid_amount ?? $p->amount),
                'mode' => $p->methodLabel(),
                'gatewayTxnId' => $p->gateway_txn_id,
                'paidAt' => $p->paid_at?->setTimezone(Station::TIMEZONE)->format('j M Y, g:i A'),
                'simulated' => $p->environment === 'simulator',
            ] : null,
            'lastFailure' => $b->payments()->where('status', 'failed')->value('failure_reason'),
            'refunds' => $b->refunds()->get()->map(fn ($r) => [
                'amount' => (float) $r->amount,
                'retained' => (float) $r->fee_retained,
                'status' => $r->status,
                'rule' => $r->policy_rule,
                'reason' => $r->reason,
                'date' => $r->created_at->setTimezone(Station::TIMEZONE)->format('j M Y, g:i A'),
            ]),
            'cancelledAt' => $b->cancelled_at?->setTimezone(Station::TIMEZONE)->format('j M Y, g:i A'),
            'cancelReason' => $b->cancel_reason,
            'createdAt' => $b->created_at->setTimezone(Station::TIMEZONE)->format('j M Y, g:i A'),
        ];
    }
}
