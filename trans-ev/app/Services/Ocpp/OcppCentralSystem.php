<?php

namespace App\Services\Ocpp;

use App\Enums\BookingStatus;
use App\Enums\ConnectorLiveStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppCommand;
use App\Models\OcppFault;
use App\Models\OcppMessage;
use App\Services\ChargingSessionService;
use App\Services\CheckInService;
use App\Services\ConnectorStatusService;
use App\Services\WorkOrderService;
use App\Support\BusinessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase F: the OCPP central system. Transport-free: the WebSocket server (`ocpp:serve`) and the tests both call
 * handleCall() with a charger's CALL and send back what it returns (or a CALLERROR for an OcppError).
 *
 * Charge point → us (1.6J and 2.0.1): BootNotification, Heartbeat, StatusNotification, Authorize,
 * StartTransaction / StopTransaction (1.6) and TransactionEvent (2.0.1), MeterValues, DataTransfer,
 * Firmware/Diagnostics/Log status notifications, NotifyEvent, SecurityEventNotification.
 *
 * Sessions, the final bill (true-up), walk-in billing, connector statuses and work orders all go through the same
 * services the Staff Console uses, so a session looks the same whether it was started by staff or by the charger.
 */
class OcppCentralSystem
{
    public const V16 = 'ocpp1.6';

    public const V201 = 'ocpp2.0.1';

    public const PROTOCOLS = [self::V16 => '1.6J', self::V201 => '2.0.1'];

    /** OCPP 1.6 ChargePointStatus and 2.0.1 ConnectorStatus → our live status. */
    private const STATUS_MAP = [
        'Available' => ConnectorLiveStatus::Available, 'Preparing' => ConnectorLiveStatus::Preparing, 'Charging' => ConnectorLiveStatus::Charging,
        'SuspendedEV' => ConnectorLiveStatus::SuspendedEV, 'SuspendedEVSE' => ConnectorLiveStatus::SuspendedEVSE, 'Finishing' => ConnectorLiveStatus::Finishing,
        'Reserved' => ConnectorLiveStatus::Reserved, 'Unavailable' => ConnectorLiveStatus::Unavailable, 'Faulted' => ConnectorLiveStatus::Faulted,
        'Occupied' => ConnectorLiveStatus::Occupied,
    ];

    /** OCPP stop reasons → our stop reasons. */
    private const STOP_REASONS = [
        'EVDisconnected' => 'completed', 'Local' => 'completed', 'StoppedByEV' => 'completed', 'EnergyLimitReached' => 'completed', 'SOCLimitReached' => 'completed',
        'Remote' => 'customer', 'EmergencyStop' => 'emergency', 'PowerLoss' => 'fault', 'GroundFault' => 'fault', 'OvercurrentFault' => 'fault',
        'TimeLimitReached' => 'slot_end', 'DeAuthorized' => 'other', 'HardReset' => 'other', 'SoftReset' => 'other', 'Reboot' => 'other', 'Other' => 'other',
    ];

    public function __construct(
        private readonly ConnectorStatusService $connectors,
        private readonly ChargingSessionService $sessions,
        private readonly CheckInService $checkIns,
        private readonly WorkOrderService $workOrders,
    ) {}

    // ── Connection ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Security profile 1/2: HTTP Basic auth with the charge point identity as user name and the password set in
     * Admin → Charger control (stored hashed). TLS (wss://) is terminated by Nginx in front of the server.
     */
    public function authenticate(string $identity, ?string $authorization): ?Charger
    {
        $charger = Charger::query()->with('station.company')->where('ocpp_identity', $identity)->first();
        if (! $charger || ! ($charger->station?->company?->hasFeature('ocpp') ?? false)) {
            return null;
        }
        if (! $charger->ocpp_password) {
            return BusinessRules::int('ocpp.allow_without_password') === 1 ? $charger : null;
        }
        if (! is_string($authorization) || ! str_starts_with($authorization, 'Basic ')) {
            return null;
        }
        [$user, $pass] = array_pad(explode(':', (string) base64_decode(substr($authorization, 6), true), 2), 2, '');

        return $user === $identity && Hash::check($pass, $charger->ocpp_password) ? $charger : null;
    }

    public function connected(Charger $charger, string $protocol): void
    {
        $charger->forceFill(['ocpp_connected' => true, 'ocpp_connected_at' => now(), 'last_heartbeat_at' => now(), 'ocpp_protocol' => self::PROTOCOLS[$protocol] ?? $charger->ocpp_protocol])->save();
    }

    /** The socket closed: connectors without a running session show Offline until the charger is back. */
    public function disconnected(Charger $charger): void
    {
        $charger->forceFill(['ocpp_connected' => false, 'ocpp_disconnected_at' => now()])->save();
        foreach ($charger->connectorPoints()->get() as $c) {
            if (! $this->sessions->active($c)) {
                $this->connectors->setLive($c, ConnectorLiveStatus::Unavailable, 'ocpp', 'Charger disconnected from the network');
            }
        }
    }

    /** Scheduled: chargers that stopped sending heartbeats are treated as disconnected. */
    public function markSilentOffline(): int
    {
        $interval = max(30, BusinessRules::int('ocpp.heartbeat_seconds'));

        return Charger::query()->where('ocpp_connected', true)->where(fn ($q) => $q->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subSeconds($interval * 3)))
            ->get()->each(fn (Charger $c) => $this->disconnected($c))->count();
    }

