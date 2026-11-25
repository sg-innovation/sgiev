<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;

use function Ratchet\Client\connect;

/**
 * F7: a charge point simulator (OCPP 1.6J or 2.0.1) for testing without hardware. It connects like a real charger,
 * boots, sends heartbeats and statuses, and can run a whole charging session for a booking code: authorize →
 * start → meter values → stop. With --wait it stays online and obeys remote start / stop / reset / unlock.
 *
 *   php artisan ocpp:simulate CP-PAT-01 --password=… --tag=TEV-261125-ABCDE --kwh=6 --seconds=30
 */
class OcppSimulate extends Command
{
    protected $signature = 'ocpp:simulate {identity : Charge point identity}
        {--url= : Central system URL (default: OCPP public URL setting)}
        {--password= : Basic-auth password}
        {--protocol=1.6 : 1.6 or 2.0.1}
        {--tag= : Booking code to charge for (starts a session at once)}
        {--kwh=5 : Energy to deliver}
        {--seconds=20 : How long the session lasts}
        {--connector=1 : Connector / EVSE number}
        {--wait : Stay connected and answer remote commands}';

    protected $description = 'Simulate an OCPP charge point (for testing without a real charger)';

    private WebSocket $ws;

    /** @var array<string, array{0: string, 1: callable|null}> */
    private array $pending = [];

    private bool $v201 = false;

    private float $register = 0;

    private ?string $txId = null;

    private bool $charging = false;

    public function handle(): int
    {
        $this->v201 = $this->option('protocol') === '2.0.1';
        $this->register = random_int(1_000_000, 9_000_000) / 1000; // kWh
        $identity = (string) $this->argument('identity');
        $url = rtrim((string) ($this->option('url') ?: config('services.ocpp.public_url')), '/').'/'.rawurlencode($identity);
        $headers = $this->option('password') ? ['Authorization' => 'Basic '.base64_encode($identity.':'.$this->option('password'))] : [];
        $loop = Loop::get();

        connect($url, [$this->v201 ? 'ocpp2.0.1' : 'ocpp1.6'], $headers, $loop)->then(function (WebSocket $ws) use ($loop) {
            $this->ws = $ws;
            $this->info('Connected as '.($this->v201 ? 'OCPP 2.0.1' : 'OCPP 1.6J'));
            $ws->on('message', fn ($msg) => $this->onMessage((string) $msg));
            $ws->on('close', function ($code = null, $reason = null) use ($loop) {
                $this->warn("Connection closed {$code} {$reason}");
                $loop->stop();
            });
            $boot = $this->v201
                ? ['reason' => 'PowerUp', 'chargingStation' => ['model' => 'SimCharger 60', 'vendorName' => 'TransEV Sim', 'serialNumber' => 'SIM-'.strtoupper(Str::random(6)), 'firmwareVersion' => '1.0.0']]
                : ['chargePointVendor' => 'TransEV Sim', 'chargePointModel' => 'SimCharger 60', 'chargePointSerialNumber' => 'SIM-'.strtoupper(Str::random(6)), 'firmwareVersion' => '1.0.0'];
            $this->call_('BootNotification', $boot, function (array $r) use ($loop) {
                $this->line('Boot: '.($r['status'] ?? '?').' · heartbeat '.($r['interval'] ?? '?').' s');
                $this->call_('Heartbeat', []);
                $this->status('Available');
                $loop->addPeriodicTimer(max(10, (int) ($r['interval'] ?? 60)), fn () => $this->call_('Heartbeat', []));
                if ($this->option('tag')) {
                    $loop->addTimer(1, fn () => $this->startCharging((string) $this->option('tag')));
                } elseif (! $this->option('wait')) {
                    $loop->addTimer(2, fn () => $this->ws->close());
                }
            });
        }, function (\Throwable $e) use ($loop) {
            $this->error('Could not connect: '.$e->getMessage());
            $loop->stop();
        });
        $loop->run();

        return self::SUCCESS;
    }

    private function startCharging(string $tag): void
    {
        $authorize = $this->v201 ? ['idToken' => ['idToken' => $tag, 'type' => 'Central']] : ['idTag' => $tag];
        $this->call_('Authorize', $authorize, function (array $r) use ($tag) {
            $status = $r['idTagInfo']['status'] ?? $r['idTokenInfo']['status'] ?? '?';
            $this->line("Authorize {$tag}: {$status}");
            if ($status !== 'Accepted') {
                $this->option('wait') ?: $this->ws->close();

                return;
            }
            $this->status('Preparing');
            $connector = (int) $this->option('connector');
            if ($this->v201) {
                $this->txId = (string) Str::uuid();
                $this->call_('TransactionEvent', $this->txEvent('Started', 'Authorized', $tag), fn (array $r) => $this->charge($r['idTokenInfo']['status'] ?? 'Accepted'));
            } else {
                $this->call_('StartTransaction', ['connectorId' => $connector, 'idTag' => $tag, 'meterStart' => (int) round($this->register * 1000), 'timestamp' => $this->ts()],
                    function (array $r) {
                        $this->txId = (string) ($r['transactionId'] ?? '');
                        $this->charge($r['idTagInfo']['status'] ?? '?');
                    });
            }
        });
    }

