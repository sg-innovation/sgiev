<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StationStatus;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Franchises\FranchiseResource;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\FranchiseUpdate;
use App\Notifications\NewBookingForOperator;
use App\Services\ChargingSessionService;
use App\Services\FranchiseService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\Reports\ReportFilters;
use App\Services\WorkOrderService;
use App\Support\BusinessRules;
use App\Support\PaymentInstrument;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Review gaps (operator booking alerts, profile photo and city, masked payment method, maintenance work orders) and G3 franchises. */
class GapsAndFranchiseTest extends TestCase
{
    use RefreshDatabase;

    private Station $patna;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->patna = Station::where('code', 'PAT-BLY')->firstOrFail();
        $platform = Company::where('is_platform', true)->value('id');
        $this->admin = User::factory()->create(['company_id' => $platform]);
        $this->admin->assignRole(Role::SuperAdmin->value);
        $this->owner = User::factory()->create(['company_id' => $this->patna->company_id, 'phone' => '9811111111']);
        $this->owner->assignRole(Role::CompanyOwner->value);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->app['session']->flush();

        return $this->actingAs($user);
    }

    private function paidBooking(): Booking
    {
        $customer = User::factory()->create(['phone' => '9876543210']);
        $customer->assignRole(Role::Customer->value);
        $charger = $this->patna->chargers()->where('type', 'DC')->first();
        $this->as($customer)->post("/book/{$this->patna->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->patna->state_code, 'rule_set_id' => RuleSet::activeFor($this->patna)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $payment = $b->payments()->first();
        [, $query] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($query));

        return $b->fresh();
    }

    public function test_station_staff_are_told_about_new_bookings(): void
    {
        $assigned = User::factory()->create(['company_id' => $this->patna->company_id]);
        $assigned->assignRole(Role::StationManager->value);
        $assigned->stations()->attach($this->patna->id);
        $elsewhere = User::factory()->create(['company_id' => $this->patna->company_id]);
        $elsewhere->assignRole(Role::StationManager->value);
        $optedOut = User::factory()->create(['company_id' => $this->patna->company_id]);
        $optedOut->assignRole(Role::OperationsAdmin->value);
        $optedOut->forceFill(['notification_prefs' => ['operator_bookings' => false]])->save();

        $b = $this->paidBooking();
        $this->assertSame('confirmed', $b->status->value);
        Notification::assertSentTo([$this->owner, $assigned], NewBookingForOperator::class, fn ($n) => $n->booking->is($b));
        // Not to a manager of other stations, nor to someone who switched it off. (Patna is Trans EV's own station,
        // so Trans EV's super admins are its operator staff and are told too.)
        Notification::assertNotSentTo([$elsewhere, $optedOut], NewBookingForOperator::class);
        $mail = (new NewBookingForOperator($b))->toMail($this->owner);
        $this->assertStringContainsString($b->code, $mail->subject);

        // Switched off in Business rules: nobody is told.
        BusinessRules::save(['booking.operator_alerts' => '0'], $this->admin->id);
        Notification::fake();
        app(\App\Services\OperatorAlertService::class)->newBooking($b);
        Notification::assertNothingSent();

        // Staff can switch it off for themselves on the notifications page.
        $this->as($assigned)->put('/settings/notifications', ['sms' => true, 'whatsapp' => false, 'push' => true, 'operator_bookings' => false])->assertSessionHasNoErrors();
        $this->assertFalse($assigned->fresh()->notification_prefs['operator_bookings']);
    }

    public function test_receipts_show_a_masked_payment_method(): void
    {
        $this->assertSame('VISA •••• 4242', PaymentInstrument::card('visa', '4242'));
        $this->assertSame('UPI ra•••@okicici', PaymentInstrument::upi('Ravi.Kumar@okicici'));
        $this->assertSame('MASTERCARD •••• 1234', PaymentInstrument::fromAny('XXXX-XXXX-XXXX-1234', 'Mastercard'));
        $this->assertNull(PaymentInstrument::upi('not a vpa'));

        $b = $this->paidBooking();
        $p = $b->payments()->first();
        $this->assertSame('UPI de•••@okaxis', $p->payment_instrument);
        $this->assertSame('UPI · UPI de•••@okaxis', $p->methodLabel());
        $this->get(route('bookings.receipt', $b))->assertOk()->assertInertia(fn ($page) => $page->where('receipt.payment.mode', 'UPI · UPI de•••@okaxis'));
        $this->get(route('bookings.show', $b))->assertOk()->assertInertia(fn ($page) => $page->where('booking.payment.mode', 'UPI · UPI de•••@okaxis'));
        $this->assertStringContainsString('de•••@okaxis', json_encode(Invoice::where('booking_id', $b->id)->first()->site, JSON_UNESCAPED_UNICODE));
    }

    public function test_customers_can_add_a_profile_photo_and_city(): void
    {
        Storage::fake('public');
        $u = User::factory()->create(['email' => 'asha@example.com']);
        $u->assignRole(Role::Customer->value);

        $this->as($u)->patch('/settings/profile', ['name' => 'Asha K', 'email' => 'asha@example.com', 'city' => 'Pune'])->assertSessionHasNoErrors();
        $this->assertSame('Pune', $u->fresh()->city);
        $this->patch('/settings/profile', ['name' => 'Asha K', 'email' => 'asha@example.com', 'city' => '<script>'])->assertSessionHasErrors('city');

        $this->post('/settings/profile/photo', ['photo' => UploadedFile::fake()->image('me.png', 800, 600)])->assertSessionHasNoErrors();
        $path = $u->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);
        $this->assertSame([256, 256], array_slice(getimagesizefromstring(Storage::disk('public')->get($path)), 0, 2));
        $this->get('/settings/profile')->assertInertia(fn ($page) => $page->where('auth.user.avatar', fn ($url) => str_contains($url, $path))->where('auth.user.city', 'Pune'));

        $this->post('/settings/profile/photo', ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->delete('/settings/profile/photo');
        $this->assertNull($u->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_work_orders_take_a_charger_out_of_service_until_done(): void
    {
        $charger = $this->patna->chargers()->first();
        $svc = app(WorkOrderService::class);
        $tech = User::factory()->create(['company_id' => $this->patna->company_id]);
        $tech->assignRole(Role::StationManager->value);
        $tech->stations()->attach($this->patna->id);

        $a = $svc->open($charger, ['type' => 'fault', 'priority' => 'urgent', 'title' => 'Connector not locking', 'take_offline' => true, 'assigned_to' => $tech->id], $this->owner);
        $this->assertMatchesRegularExpression('/^WO-\d{6}$/', $a->number);
        $this->assertSame([$this->patna->id, $this->patna->company_id], [$a->station_id, $a->company_id]);
        $this->assertSame(StationStatus::Maintenance, $charger->fresh()->status);
        Notification::assertSentTo($tech, \App\Notifications\WorkOrderUpdate::class);
        $b = $svc->open($charger, ['type' => 'preventive', 'title' => 'Quarterly service', 'take_offline' => true], $this->owner);

        $this->travel(90)->minutes();
        $svc->start($a, $tech);
        $svc->complete($a->fresh(), ['resolution' => 'Replaced locking actuator.', 'labour_cost' => '500', 'parts_cost' => '1200.50'], $tech);
        $a->refresh();
        $this->assertSame(['done', 90, '1700.50'], [$a->status, $a->downtime_minutes, $a->totalCost()]);
        $this->assertSame(StationStatus::Maintenance, $charger->fresh()->status, 'still out: another work order needs it');
        $svc->cancel($b, $this->owner, 'Moved to next month');
        $this->assertSame(StationStatus::Online, $charger->fresh()->status);
        $this->expectException(ValidationException::class);
        $svc->complete($a, ['resolution' => 'again'], $tech);
    }

    public function test_work_order_screens_and_permissions(): void
    {
        $charger = $this->patna->chargers()->first();
        $this->as($this->owner)->get(WorkOrderResource::getUrl())->assertOk();
        Livewire::test(CreateWorkOrder::class)->fillForm(['station_id' => $this->patna->id, 'charger_id' => $charger->id, 'type' => 'inspection', 'priority' => 'normal', 'title' => 'Annual inspection'])
            ->call('create')->assertHasNoFormErrors();
        $wo = \App\Models\ChargerWorkOrder::sole();
        $this->get(WorkOrderResource::getUrl('view', ['record' => $wo]))->assertOk()->assertSee('Annual inspection');

        $other = User::factory()->create(['company_id' => Station::where('code', 'GG-PUN-BNR')->value('company_id')]);
        $other->assignRole(Role::CompanyOwner->value);
        $this->assertFalse($other->can('view', $wo));
        $auditor = User::factory()->create(['company_id' => $this->patna->company_id]);
        $auditor->assignRole(Role::Auditor->value);
        $this->assertTrue($auditor->can('view', $wo));
        $this->assertFalse($auditor->can('update', $wo));
    }

    public function test_franchise_application_approval_stations_managers_and_payouts(): void
    {
        $lead = User::factory()->create(['company_id' => $this->admin->company_id]);
        $lead->assignRole(Role::SuperAdmin->value);

        // Public application.
        $this->get('/franchise')->assertOk();
        $this->post('/franchise', ['name' => 'Bihar EV Partners', 'contact_name' => 'Rakesh', 'email' => 'Rakesh@Example.com', 'phone' => '98765 43210',
            'city' => 'Patna', 'state' => 'Bihar', 'region' => 'Patna and nearby', 'consent' => '1'])->assertSessionHas('success');
        $f = Franchise::sole();
        $this->assertSame(['pending', 'rakesh@example.com', '9876543210'], [$f->status, $f->email, $f->phone]);
        $this->assertMatchesRegularExpression('/^FR-\d{4}$/', $f->code);
        Notification::assertSentTo($lead, FranchiseUpdate::class, fn ($n) => $n->template === 'franchise.application');

        $svc = app(FranchiseService::class);
        $svc->approve($f, $this->admin, '5', 'Agreed on call');
        $this->assertSame(['active', '5.00'], [$f->fresh()->status, (string) $f->fresh()->commission_percent]);
        Notification::assertSentOnDemand(FranchiseUpdate::class, fn ($n) => $n->template === 'franchise.approved');

        // Stations and a regional manager.
        $svc->syncStations($f, [$this->patna->id]);
        $rm = User::factory()->create(['company_id' => $this->admin->company_id]);
        $svc->syncManagers($f, [$rm->id]);
        $rm = $rm->fresh();
        $this->assertTrue($rm->hasRole(Role::RegionalManager->value));
        $this->assertSame([$this->patna->id], ChargingSessionService::stations($rm)->pluck('id')->all());
        $other = Franchise::create(['name' => 'Other', 'contact_name' => 'X', 'email' => 'x@example.com', 'status' => 'active']);
        try {
            $svc->syncStations($other, [$this->patna->id]);
            $this->fail('A station can only be in one franchise');
        } catch (ValidationException) {
        }

        // Regional manager's reports are limited to the franchise; no operators tab.
        $booking = $this->paidBooking();
        $filters = ReportFilters::forUser($rm, ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame([$this->patna->id], $filters->stationIds);
        $this->assertNull($filters->companyId);
        $this->as($rm)->get(Reports::getUrl())->assertOk();
        $this->assertArrayNotHasKey('operators', Livewire::test(Reports::class)->instance()->tabs());
        $this->get(FranchiseResource::getUrl())->assertOk()->assertSee('Bihar EV Partners')->assertDontSee('Other');
        $this->assertFalse($rm->can('update', $other));

        // Monthly payout: 5% of the month's net sales of its stations.
        $payout = $svc->generatePayout($f->fresh(), CarbonImmutable::parse('2026-11-01', 'Asia/Kolkata'));
        $taxable = (string) Invoice::where('booking_id', $booking->id)->value('taxable_amount');
        $this->assertSame(number_format((float) $taxable, 2, '.', ''), (string) $payout->net_sales);
        $this->assertSame(bcdiv(bcmul($taxable, '5', 4), '100', 2), (string) $payout->commission_amount);
        $this->assertSame($this->patna->id, $payout->lines[0]['station_id']);
        $svc->approvePayout($payout, $this->admin);
        $svc->markPaid($payout->fresh(), $this->admin, 'UTR12345');
        $this->assertSame(['paid', 'UTR12345'], [$payout->fresh()->status, $payout->fresh()->payment_reference]);
        $this->assertSame('paid', $svc->generatePayout($f->fresh(), CarbonImmutable::parse('2026-11-01', 'Asia/Kolkata'))->status, 'paid months are never redrafted');

        // Suspended: the regional manager loses the stations.
        $svc->setStatus($f, 'suspended', $this->admin);
        $this->assertSame([], ChargingSessionService::stations($rm)->pluck('id')->all());

        // Admin screens render.
        $this->as($this->admin)->get(FranchiseResource::getUrl())->assertOk();
        $this->get(FranchiseResource::getUrl('edit', ['record' => $f]))->assertOk()->assertSee('Bihar EV Partners');
        Livewire::test(\App\Filament\Resources\Franchises\RelationManagers\PayoutsRelationManager::class, ['ownerRecord' => $f, 'pageClass' => \App\Filament\Resources\Franchises\Pages\EditFranchise::class])
            ->assertOk()->assertCanSeeTableRecords($f->payouts()->get());
    }
}
