<?php

namespace Tests\Feature;

use App\Enums\ConnectorLiveStatus;
use App\Enums\Role;
use App\Filament\Resources\ChargerControl\ChargerControlResource;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\ChargerWorkOrder;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppCommand;
use App\Models\OcppFault;
use App\Models\OcppMessage;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\ChargingSessionService;
use App\Services\Ocpp\OcppCentralSystem;
use App\Services\Ocpp\OcppCommandService;
use App\Services\Ocpp\OcppError;
use App\Services\Ocpp\OcppSocketApp;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Ratchet\ConnectionInterface;
use Tests\TestCase;

/** Phase F: OCPP 1.6J / 2.0.1 central system, remote commands, automatic billing, faults and the WebSocket layer. */
class OcppTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Charger $charger;

    private Connector $connector;

    private User $owner;

    private OcppCentralSystem $cs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'PAT-BLY')->firstOrFail();
        $this->charger = $this->station->chargers()->where('type', 'DC')->firstOrFail();
        $this->charger->forceFill(['ocpp_identity' => 'CP-TEST-01', 'ocpp_password' => Hash::make('s3cret-pass'), 'ocpp_protocol' => '1.6J'])->save();
        $this->connector = $this->charger->connectorPoints()->orderBy('connector_no')->firstOrFail();
        $this->owner = User::factory()->create(['company_id' => $this->station->company_id]);
        $this->owner->assignRole(Role::CompanyOwner->value);
        $this->cs = app(OcppCentralSystem::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A paid booking on the OCPP charger, then the clock moved to its start. */
    private function paidBooking(): Booking
    {
        $customer = User::factory()->create(['phone' => '9876543210']);
        $customer->assignRole(Role::Customer->value);
        $this->actingAs($customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $this->charger->id, 'start' => Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => $this->connector->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->station->state_code, 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q));
        $this->travelTo(Carbon::parse('2026-11-24 12:02', 'Asia/Kolkata'));

        return $b->fresh();
    }

    private function ocpp(string $action, array $payload, string $protocol = OcppCentralSystem::V16): array
    {
        return $this->cs->handleCall($this->charger->fresh(), $protocol, $action, $payload);
    }

    public function test_authentication_uses_identity_and_password(): void
    {
        $basic = fn (string $u, string $p) => 'Basic '.base64_encode("{$u}:{$p}");
        $this->assertTrue($this->cs->authenticate('CP-TEST-01', $basic('CP-TEST-01', 's3cret-pass'))?->is($this->charger));
        $this->assertNull($this->cs->authenticate('CP-TEST-01', $basic('CP-TEST-01', 'wrong')));
        $this->assertNull($this->cs->authenticate('CP-TEST-01', null));
        $this->assertNull($this->cs->authenticate('CP-UNKNOWN', $basic('CP-UNKNOWN', 's3cret-pass')));
        $this->charger->forceFill(['ocpp_password' => null])->save();
        $this->assertNull($this->cs->authenticate('CP-TEST-01', null), 'no password is refused unless allowed for testing');
    }

    public function test_ocpp16_session_is_started_metered_stopped_and_billed_from_the_meter(): void
    {
        $b = $this->paidBooking();
        $boot = $this->ocpp('BootNotification', ['chargePointVendor' => 'ABB', 'chargePointModel' => 'Terra 54', 'firmwareVersion' => '1.8.2']);
        $this->assertSame(['Accepted', 300], [$boot['status'], $boot['interval']]);
        $this->assertSame(['ABB', 'Terra 54', '1.8.2'], [$this->charger->fresh()->ocpp_vendor, $this->charger->fresh()->ocpp_model, $this->charger->fresh()->firmware_version]);
        $this->assertArrayHasKey('currentTime', $this->ocpp('Heartbeat', []));

        $this->ocpp('StatusNotification', ['connectorId' => $this->connector->connector_no, 'errorCode' => 'NoError', 'status' => 'Preparing']);
        $this->assertSame(ConnectorLiveStatus::Preparing, $this->connector->fresh()->live_status);
        $this->assertSame('Invalid', $this->ocpp('Authorize', ['idTag' => 'NOT-A-BOOKING'])['idTagInfo']['status']);
        $this->assertSame('Accepted', $this->ocpp('Authorize', ['idTag' => strtolower($b->code)])['idTagInfo']['status']);

        $start = $this->ocpp('StartTransaction', ['connectorId' => $this->connector->connector_no, 'idTag' => $b->code, 'meterStart' => 1_000_000, 'timestamp' => now()->toIso8601String()]);
        $this->assertSame('Accepted', $start['idTagInfo']['status']);
        $session = ChargingSession::where('booking_id', $b->id)->sole();
        $this->assertSame([(string) $start['transactionId'], 'ocpp', '1000.000', 'checked_in'], [$session->ocpp_transaction_id, $session->source, (string) $session->meter_start_kwh, $b->fresh()->status->value]);
        $this->assertSame(ConnectorLiveStatus::Charging, $this->connector->fresh()->live_status);

        $this->ocpp('MeterValues', ['connectorId' => $this->connector->connector_no, 'transactionId' => $start['transactionId'], 'meterValue' => [['timestamp' => now()->toIso8601String(), 'sampledValue' => [
            ['value' => '1004500', 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh'],
            ['value' => '48000', 'measurand' => 'Power.Active.Import', 'unit' => 'W'],
            ['value' => '61', 'measurand' => 'SoC', 'unit' => 'Percent'],
            ['value' => '402.5', 'measurand' => 'Voltage', 'unit' => 'V'],
        ]]]]);
        $mv = $session->meterValues()->where('context', 'sample')->sole();
        $this->assertSame(['1004.500', '4.500', '48.00', 61, '402.50'], [(string) $mv->register_kwh, (string) $mv->energy_kwh, (string) $mv->power_kw, $mv->soc, (string) $mv->voltage_v]);

        $this->travel(20)->minutes();
        $this->assertSame('Accepted', $this->ocpp('StopTransaction', ['transactionId' => $start['transactionId'], 'meterStop' => 1_012_000, 'timestamp' => now()->toIso8601String(), 'reason' => 'EVDisconnected'])['idTagInfo']['status']);
        $session->refresh();
        $b->refresh();
        $this->assertSame(['completed', '12.000', 'meter', 'completed'], [$session->status, (string) $session->energy_kwh, $session->energy_source, $session->stop_reason]);
        $this->assertSame('completed', $b->status->value);
        $this->assertSame('12.000', (string) $b->final_kwh, 'final bill uses the charger meter');
        $this->assertNotNull($b->trueup_status);
        // Stopping twice changes nothing.
        $this->ocpp('StopTransaction', ['transactionId' => $start['transactionId'], 'meterStop' => 1_020_000, 'timestamp' => now()->toIso8601String()]);
        $this->assertSame('12.000', (string) $session->fresh()->energy_kwh);
        $this->expectException(OcppError::class);
        $this->ocpp('GetCompositeSchedule', []);
    }

    public function test_ocpp201_transaction_events_and_remote_start(): void
    {
        $b = $this->paidBooking();
        $this->charger->forceFill(['ocpp_protocol' => '2.0.1'])->save();
        $v = OcppCentralSystem::V201;
        $this->assertSame('Accepted', $this->ocpp('BootNotification', ['reason' => 'PowerUp', 'chargingStation' => ['model' => 'X', 'vendorName' => 'Y']], $v)['status']);
        $this->ocpp('StatusNotification', ['timestamp' => now()->toIso8601String(), 'connectorStatus' => 'Occupied', 'evseId' => $this->connector->connector_no, 'connectorId' => 1], $v);
        $this->assertSame(ConnectorLiveStatus::Occupied, $this->connector->fresh()->live_status);

        // Remote start: 2.0.1 message shape.
        $cmd = app(OcppCommandService::class)->remoteStart($b, $this->owner);
        [$action, $payload] = app(OcppCommandService::class)->build($cmd, $v);
        $this->assertSame(['RequestStartTransaction', $b->code, 'Central'], [$action, $payload['idToken']['idToken'], $payload['idToken']['type']]);
        [$a16, $p16] = app(OcppCommandService::class)->build($cmd, OcppCentralSystem::V16);
        $this->assertSame(['RemoteStartTransaction', $b->code], [$a16, $p16['idTag']]);

        $meter = fn (int $wh) => [['timestamp' => now()->toIso8601String(), 'sampledValue' => [['value' => $wh, 'measurand' => 'Energy.Active.Import.Register', 'unitOfMeasure' => ['unit' => 'kWh', 'multiplier' => -3]]]]];
        $tx = ['transactionId' => 'f9b0-tx-1'];
        $r = $this->ocpp('TransactionEvent', ['eventType' => 'Started', 'timestamp' => now()->toIso8601String(), 'triggerReason' => 'RemoteStart', 'seqNo' => 0,
            'transactionInfo' => $tx, 'idToken' => ['idToken' => $b->code, 'type' => 'Central'], 'evse' => ['id' => $this->connector->connector_no, 'connectorId' => 1], 'meterValue' => $meter(2_000_000)], $v);
        $this->assertSame('Accepted', $r['idTokenInfo']['status']);
        $session = ChargingSession::where('ocpp_transaction_id', 'f9b0-tx-1')->sole();
        $this->assertSame('2000.000', (string) $session->meter_start_kwh);
        $this->ocpp('TransactionEvent', ['eventType' => 'Updated', 'timestamp' => now()->toIso8601String(), 'triggerReason' => 'MeterValuePeriodic', 'seqNo' => 1, 'transactionInfo' => $tx, 'meterValue' => $meter(2_003_000)], $v);
        $this->assertSame('3.000', (string) $session->meterValues()->where('context', 'sample')->value('energy_kwh'));
        $this->travel(15)->minutes();
        $this->ocpp('TransactionEvent', ['eventType' => 'Ended', 'timestamp' => now()->toIso8601String(), 'triggerReason' => 'EVDeparted', 'seqNo' => 2, 'transactionInfo' => $tx + ['stoppedReason' => 'EVDisconnected'], 'meterValue' => $meter(2_008_250)], $v);
        $this->assertSame(['completed', '8.250'], [$session->fresh()->status, (string) $session->fresh()->energy_kwh]);
        $this->assertSame('completed', $b->fresh()->status->value);
    }

    public function test_staff_and_customer_stops_go_to_the_charger_and_staff_starts_are_adopted(): void
    {
        $b = $this->paidBooking();
        $this->charger->forceFill(['ocpp_connected' => true])->save();
        $sessions = app(ChargingSessionService::class);

        // Staff start in the console on a connected OCPP charger → session + remote start; the charger's StartTransaction adopts it.
        $s = $sessions->startForBooking($b, $this->owner);
        $this->assertSame('remote_start', OcppCommand::sole()->command);
        $start = $this->ocpp('StartTransaction', ['connectorId' => $this->connector->connector_no, 'idTag' => $b->code, 'meterStart' => 500_000, 'timestamp' => now()->toIso8601String()]);
        $this->assertSame($s->id, ChargingSession::where('ocpp_transaction_id', (string) $start['transactionId'])->value('id'));
        $this->assertSame('500.000', (string) $s->fresh()->meter_start_kwh);

        // Staff "Stop" without a reading → remote stop; the session stays active until the charger reports it.
        $stopped = $sessions->stop($s->fresh(), $this->owner, null, null, 'completed');
        $this->assertSame('active', $stopped->status);
        $this->assertSame('remote_stop', OcppCommand::latest('id')->first()->command);
        // Customer "Stop charging" reuses the pending stop command.
        app(\App\Services\LiveSessionService::class)->requestStop($b->fresh(), $b->user);
        $this->assertSame(1, OcppCommand::where('command', 'remote_stop')->count());
    }

    public function test_faults_open_a_work_order_and_clear_when_the_charger_recovers(): void
    {
        $this->ocpp('StatusNotification', ['connectorId' => $this->connector->connector_no, 'errorCode' => 'GroundFailure', 'status' => 'Faulted', 'info' => 'RCD tripped']);
        $fault = OcppFault::sole();
        $this->assertSame(['GroundFailure', 'RCD tripped'], [$fault->error_code, $fault->info]);
        $wo = ChargerWorkOrder::sole();
        $this->assertSame(['fault', 'high', $fault->work_order_id], [$wo->type, $wo->priority, $wo->id]);
        $this->assertSame(ConnectorLiveStatus::Faulted, $this->connector->fresh()->live_status);
        // Repeated reports of the same error do not pile up.
        $this->ocpp('StatusNotification', ['connectorId' => $this->connector->connector_no, 'errorCode' => 'GroundFailure', 'status' => 'Faulted']);
        $this->assertSame(1, OcppFault::count());
        $this->ocpp('StatusNotification', ['connectorId' => $this->connector->connector_no, 'errorCode' => 'NoError', 'status' => 'Available']);
        $this->assertNotNull($fault->fresh()->cleared_at);
        $this->assertSame(ConnectorLiveStatus::Available, $this->connector->fresh()->live_status);
    }

    public function test_commands_answers_timeouts_and_offline_sweep(): void
    {
        $svc = app(OcppCommandService::class);
        $reset = $svc->queue($this->charger, 'reset', ['type' => 'hard'], $this->owner);
        $this->assertSame(['Reset', ['type' => 'Hard']], $svc->build($reset, OcppCentralSystem::V16));
        $this->assertSame(['Reset', ['type' => 'Immediate']], $svc->build($reset, OcppCentralSystem::V201));
        $svc->markSent($reset, 'Reset', 'uid-1');
        $this->assertSame('accepted', $svc->resolve('uid-1', ['status' => 'Accepted'])->status);
        $unlock = $svc->queue($this->charger, 'unlock', ['connectorNo' => 1], $this->owner);
        $svc->markSent($unlock, 'UnlockConnector', 'uid-2');
        $this->assertSame('rejected', $svc->resolve('uid-2', ['status' => 'UnlockFailed'])->status);
        $late = $svc->queue($this->charger, 'trigger', [], $this->owner);
        $svc->markSent($late, 'TriggerMessage', 'uid-3');
        $this->travel(2)->minutes();
        $svc->expire();
        $this->assertSame('timeout', $late->fresh()->status);

        $this->charger->forceFill(['ocpp_connected' => true, 'last_heartbeat_at' => now()])->save();
        $this->travel(20)->minutes();
        $this->assertSame(1, $this->cs->markSilentOffline());
        $this->assertFalse($this->charger->fresh()->ocpp_connected);
        $this->assertSame(ConnectorLiveStatus::Unavailable, $this->connector->fresh()->live_status);
    }

    public function test_websocket_layer_answers_calls_logs_frames_and_sends_queued_commands(): void
    {
        $app = new OcppSocketApp($this->cs, app(OcppCommandService::class));
        $conn = new FakeOcppConnection('/ocpp/CP-TEST-01', 'Basic '.base64_encode('CP-TEST-01:s3cret-pass'), 'ocpp1.6');
        $app->onOpen($conn);
        $this->assertFalse($conn->closed);
        $this->assertTrue($this->charger->fresh()->ocpp_connected);

        $app->onMessage($conn, json_encode([2, 'm1', 'Heartbeat', new \stdClass]));
        $reply = json_decode($conn->sent[0], true);
        $this->assertSame([3, 'm1'], array_slice($reply, 0, 2));
        $this->assertArrayHasKey('currentTime', $reply[2]);
        $app->onMessage($conn, json_encode([2, 'm2', 'StatusNotification', ['connectorId' => 1, 'errorCode' => 'NoError', 'status' => 'Available']]));
        $this->assertStringContainsString('[3,"m2",{}]', $conn->sent[1], 'an empty result is a JSON object');
        $app->onMessage($conn, json_encode([2, 'm3', 'NoSuchAction', []]));
        $this->assertSame([4, 'm3', 'NotImplemented'], array_slice(json_decode($conn->sent[2], true), 0, 3));
        $app->onMessage($conn, 'not json');
        $this->assertSame('FormationViolation', json_decode($conn->sent[3], true)[2]);

        // A queued command is sent on the next flush; the charger's answer resolves it.
        $cmd = app(OcppCommandService::class)->queue($this->charger, 'trigger', ['message' => 'StatusNotification'], $this->owner);
        $app->flushCommands();
        $frame = json_decode(end($conn->sent), true);
        $this->assertSame([2, 'TriggerMessage', 'StatusNotification'], [$frame[0], $frame[2], $frame[3]['requestedMessage']]);
        $app->onMessage($conn, json_encode([3, $frame[1], ['status' => 'Accepted']]));
        $this->assertSame('accepted', $cmd->fresh()->status);
        $this->assertGreaterThanOrEqual(6, OcppMessage::where('charger_id', $this->charger->id)->count());

        $app->onClose($conn);
        $this->assertFalse($this->charger->fresh()->ocpp_connected);

        // Wrong password or no OCPP sub-protocol: refused.
        $bad = new FakeOcppConnection('/ocpp/CP-TEST-01', 'Basic '.base64_encode('CP-TEST-01:nope'), 'ocpp1.6');
        $app->onOpen($bad);
        $this->assertTrue($bad->closed);
        $noProto = new FakeOcppConnection('/ocpp/CP-TEST-01', 'Basic '.base64_encode('CP-TEST-01:s3cret-pass'), 'chat');
        $app->onOpen($noProto);
        $this->assertTrue($noProto->closed);
    }

    public function test_charger_control_screens_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(ChargerControlResource::getUrl())->assertOk();
        $this->get(ChargerControlResource::getUrl('view', ['record' => $this->charger]))->assertOk()->assertSee('CP-TEST-01');
        \Livewire\Livewire::test(\App\Filament\Resources\ChargerControl\Pages\ViewChargerControl::class, ['record' => $this->charger->getRouteKey()])
            ->callAction('setup', ['ocpp_identity' => 'CP-NEW-02', 'ocpp_protocol' => '2.0.1', 'new_password' => true])->assertHasNoActionErrors();
        $c = $this->charger->fresh();
        $this->assertSame(['CP-NEW-02', '2.0.1'], [$c->ocpp_identity, $c->ocpp_protocol]);
        $this->assertFalse(Hash::check('s3cret-pass', $c->ocpp_password), 'a new password replaced the old one');
        $this->assertArrayNotHasKey('ocpp_password', $c->toArray());

        $other = User::factory()->create(['company_id' => Station::where('code', 'GG-PUN-BNR')->value('company_id')]);
        $other->assignRole(Role::CompanyOwner->value);
        $this->actingAs($other)->get(ChargerControlResource::getUrl('view', ['record' => $this->charger]))->assertNotFound();
        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $this->actingAs($customer)->get(ChargerControlResource::getUrl())->assertForbidden();
    }

    public function test_connector_status_actions_accept_the_enum_from_the_select(): void
    {
        $this->actingAs($this->owner);
        \Livewire\Livewire::test(\App\Filament\Resources\Connectors\Pages\ListConnectors::class)
            ->callTableAction('live', $this->connector, ['status' => ConnectorLiveStatus::Faulted->value, 'note' => 'Cable damaged'])->assertHasNoTableActionErrors();
        $this->assertSame([ConnectorLiveStatus::Faulted, 'Cable damaged'], [$this->connector->fresh()->live_status, $this->connector->fresh()->live_note]);
        \Livewire\Livewire::test(\App\Filament\Resources\Connectors\Pages\ListConnectors::class)
            ->callTableAction('availability', $this->connector, ['status' => \App\Enums\StationStatus::Maintenance->value, 'note' => 'Service'])->assertHasNoTableActionErrors();
        $this->assertSame(\App\Enums\StationStatus::Maintenance, $this->connector->fresh()->status);
    }
}

/** Minimal stand-in for a Ratchet connection. */
class FakeOcppConnection implements ConnectionInterface
{
    public int $resourceId;

    public $httpRequest;

    public array $sent = [];

    public bool $closed = false;

    public function __construct(string $path, string $auth, string $protocol)
    {
        static $id = 100;
        $this->resourceId = $id++;
        $this->httpRequest = new PsrRequest('GET', 'ws://localhost:9000'.$path, ['Authorization' => $auth, 'Sec-WebSocket-Protocol' => $protocol]);
    }

    public function send($data)
    {
        $this->sent[] = (string) $data;

        return $this;
    }

    public function close()
    {
        $this->closed = true;
    }
}
