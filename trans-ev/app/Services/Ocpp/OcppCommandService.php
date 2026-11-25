<?php

namespace App\Services\Ocpp;

use App\Models\Booking;
use App\Models\Charger;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppCommand;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Remote commands (F3). Staff, the Staff Console and the customer's "Stop charging" queue a command; the OCPP server
 * sends it to the connected charger as the right message for its protocol and stores the charger's answer.
 */
class OcppCommandService
{
    /** Answers that count as accepted (Accepted, Unlocked, Scheduled…). */
    private const OK = ['Accepted', 'Unlocked', 'Scheduled', 'AcceptedCanceled', 'Ok'];

    public function queue(Charger $charger, string $command, array $params = [], ?User $by = null, ?Connector $connector = null, ?Booking $booking = null, ?ChargingSession $session = null): OcppCommand
    {
        if (! array_key_exists($command, OcppCommand::COMMANDS)) {
            throw ValidationException::withMessages(['command' => 'Unknown command.']);
        }
        if (! $charger->ocpp_identity) {
            throw ValidationException::withMessages(['command' => 'This charger is not set up for OCPP.']);
        }
        $cmd = OcppCommand::create([
            'charger_id' => $charger->id, 'connector_id' => $connector?->id, 'command' => $command, 'params' => $params, 'status' => 'queued',
            'requested_by' => $by?->id, 'booking_id' => $booking?->id, 'session_id' => $session?->id,
        ]);
        activity()->performedOn($charger)->causedBy($by)->withProperties(['command' => $command] + $params)->log('OCPP command queued');

        return $cmd;
    }

    /** Start charging for a booking: the charger is told to start with the booking code as the id tag. */
    public function remoteStart(Booking $booking, ?User $by = null): OcppCommand
    {
        $connector = Connector::query()->findOrFail($booking->connector_id);

        return $this->queue($booking->charger, 'remote_start', ['idTag' => $booking->code, 'connectorNo' => $connector->connector_no], $by, $connector, $booking);
    }

    public function remoteStop(ChargingSession $session, ?User $by = null): ?OcppCommand
    {
        if (! $session->ocpp_transaction_id || $session->status !== 'active') {
            return null;
        }
        $pending = OcppCommand::query()->where('session_id', $session->id)->where('command', 'remote_stop')->whereIn('status', ['queued', 'sent'])->first();

        return $pending ?? $this->queue($session->charger, 'remote_stop', ['transactionId' => $session->ocpp_transaction_id], $by, null, null, $session);
    }

    /** OCPP action and payload for this command in the charger's protocol. */
    public function build(OcppCommand $cmd, string $protocol): array
    {
        $p = $cmd->params ?? [];
        $no = (int) ($p['connectorNo'] ?? $cmd->connector?->connector_no ?? 0);
        $v201 = $protocol === OcppCentralSystem::V201;

        return match ($cmd->command) {
            'remote_start' => $v201
                ? ['RequestStartTransaction', ['evseId' => max(1, $no), 'remoteStartId' => $cmd->id, 'idToken' => ['idToken' => (string) $p['idTag'], 'type' => 'Central']]]
                : ['RemoteStartTransaction', ['connectorId' => max(1, $no), 'idTag' => (string) $p['idTag']]],
            'remote_stop' => $v201
                ? ['RequestStopTransaction', ['transactionId' => (string) $p['transactionId']]]
                : ['RemoteStopTransaction', ['transactionId' => (int) $p['transactionId']]],
            'reset' => $v201
                ? ['Reset', ['type' => ($p['type'] ?? 'soft') === 'hard' ? 'Immediate' : 'OnIdle']]
                : ['Reset', ['type' => ($p['type'] ?? 'soft') === 'hard' ? 'Hard' : 'Soft']],
            'unlock' => $v201 ? ['UnlockConnector', ['evseId' => max(1, $no), 'connectorId' => 1]] : ['UnlockConnector', ['connectorId' => max(1, $no)]],
            'availability' => $v201
                ? ['ChangeAvailability', array_filter(['operationalStatus' => ($p['available'] ?? true) ? 'Operative' : 'Inoperative', 'evse' => $no ? ['id' => $no] : null])]
                : ['ChangeAvailability', ['connectorId' => $no, 'type' => ($p['available'] ?? true) ? 'Operative' : 'Inoperative']],
            'trigger' => $v201
                ? ['TriggerMessage', array_filter(['requestedMessage' => $p['message'] ?? 'StatusNotification', 'evse' => $no ? ['id' => $no] : null])]
                : ['TriggerMessage', array_filter(['requestedMessage' => $p['message'] ?? 'StatusNotification', 'connectorId' => $no ?: null])],
            'update_firmware' => $v201
                ? ['UpdateFirmware', ['requestId' => $cmd->id, 'firmware' => ['location' => (string) $p['location'], 'retrieveDateTime' => OcppCentralSystem::now()]]]
                : ['UpdateFirmware', ['location' => (string) $p['location'], 'retrieveDate' => OcppCentralSystem::now()]],
            'get_diagnostics' => $v201
                ? ['GetLog', ['logType' => 'DiagnosticsLog', 'requestId' => $cmd->id, 'log' => ['remoteLocation' => (string) $p['location']]]]
                : ['GetDiagnostics', ['location' => (string) $p['location']]],
        };
    }

    public function markSent(OcppCommand $cmd, string $action, string $uniqueId): void
    {
        $cmd->forceFill(['status' => 'sent', 'action' => $action, 'unique_id' => $uniqueId, 'sent_at' => now()])->save();
    }

    /** The charger answered a command (CALLRESULT or CALLERROR). */
    public function resolve(string $uniqueId, ?array $result, ?string $error = null): ?OcppCommand
    {
        $cmd = OcppCommand::query()->where('unique_id', $uniqueId)->where('status', 'sent')->first();
        if (! $cmd) {
            return null;
        }
        $status = $error !== null ? 'failed' : (in_array((string) ($result['status'] ?? 'Accepted'), self::OK, true) ? 'accepted' : 'rejected');
        $cmd->forceFill(['status' => $status, 'response' => $result, 'error' => $error ? mb_substr($error, 0, 255) : null, 'responded_at' => now()])->save();

        return $cmd;
    }

    /** Commands not answered within a minute time out; queued ones for a charger that never connects fail after the limit. */
    public function expire(int $queuedMinutes = 10): int
    {
        $n = OcppCommand::query()->where('status', 'sent')->where('sent_at', '<', now()->subMinute())->update(['status' => 'timeout', 'responded_at' => now()]);

        return $n + OcppCommand::query()->where('status', 'queued')->where('created_at', '<', now()->subMinutes($queuedMinutes))
            ->update(['status' => 'failed', 'error' => 'The charger was not connected', 'responded_at' => now()]);
    }
}
