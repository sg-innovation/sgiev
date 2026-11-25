<?php

namespace App\Console\Commands;

use App\Services\Ocpp\OcppCentralSystem;
use App\Services\Ocpp\OcppCommandService;
use App\Services\Ocpp\OcppSocketApp;
use Illuminate\Console\Command;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

/**
 * Phase F: run the OCPP central system (1.6J and 2.0.1) as a WebSocket server.
 * In production run it under Supervisor and put Nginx in front for wss:// (see README → OCPP).
 */
class OcppServe extends Command
{
    protected $signature = 'ocpp:serve {--host= : Interface to listen on} {--port= : Port to listen on}';

    protected $description = 'Run the OCPP central system (WebSocket server for chargers)';

    public function handle(OcppCentralSystem $central, OcppCommandService $commands): int
    {
        $host = $this->option('host') ?: config('services.ocpp.host', '0.0.0.0');
        $port = (int) ($this->option('port') ?: config('services.ocpp.port', 9000));
        $app = new OcppSocketApp($central, $commands, fn (string $line) => $this->line('['.now()->format('H:i:s').'] '.$line));

        $loop = Loop::get();
        $ws = new WsServer($app);
        $ws->enableKeepAlive($loop, 30);
        $server = new IoServer(new HttpServer($ws), new SocketServer("{$host}:{$port}", [], $loop), $loop);

        $loop->addPeriodicTimer(1, fn () => $app->flushCommands());
        $loop->addPeriodicTimer(30, fn () => $app->housekeeping());
        $this->info("OCPP central system listening on ws://{$host}:{$port}/ocpp/{identity} (ocpp1.6, ocpp2.0.1)");
        $server->run();

        return self::SUCCESS;
    }
}
