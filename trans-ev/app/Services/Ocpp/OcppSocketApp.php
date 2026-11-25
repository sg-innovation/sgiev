<?php

namespace App\Services\Ocpp;

use App\Models\Charger;
use App\Models\OcppCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\WebSocket\WsServerInterface;
use Throwable;

/**
 * The WebSocket side of the OCPP central system (run by `php artisan ocpp:serve`). Charge points connect to
 * wss://<host>/ocpp/<identity> with sub-protocol ocpp1.6 or ocpp2.0.1 and Basic auth; every CALL goes to
 * OcppCentralSystem, every answer to our commands to OcppCommandService. Frames are logged in ocpp_messages.
 */
class OcppSocketApp implements MessageComponentInterface, WsServerInterface
{
    /** @var array<string, ConnectionInterface> identity => connection */
    private array $clients = [];

    /** @var array<int, array{charger: int, identity: string, protocol: string}> resourceId => info */
    private array $info = [];

    /** @var callable(string): void */
    private $out;

    public function __construct(private readonly OcppCentralSystem $central, private readonly OcppCommandService $commands, ?callable $out = null)
    {
        $this->out = $out ?? fn (string $line) => null;
    }

    public function getSubProtocols(): array
    {
        return array_keys(OcppCentralSystem::PROTOCOLS);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $req = $conn->httpRequest;
        $identity = rawurldecode((string) last(array_filter(explode('/', $req->getUri()->getPath()))));
        $charger = $this->db(fn () => $this->central->authenticate($identity, $req->getHeaderLine('Authorization') ?: null));
        $requested = array_map('trim', explode(',', $req->getHeaderLine('Sec-WebSocket-Protocol')));
        $protocol = collect($requested)->first(fn ($p) => array_key_exists($p, OcppCentralSystem::PROTOCOLS));
        if (! $charger || ! $protocol) {
            ($this->out)("Refused {$identity}: ".(! $charger ? 'unknown identity or wrong password' : 'no supported OCPP sub-protocol'));
            $this->central->log(null, $identity ?: '?', 'in', 2, '-', 'Connect', ['refused' => ! $charger ? 'auth' : 'protocol']);
            $conn->close();

            return;
        }
        if (isset($this->clients[$identity])) {
            $this->clients[$identity]->close(); // a charger reconnecting replaces its old socket
        }
        $this->clients[$identity] = $conn;
        $this->info[$conn->resourceId] = ['charger' => $charger->id, 'identity' => $identity, 'protocol' => $protocol];
        $this->db(fn () => $this->central->connected($charger, $protocol));
        ($this->out)("Connected {$identity} ({$protocol})");
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $info = $this->info[$from->resourceId] ?? null;
        if (! $info) {
            return;
        }
        $frame = json_decode((string) $msg, true);
        $charger = $this->db(fn () => Charger::query()->find($info['charger']));
        if (! is_array($frame) || count($frame) < 3 || ! in_array($frame[0] ?? null, [2, 3, 4], true) || ! $charger) {
            $this->send($from, null, [4, is_array($frame) ? (string) ($frame[1] ?? '-') : '-', 'FormationViolation', 'Not an OCPP-J frame', new \stdClass]);

            return;
        }
        [$type, $id] = [(int) $frame[0], (string) $frame[1]];

        if ($type === 2) {
            $action = (string) ($frame[2] ?? '');
            $payload = (array) ($frame[3] ?? []);
            $this->central->log($charger, $info['identity'], 'in', 2, $id, $action, $payload);
            try {
                $result = $this->db(fn () => $this->central->handleCall($charger, $info['protocol'], $action, $payload));
                $this->send($from, $charger, [3, $id, $result === [] ? new \stdClass : $result], $action);
            } catch (OcppError $e) {
                $this->send($from, $charger, [4, $id, $e->ocppCode, $e->getMessage(), $e->details ?: new \stdClass], $action);
            } catch (Throwable $e) {
                Log::error('OCPP call failed', ['identity' => $info['identity'], 'action' => $action, 'error' => $e->getMessage()]);
                $this->send($from, $charger, [4, $id, 'InternalError', 'The central system could not process this message', new \stdClass], $action);
            }

            return;
        }
        // Answer to one of our commands.
        $this->central->log($charger, $info['identity'], 'in', $type, $id, null, $type === 3 ? (array) ($frame[2] ?? []) : ['code' => $frame[2] ?? null, 'description' => $frame[3] ?? null]);
        $this->db(fn () => $this->commands->resolve($id, $type === 3 ? (array) ($frame[2] ?? []) : null, $type === 4 ? trim(($frame[2] ?? '').' '.($frame[3] ?? '')) : null));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $info = $this->info[$conn->resourceId] ?? null;
        unset($this->info[$conn->resourceId]);
        if ($info && ($this->clients[$info['identity']] ?? null) === $conn) {
            unset($this->clients[$info['identity']]);
            $charger = $this->db(fn () => Charger::query()->find($info['charger']));
            if ($charger) {
                $this->db(fn () => $this->central->disconnected($charger));
            }
            ($this->out)("Disconnected {$info['identity']}");
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        Log::warning('OCPP socket error', ['error' => $e->getMessage()]);
        $conn->close();
    }

    /** Every second: send queued commands to connected chargers. */
    public function flushCommands(): void
    {
        $connected = collect($this->info)->pluck('charger')->unique()->values()->all();
        if (! $connected) {
            return;
        }
        $this->db(function () use ($connected) {
            OcppCommand::query()->with(['charger', 'connector'])->where('status', 'queued')->whereIn('charger_id', $connected)->orderBy('id')->limit(50)->get()
                ->each(function (OcppCommand $cmd) {
                    $info = collect($this->info)->firstWhere('charger', $cmd->charger_id);
                    $conn = $info ? ($this->clients[$info['identity']] ?? null) : null;
                    if (! $conn) {
                        return;
                    }
                    [$action, $payload] = $this->commands->build($cmd, $info['protocol']);
                    $uid = OcppCentralSystem::newUniqueId();
                    $this->commands->markSent($cmd, $action, $uid);
                    $this->send($conn, $cmd->charger, [2, $uid, $action, $payload === [] ? new \stdClass : $payload], $action);
                    ($this->out)("→ {$info['identity']} {$action}");
                });
        });
    }

    /** Every 30 s: time out unanswered commands; mark chargers that went silent as disconnected. */
    public function housekeeping(): void
    {
        $this->db(fn () => $this->commands->expire());
        $this->db(fn () => $this->central->markSilentOffline());
    }

    public function connectedCount(): int
    {
        return count($this->clients);
    }

    private function send(ConnectionInterface $conn, ?Charger $charger, array $frame, ?string $action = null): void
    {
        $conn->send(json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $identity = $this->info[$conn->resourceId]['identity'] ?? '?';
        $this->central->log($charger, $identity, 'out', $frame[0], (string) $frame[1], $frame[0] === 2 ? $frame[2] : $action, $frame[0] === 2 ? (array) $frame[3] : ($frame[0] === 3 ? (array) $frame[2] : ['code' => $frame[2], 'description' => $frame[3]]));
    }

    /** Long-running process: reconnect once if the database connection dropped. */
    private function db(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Illuminate\Database\QueryException|\PDOException $e) {
            if (! str_contains($e->getMessage(), 'gone away') && ! str_contains($e->getMessage(), 'Lost connection') && ! str_contains($e->getMessage(), 'Error while sending')) {
                throw $e;
            }
            DB::reconnect();

            return $fn();
        }
    }
}