    public function log(?Charger $charger, string $identity, string $direction, int $type, string $uniqueId, ?string $action, mixed $payload): void
    {
        if (BusinessRules::int('ocpp.log_messages') !== 1 && $action === 'Heartbeat') {
            return;
        }
        try {
            OcppMessage::create(['charger_id' => $charger?->id, 'identity' => mb_substr($identity, 0, 64), 'direction' => $direction, 'message_type' => $type,
                'unique_id' => mb_substr($uniqueId, 0, 64), 'action' => $action ? mb_substr($action, 0, 40) : null, 'payload' => is_array($payload) ? $payload : ['value' => $payload]]);
        } catch (\Throwable $e) {
            Log::warning('OCPP log failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Incoming calls ──────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> the CALLRESULT payload */
    public function handleCall(Charger $charger, string $protocol, string $action, array $p): array
    {
        $charger->forceFill(['last_heartbeat_at' => now(), 'ocpp_connected' => true])->saveQuietly();

        return $protocol === self::V201 ? $this->handle201($charger, $action, $p) : $this->handle16($charger, $action, $p);
    }

    private function handle16(Charger $c, string $action, array $p): array
    {
        return match ($action) {
            'BootNotification' => $this->boot($c, $p['chargePointVendor'] ?? null, $p['chargePointModel'] ?? null, $p['chargePointSerialNumber'] ?? $p['chargeBoxSerialNumber'] ?? null, $p['firmwareVersion'] ?? null, $p['meterSerialNumber'] ?? null),
            'Heartbeat' => ['currentTime' => self::now()],
            'StatusNotification' => $this->status($c, (int) ($p['connectorId'] ?? 0), (string) ($p['status'] ?? ''), $p['errorCode'] ?? null, $p['info'] ?? null, $p['vendorErrorCode'] ?? null),
            'Authorize' => ['idTagInfo' => ['status' => $this->authorize($c, (string) ($p['idTag'] ?? '')) ? 'Accepted' : 'Invalid']],
            'StartTransaction' => $this->start16($c, $p),
            'MeterValues' => $this->meterValues($c, isset($p['transactionId']) ? (string) $p['transactionId'] : null, (int) ($p['connectorId'] ?? 0), (array) ($p['meterValue'] ?? [])),
            'StopTransaction' => $this->stop16($c, $p),
            'DataTransfer' => ['status' => 'UnknownVendorId'],
            'DiagnosticsStatusNotification', 'FirmwareStatusNotification' => $this->firmwareStatus($c, (string) ($p['status'] ?? '')),
            default => throw new OcppError('NotImplemented', "{$action} is not supported"),
        };
    }

    private function handle201(Charger $c, string $action, array $p): array
    {
        return match ($action) {
            'BootNotification' => $this->boot($c, $p['chargingStation']['vendorName'] ?? null, $p['chargingStation']['model'] ?? null, $p['chargingStation']['serialNumber'] ?? null, $p['chargingStation']['firmwareVersion'] ?? null, null),
            'Heartbeat' => ['currentTime' => self::now()],
            'StatusNotification' => $this->status($c, (int) ($p['evseId'] ?? 0), (string) ($p['connectorStatus'] ?? '')),
            'Authorize' => ['idTokenInfo' => ['status' => $this->authorize($c, (string) ($p['idToken']['idToken'] ?? '')) ? 'Accepted' : 'Invalid']],
            'TransactionEvent' => $this->transactionEvent($c, $p),
            'MeterValues' => $this->meterValues($c, null, (int) ($p['evseId'] ?? 0), (array) ($p['meterValue'] ?? [])),
            'DataTransfer' => ['status' => 'UnknownVendorId'],
            'FirmwareStatusNotification', 'LogStatusNotification' => $this->firmwareStatus($c, (string) ($p['status'] ?? '')),
            'NotifyEvent', 'SecurityEventNotification', 'NotifyReport', 'NotifyChargingLimit', 'ReportChargingProfiles' => [],
            default => throw new OcppError('NotImplemented', "{$action} is not supported"),
        };
    }

    private function boot(Charger $c, ?string $vendor, ?string $model, ?string $serial, ?string $firmware, ?string $meterSerial): array
    {
        $c->forceFill(array_filter([
            'ocpp_boot_at' => now(), 'ocpp_vendor' => $vendor ? mb_substr($vendor, 0, 60) : null, 'ocpp_model' => $model ? mb_substr($model, 0, 60) : null,
            'firmware_version' => $firmware ? mb_substr($firmware, 0, 60) : null,
            'serial_no' => $c->serial_no ?: ($serial ? mb_substr($serial, 0, 60) : null),
            'meter_serial' => $c->meter_serial ?: ($meterSerial ? mb_substr($meterSerial, 0, 60) : null),
        ], fn ($v) => $v !== null))->save();
        activity()->performedOn($c)->withProperties(compact('vendor', 'model', 'firmware'))->log('charger booted (OCPP)');

        return ['status' => 'Accepted', 'currentTime' => self::now(), 'interval' => max(30, BusinessRules::int('ocpp.heartbeat_seconds'))];
    }

    /** Connector status (and error codes). Connector/EVSE 0 is the whole charger: faults are recorded, connectors untouched. */
    private function status(Charger $c, int $connectorNo, string $ocppStatus, ?string $errorCode = null, ?string $info = null, ?string $vendorError = null): array
    {
        $connector = $connectorNo > 0 ? $c->connectorPoints()->where('connector_no', $connectorNo)->first() : null;
        $faulty = $ocppStatus === 'Faulted' || ($errorCode !== null && $errorCode !== 'NoError');
        if ($faulty) {
            $this->openFault($c, $connector, $errorCode && $errorCode !== 'NoError' ? $errorCode : 'Faulted', $info, $vendorError);
        } else {
            OcppFault::query()->where('charger_id', $c->id)->where('connector_id', $connector?->id)->whereNull('cleared_at')->update(['cleared_at' => now()]);
        }
        if ($connector && isset(self::STATUS_MAP[$ocppStatus])) {
            $note = $faulty ? trim(($errorCode ?? 'Faulted').' '.($info ?? '')) : 'Reported by the charger';
            $this->connectors->setLive($connector, self::STATUS_MAP[$ocppStatus], 'ocpp', mb_substr($note, 0, 255));
        }

        return [];
    }

    private function openFault(Charger $c, ?Connector $connector, string $code, ?string $info, ?string $vendorError): void
    {
        $open = OcppFault::query()->where('charger_id', $c->id)->where('connector_id', $connector?->id)->where('error_code', $code)->whereNull('cleared_at')->exists();
        if ($open) {
            return;
        }
        $fault = OcppFault::create(['charger_id' => $c->id, 'connector_id' => $connector?->id, 'error_code' => mb_substr($code, 0, 40), 'info' => $info ? mb_substr($info, 0, 255) : null,
            'vendor_error_code' => $vendorError ? mb_substr($vendorError, 0, 60) : null, 'occurred_at' => now()]);
        if (BusinessRules::int('ocpp.auto_work_order') === 1) {
            $wo = $this->workOrders->open($c, [
                'type' => 'fault', 'priority' => 'high', 'connector_id' => $connector?->id,
                'title' => 'Charger reported '.$code.($connector ? ' on connector '.$connector->connector_no : ''),
                'description' => trim(implode("\n", array_filter([$info ? 'Info: '.$info : null, $vendorError ? 'Vendor error: '.$vendorError : null, 'Opened automatically from OCPP.']))),
            ], null);
            $fault->forceFill(['work_order_id' => $wo->id])->save();
        }
    }

    /** An id tag is accepted when it is a booking code for this charger (or a tag we sent with a remote start). */
    public function authorize(Charger $c, string $tag): bool
    {
        return $this->bookingForTag($c, $tag) !== null
            || ChargingSession::query()->where('charger_id', $c->id)->where('status', 'active')->where('id_tag', $tag)->exists()
            || OcppCommand::query()->where('charger_id', $c->id)->where('command', 'remote_start')->whereIn('status', ['queued', 'sent', 'accepted'])
                ->where('created_at', '>=', now()->subMinutes(15))->where('params->idTag', $tag)->exists();
    }

    private function bookingForTag(Charger $c, string $tag): ?Booking
    {
        if ($tag === '') {
            return null;
        }
        $booking = Booking::query()->where('charger_id', $c->id)->where('code', strtoupper($tag))
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])->first();

        return $booking && $this->checkIns->blocker($booking) === null ? $booking : null;
    }

    private function start16(Charger $c, array $p): array
    {
        $session = $this->startTransaction($c, (int) ($p['connectorId'] ?? 0), (string) ($p['idTag'] ?? ''), self::whToKwh($p['meterStart'] ?? null), null);

        return ['transactionId' => $session ? (int) $session->ocpp_transaction_id : 0, 'idTagInfo' => ['status' => $session ? 'Accepted' : 'Invalid']];
    }

    private function stop16(Charger $c, array $p): array
    {
        $reading = MeterReading::from((array) ($p['transactionData'] ?? []));
        $this->stopTransaction($c, (string) ($p['transactionId'] ?? ''), self::whToKwh($p['meterStop'] ?? null), (string) ($p['reason'] ?? 'Local'), $reading->signed);

        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    /** 2.0.1 TransactionEvent: Started / Updated / Ended (the charger names the transaction). */
    private function transactionEvent(Charger $c, array $p): array
    {
        $txId = (string) ($p['transactionInfo']['transactionId'] ?? '');
        $tag = (string) ($p['idToken']['idToken'] ?? '');
        $evse = (int) ($p['evse']['id'] ?? 0);
        $reading = MeterReading::from((array) ($p['meterValue'] ?? []));
        $session = ChargingSession::query()->where('charger_id', $c->id)->where('ocpp_transaction_id', $txId)->first();

        switch ($p['eventType'] ?? '') {
            case 'Started':
            case 'Updated':
                if (! $session && $tag !== '') {
                    $session = $this->startTransaction($c, $evse, $tag, $reading->registerKwh, $txId);
                    if (! $session) {
                        return ['idTokenInfo' => ['status' => 'Invalid']];
                    }
                } elseif ($session && ! $reading->isEmpty()) {
                    $this->storeReading($session, $reading, 'sample');
                }
                break;
            case 'Ended':
                if ($session) {
                    $this->stopTransaction($c, $txId, $reading->registerKwh, (string) ($p['transactionInfo']['stoppedReason'] ?? 'Local'), $reading->signed);
                }
                break;
        }

        return $tag !== '' ? ['idTokenInfo' => ['status' => $session ? 'Accepted' : 'Invalid']] : [];
    }

    /**
     * Start (or adopt) a session: a session staff already started on that connector (Staff Console / walk-in /
     * remote start) is linked to the transaction; otherwise the booking named by the id tag is checked in and started.
     */
    public function startTransaction(Charger $c, int $connectorNo, string $tag, ?string $meterStartKwh, ?string $txId): ?ChargingSession
    {
        $connector = $c->connectorPoints()->where('connector_no', max(1, $connectorNo))->first();
        if (! $connector) {
            return null;
        }

        return DB::transaction(function () use ($c, $connector, $tag, $meterStartKwh, $txId) {
            $session = ChargingSession::query()->where('connector_id', $connector->id)->where('status', 'active')->lockForUpdate()->first();
            if ($session && $session->ocpp_transaction_id === null) {
                $session->forceFill(['ocpp_transaction_id' => $txId ?? (string) $session->id, 'id_tag' => $tag ?: $session->id_tag,
                    'meter_start_kwh' => $session->meter_start_kwh ?? $meterStartKwh])->save();
            } elseif ($session) {
                return $session->ocpp_transaction_id === $txId ? $session : null; // another transaction is running here
            } else {
                $booking = $this->bookingForTag($c, $tag) ?? $this->bookingFromRemoteStart($c, $tag);
                if (! $booking) {
                    return null;
                }
                if ($booking->status === BookingStatus::Confirmed) {
                    $this->checkIns->checkIn($booking, 'ocpp');
                }
                $session = ChargingSession::create([
                    'company_id' => $booking->company_id, 'station_id' => $booking->station_id, 'charger_id' => $c->id, 'connector_id' => $connector->id,
                    'booking_id' => $booking->id, 'source' => 'ocpp', 'status' => 'active', 'started_at' => now(), 'id_tag' => $tag,
                    'meter_start_kwh' => $meterStartKwh, 'energy_source' => 'meter',
                ]);
                $session->forceFill(['ocpp_transaction_id' => $txId ?? (string) $session->id])->save();
                activity()->performedOn($booking)->withProperties(['ocpp' => true])->log('charging started');
            }
            if ($session->meter_start_kwh !== null && ! $session->meterValues()->where('context', 'start')->exists()) {
                $session->meterValues()->create(['measured_at' => now(), 'register_kwh' => $session->meter_start_kwh, 'energy_kwh' => '0', 'context' => 'start', 'source' => 'ocpp']);
            }
            OcppCommand::query()->where('charger_id', $c->id)->where('command', 'remote_start')->whereIn('status', ['sent', 'accepted'])
                ->where('params->idTag', $tag)->update(['session_id' => $session->id]);
            $this->connectors->setLive($connector, ConnectorLiveStatus::Charging, 'ocpp', 'Transaction '.$session->ocpp_transaction_id);

            return $session->fresh();
        });
    }

    private function bookingFromRemoteStart(Charger $c, string $tag): ?Booking
    {
        $id = OcppCommand::query()->where('charger_id', $c->id)->where('command', 'remote_start')->whereIn('status', ['sent', 'accepted'])
            ->where('params->idTag', $tag)->where('created_at', '>=', now()->subMinutes(15))->latest('id')->value('booking_id');

        return $id ? Booking::query()->find($id) : null;
    }

    private function meterValues(Charger $c, ?string $txId, int $connectorNo, array $values): array
    {
        $reading = MeterReading::from($values);
        $session = $txId
            ? ChargingSession::query()->where('charger_id', $c->id)->where('ocpp_transaction_id', $txId)->where('status', 'active')->first()
            : ChargingSession::query()->where('charger_id', $c->id)->where('status', 'active')
                ->whereHas('connector', fn ($q) => $q->where('connector_no', max(1, $connectorNo)))->first();
        if ($session && ! $reading->isEmpty()) {
            $this->storeReading($session, $reading, $reading->context === 'Transaction.Begin' ? 'start' : 'sample');
        }

        return [];
    }

    private function storeReading(ChargingSession $s, MeterReading $r, string $context): void
    {
        if ($s->meter_start_kwh === null && $r->registerKwh !== null) {
            $s->forceFill(['meter_start_kwh' => $r->registerKwh])->save();
        }
        $energy = $r->registerKwh !== null && $s->meter_start_kwh !== null ? bcsub($r->registerKwh, (string) $s->meter_start_kwh, 3) : null;
        $s->meterValues()->create([
            'measured_at' => now(), 'register_kwh' => $r->registerKwh, 'energy_kwh' => $energy !== null && bccomp($energy, '0', 3) >= 0 ? $energy : null,
            'power_kw' => $r->powerKw, 'soc' => $r->soc, 'voltage_v' => $r->voltage, 'current_a' => $r->current, 'signed_data' => $r->signed,
            'context' => $context, 'source' => 'ocpp',
        ]);
    }

    /** Stop: the energy is the charger's meter (stop − start); then the same final bill as staff stops. */
    public function stopTransaction(Charger $c, string $txId, ?string $meterStopKwh, string $ocppReason, ?string $signed = null): ?ChargingSession
    {
        $session = ChargingSession::query()->where('charger_id', $c->id)->where('ocpp_transaction_id', $txId)->first();
        if (! $session || $session->status !== 'active') {
            return $session;
        }
        if ($signed) {
            $session->meterValues()->create(['measured_at' => now(), 'register_kwh' => $meterStopKwh, 'context' => 'signed', 'source' => 'ocpp', 'signed_data' => $signed]);
        }
        $reason = self::STOP_REASONS[$ocppReason] ?? 'other';
        $energy = $meterStopKwh === null ? ($session->meterValues()->whereNotNull('energy_kwh')->latest('id')->value('energy_kwh') ?? '0') : null;

        return $this->sessions->complete($session, null, $meterStopKwh, $energy !== null ? (string) $energy : null, $reason, 'OCPP: '.$ocppReason, 'ocpp');
    }

    private function firmwareStatus(Charger $c, string $status): array
    {
        $c->forceFill(['ocpp_firmware_status' => mb_substr($status, 0, 40)])->save();

        return [];
    }

    private static function whToKwh(mixed $wh): ?string
    {
        return is_numeric($wh) ? number_format((float) $wh / 1000, 3, '.', '') : null;
    }

    public static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
