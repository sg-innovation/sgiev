<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ConnectorLiveStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\CheckInRecord;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Support\BusinessRules;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * C1 check-in.
 *  - Booking pass: a signed token (booking uuid + expiry, HMAC with the app key) valid for a few minutes. The customer's
 *    booking page shows it as a QR that refreshes itself, so a screenshot or forwarded image soon stops working.
 *    Staff scan it with any phone camera; the link opens the confirm page (only for staff of that station).
 *  - Charger sticker: each charger has a random key printed as a QR. A customer who scans it is checked in to
 *    their booking on that charger.
 * Check-in is allowed from a few minutes before the slot until the slot ends, only for confirmed (paid) bookings.
 */
class CheckInService
{
    public function __construct(private readonly ConnectorStatusService $connectors) {}

    // ── Tokens ──────────────────────────────────────────────────────────────────────────────────────────────

    public function token(Booking $booking, ?Carbon $now = null): array
    {
        $expires = ($now ?? now())->copy()->addMinutes(BusinessRules::int('checkin.qr_minutes'));
        $payload = self::b64($booking->uuid).'.'.base_convert((string) $expires->getTimestamp(), 10, 36);

        return ['token' => $payload.'.'.$this->sign($payload), 'expires_at' => $expires];
    }

    /** Returns the booking for a valid, unexpired token; throws with a customer-friendly message otherwise. */
    public function verify(string $token): Booking
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! hash_equals($this->sign($parts[0].'.'.$parts[1]), $parts[2])) {
            throw ValidationException::withMessages(['token' => 'This check-in code is not valid.']);
        }
        $expires = (int) base_convert($parts[1], 36, 10);
        if ($expires < now()->getTimestamp()) {
            throw ValidationException::withMessages(['token' => 'This check-in code has expired. Ask the customer to refresh the QR on their booking.']);
        }
        $booking = Booking::query()->where('uuid', self::unb64($parts[0]))->first();
        if (! $booking) {
            throw ValidationException::withMessages(['token' => 'Booking not found.']);
        }

        return $booking;
    }

    /** Accepts a token, a full scanned URL, or a booking code typed at the desk. */
    public function resolve(string $input): Booking
    {
        $input = trim($input);
        if (preg_match('~/checkin/([A-Za-z0-9_\-]+\.[a-z0-9]+\.[A-Za-z0-9_\-]+)~', $input, $m)) {
            return $this->verify($m[1]);
        }
        if (substr_count($input, '.') === 2) {
            return $this->verify($input);
        }

        return Booking::query()->where('code', strtoupper($input))->first()
            ?? throw ValidationException::withMessages(['token' => 'No booking matches that code.']);
    }

    public function passUrl(Booking $booking): array
    {
        $t = $this->token($booking);

        return ['url' => route('checkin.show', $t['token']), 'expires_at' => $t['expires_at']];
    }

    public function chargerUrl(Charger $charger): string
    {
        return route('charge.show', $charger->qr_key);
    }

    public static function qrSvg(string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true, 'quietzoneSize' => 2, 'svgUseFillAttributes' => true, 'drawLightModules' => false,
        ]);

        return (new QRCode($options))->render($data);
    }

    // ── Rules ───────────────────────────────────────────────────────────────────────────────────────────────

    /** Why this booking can't be checked in right now, or null when it can. */
    public function blocker(Booking $booking, ?Carbon $now = null): ?string
    {
        $now ??= now();
        $tz = Station::TIMEZONE;

        return match (true) {
            $booking->status === BookingStatus::CheckedIn => null,
            $booking->status === BookingStatus::Held => 'This booking has not been paid yet.',
            $booking->status !== BookingStatus::Confirmed => 'This booking is '.strtolower($booking->status->getLabel()).'.',
            $now->lt($opens = $booking->start_at->copy()->subMinutes(BusinessRules::int('checkin.early_minutes')))
                => 'Too early: check-in opens at '.$opens->timezone($tz)->format('g:i A, j M').'.',
            $now->gte($booking->end_at) => 'This slot ended at '.$booking->end_at->timezone($tz)->format('g:i A').'.',
            default => null,
        };
    }

    /** Bookings of this customer that can be checked in on this charger now (or soon). */
    public function bookingsFor(User $user, Charger $charger): \Illuminate\Support\Collection
    {
        return Booking::query()->where('user_id', $user->id)->where('station_id', $charger->station_id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
            ->where('end_at', '>', now())->where('start_at', '<', now()->addHours(12))
            ->with(['charger', 'connectorPoint'])->orderBy('start_at')->get();
    }

    // ── Check-in ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Check the booking in. Idempotent: a booking already checked in returns its existing record.
     *
     * @param  string  $method  staff_scan | charger_scan | manual
     */
    public function checkIn(Booking $booking, string $method, ?User $by = null, ?Charger $scanned = null, ?string $note = null): CheckInRecord
    {
        if ($scanned && $scanned->id !== $booking->charger_id) {
            throw ValidationException::withMessages(['booking' => 'This booking is for charger '.$booking->charger?->code.'. Please go to that charger.']);
        }

        $record = DB::transaction(function () use ($booking, $method, $by, $note) {
            /** @var Booking $b */
            $b = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();
            if ($b->status === BookingStatus::CheckedIn && ($existing = $b->checkIn()->first())) {
                return $existing;
            }
            if ($reason = $this->blocker($b)) {
                throw ValidationException::withMessages(['booking' => $reason]);
            }
            $now = now();
            $b->forceFill(['status' => BookingStatus::CheckedIn, 'checked_in_at' => $now])->save();
            $record = CheckInRecord::create([
                'booking_id' => $b->id, 'company_id' => $b->company_id, 'station_id' => $b->station_id, 'charger_id' => $b->charger_id,
                'connector_id' => $b->connector_id, 'method' => $method, 'checked_in_by' => $by?->id, 'checked_in_at' => $now,
                'late_minutes' => (int) round(($now->getTimestamp() - $b->start_at->getTimestamp()) / 60),
                'ip' => request()?->ip(), 'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255) ?: null, 'note' => $note,
            ]);
            activity()->performedOn($b)->causedBy($by)->withProperties(['method' => $method])->log('checked in');

            return $record;
        });

        if ($record->wasRecentlyCreated && $booking->connector_id) {
            try {
                $connector = Connector::query()->find($booking->connector_id);
                if ($connector && in_array($connector->live_status, [ConnectorLiveStatus::Available, ConnectorLiveStatus::Reserved], true)) {
                    $this->connectors->setLive($connector, ConnectorLiveStatus::Preparing, 'booking', 'Checked in: '.$booking->code, $by?->id);
                }
            } catch (\Throwable $e) {
                Log::warning('Connector status after check-in failed', ['booking' => $booking->code, 'error' => $e->getMessage()]);
            }
        }

        return $record;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────────────────

    private function sign(string $payload): string
    {
        return self::b64(substr(hash_hmac('sha256', 'checkin|'.$payload, (string) config('app.key'), true), 0, 16));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