    private function charge(string $status): void
    {
        $this->line('Transaction '.$this->txId.': '.$status);
        if ($status !== 'Accepted') {
            $this->ws->close();

            return;
        }
        $this->charging = true;
        $this->status('Charging');
        $seconds = max(4, (int) $this->option('seconds'));
        $total = (float) $this->option('kwh');
        $steps = max(1, intdiv($seconds, 2));
        $per = $total / $steps;
        $i = 0;
        $loop = Loop::get();
        $timer = $loop->addPeriodicTimer($seconds / $steps, function () use (&$i, $steps, $per, $total, $seconds, &$timer, $loop) {
            if (! $this->charging) {
                $loop->cancelTimer($timer);

                return;
            }
            $i++;
            $this->register += $per;
            $kw = $total / ($seconds / 3600);
            $this->meter($kw, min(100, 20 + (int) (60 * $i / $steps)));
            $this->line(sprintf('  %.3f kWh delivered', $per * $i));
            if ($i >= $steps) {
                $loop->cancelTimer($timer);
                $this->stopCharging('EVDisconnected');
            }
        });
    }

    private function stopCharging(string $reason): void
    {
        if (! $this->charging) {
            return;
        }
        $this->charging = false;
        $this->status('Finishing');
        $done = function () {
            $this->status('Available');
            $this->info('Session finished at meter '.number_format($this->register, 3).' kWh');
            if (! $this->option('wait')) {
                Loop::get()->addTimer(1, fn () => $this->ws->close());
            }
        };
        if ($this->v201) {
            $this->call_('TransactionEvent', $this->txEvent('Ended', $reason === 'Remote' ? 'RemoteStop' : 'EVDeparted', null, $reason), $done);
        } else {
            $this->call_('StopTransaction', ['transactionId' => (int) $this->txId, 'meterStop' => (int) round($this->register * 1000), 'timestamp' => $this->ts(), 'reason' => $reason], $done);
        }
    }

    private function meter(float $kw, int $soc): void
    {
        $values = $this->v201
            ? [['timestamp' => $this->ts(), 'sampledValue' => [
                ['value' => round($this->register * 1000), 'measurand' => 'Energy.Active.Import.Register', 'unitOfMeasure' => ['unit' => 'Wh']],
                ['value' => round($kw * 1000), 'measurand' => 'Power.Active.Import', 'unitOfMeasure' => ['unit' => 'W']],
                ['value' => $soc, 'measurand' => 'SoC'],
            ]]]
            : [['timestamp' => $this->ts(), 'sampledValue' => [
                ['value' => (string) round($this->register * 1000), 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh'],
                ['value' => (string) round($kw * 1000), 'measurand' => 'Power.Active.Import', 'unit' => 'W'],
                ['value' => (string) $soc, 'measurand' => 'SoC', 'unit' => 'Percent'],
            ]]];
        $this->v201
            ? $this->call_('TransactionEvent', $this->txEvent('Updated', 'MeterValuePeriodic', null, null, $values))
            : $this->call_('MeterValues', ['connectorId' => (int) $this->option('connector'), 'transactionId' => (int) $this->txId, 'meterValue' => $values]);
    }

    private function txEvent(string $type, string $trigger, ?string $tag, ?string $stopReason = null, ?array $values = null): array
    {
        static $seq = 0;

        return array_filter([
            'eventType' => $type, 'timestamp' => $this->ts(), 'triggerReason' => $trigger, 'seqNo' => $seq++,
            'transactionInfo' => array_filter(['transactionId' => $this->txId, 'stoppedReason' => $stopReason]),
            'idToken' => $tag ? ['idToken' => $tag, 'type' => 'Central'] : null,
            'evse' => ['id' => (int) $this->option('connector'), 'connectorId' => 1],
            'meterValue' => $values ?? [['timestamp' => $this->ts(), 'sampledValue' => [['value' => round($this->register * 1000), 'measurand' => 'Energy.Active.Import.Register', 'unitOfMeasure' => ['unit' => 'Wh']]]]],
        ]);
    }

    private function status(string $status): void
    {
        $connector = (int) $this->option('connector');
        $this->v201
            ? $this->call_('StatusNotification', ['timestamp' => $this->ts(), 'connectorStatus' => in_array($status, ['Charging', 'Preparing', 'Finishing'], true) ? 'Occupied' : $status, 'evseId' => $connector, 'connectorId' => 1])
            : $this->call_('StatusNotification', ['connectorId' => $connector, 'errorCode' => 'NoError', 'status' => $status, 'timestamp' => $this->ts()]);
    }

    private function onMessage(string $raw): void
    {
        $f = json_decode($raw, true);
        if (! is_array($f)) {
            return;
        }
        if ($f[0] === 3 || $f[0] === 4) {
            [$action, $cb] = $this->pending[$f[1]] ?? [null, null];
            unset($this->pending[$f[1]]);
            if ($f[0] === 4) {
                $this->error("{$action} → error {$f[2]} {$f[3]}");
            } elseif ($cb) {
                $cb((array) $f[2]);
            }

            return;
        }
        // A command from the central system.
        [, $id, $action, $p] = $f;
        $this->comment("← {$action} ".json_encode($p));
        $answer = match ($action) {
            'UnlockConnector' => ['status' => 'Unlocked'],
            default => ['status' => 'Accepted'],
        };
        $this->ws->send(json_encode([3, $id, $answer]));
        match ($action) {
            'RemoteStartTransaction' => Loop::get()->addTimer(1, fn () => $this->startCharging((string) $p['idTag'])),
            'RequestStartTransaction' => Loop::get()->addTimer(1, fn () => $this->startCharging((string) $p['idToken']['idToken'])),
            'RemoteStopTransaction', 'RequestStopTransaction' => Loop::get()->addTimer(1, fn () => $this->stopCharging('Remote')),
            'TriggerMessage' => $this->status($this->charging ? 'Charging' : 'Available'),
            default => null,
        };
    }

    private function call_(string $action, array $payload, ?callable $then = null): void
    {
        $id = (string) Str::uuid();
        $this->pending[$id] = [$action, $then];
        $this->ws->send(json_encode([2, $id, $action, $payload === [] ? new \stdClass : $payload]));
    }

    private function ts(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
