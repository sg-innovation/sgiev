<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->station = Station::where('code', 'PAT-BLY')->first();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hold(?Station $station = null, string $at = '2026-11-25 12:00'): Booking
    {
        $station ??= $this->station;
        $charger = $station->chargers()->where('type', 'DC')->first();
        $this->actingAs($this->customer)->post("/book/{$station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $station->state_code, 'rule_set_id' => RuleSet::activeFor($station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();

        return Booking::latest('id')->first();
    }

    private function payWithSimulator(Booking $booking, string $status = 'SUCCESS')
    {
        $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/pay")->assertRedirectContains('/dev/sabpaisa/checkout/');
        $this->actingAs($this->customer)->postJson("/account/bookings/{$booking->code}/pay")->assertOk()->assertJsonPath('url', fn ($u) => str_contains($u, '/dev/sabpaisa/checkout/'));
        $payment = $booking->payments()->first();
        [, $query] = SimulatedSabPaisaGateway::complete($payment, $status);

        return $this->actingAs($this->customer)->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($query));
    }

    private function connectSabPaisa(Company $company): void
    {
        config(['payments.sabpaisa.simulate' => false]);
        $company->fill([
            'sabpaisa_env' => 'staging', 'sabpaisa_merchant_id' => 'TESTMID',
            'sabpaisa_api_key' => 'sk_test_key', 'sabpaisa_secret_key' => 'test_secret', 'sabpaisa_webhook_secret' => 'wh_secret',
        ])->save();
    }

    public function test_successful_payment_confirms_booking_and_logs_events(): void
    {
        $booking = $this->hold();
        $this->payWithSimulator($booking)->assertRedirect("/account/bookings/{$booking->code}")->assertSessionHas('success');

        $booking->refresh();
        $payment = $booking->payments()->first();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(Payment::SUCCESS, $payment->status);
        $this->assertSame($booking->total_amount, $payment->paid_amount);
        $this->assertSame($booking->company_id, $payment->company_id);
        $this->assertSame(['create', 'return', 'enquiry'], PaymentEvent::orderBy('id')->pluck('source')->all());
        $this->assertTrue(PaymentEvent::where('source', 'return')->first()->signature_valid);
        Notification::assertSentTo($this->customer, BookingConfirmed::class);

        $this->get("/account/bookings/{$booking->code}/receipt")->assertOk();
        // Repeating the return (or a duplicate webhook) changes nothing.
        $this->get("/payments/sabpaisa/return/{$payment->uuid}")->assertRedirect();
        $this->assertSame(1, $booking->payments()->where('status', 'success')->count());
    }

    public function test_failed_payment_keeps_the_hold_for_a_retry(): void
    {
        $booking = $this->hold();
        $this->payWithSimulator($booking, 'FAILED')->assertSessionHas('error');

        $this->assertSame(BookingStatus::Held, $booking->fresh()->status);
        $this->assertSame(Payment::FAILED, $booking->payments()->first()->status);

        $this->payWithSimulator($booking, 'SUCCESS');
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_payment_events_are_append_only(): void
    {
        $this->payWithSimulator($this->hold());
        $this->expectException(LogicException::class);
        PaymentEvent::first()->delete();
    }

    public function test_real_gateway_request_is_signed_and_status_comes_from_enquiry(): void
    {
        $company = $this->station->company;
        $this->connectSabPaisa($company);
        $booking = $this->hold();

        Http::fake([
            '*/api/v2/payments' => Http::response(['success' => true, 'paymentId' => 'PAY_1', 'checkoutUrl' => 'https://staging-sb-checkout.sabpaisa.in/checkout/PAY_1', 'clientSecret' => 'cs_123', 'expiresAt' => now()->addMinutes(30)->toIso8601String()], 201),
            '*/api/v2/payments/enquiry' => Http::sequence()
                ->push(['status' => 'PENDING'], 200)
                ->push(['status' => 'SUCCESS', 'txnId' => 'SP_99', 'paidAmount' => (float) $booking->total_amount, 'paymentMode' => 'UPI', 'bankTxnId' => 'B1', 'bankRrn' => 'R1'], 200),
        ]);

        $res = $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/pay");
        $this->assertStringContainsString('clientSecret=cs_123', (string) $res->headers->get('Location'));
        $this->assertStringStartsWith('https://staging-sb-checkout.sabpaisa.in/checkout/PAY_1', (string) $res->headers->get('Location'));

        Http::assertSent(function ($req) use ($booking) {
            if (! str_ends_with($req->url(), '/api/v2/payments')) {
                return false;
            }
            $d = $req->data();
            $expected = hash_hmac('sha256', "TESTMID|{$d['merchantTxnId']}|{$d['amount']}|INR|{$d['timestamp']}", 'test_secret');

            return $req->hasHeader('X-Api-Key', 'sk_test_key') && $d['checksum'] === $expected
                && $d['amount'] === (int) round((float) $booking->total_amount * 100) && $d['customerPhone'] === '9876543210';
        });

        $payment = $booking->payments()->first();
        // A forged "SUCCESS" return is not trusted: enquiry says PENDING, so nothing is confirmed.
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?status=SUCCESS&merchant_txn_id={$payment->merchant_txn_id}&signature=forged");
        $this->assertSame(BookingStatus::Held, $booking->fresh()->status);
        $this->assertFalse(PaymentEvent::where('source', 'return')->first()->signature_valid);

        // The scheduled reconciliation picks up the real result.
        app(PaymentService::class)->reconcile($payment->fresh());
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame('SP_99', $payment->fresh()->gateway_txn_id);
    }

    public function test_webhook_signature_and_deduplication(): void
    {
        $company = $this->station->company;
        $this->connectSabPaisa($company);
        Http::fake([
            '*/api/v2/payments' => Http::response(['paymentId' => 'PAY_2', 'checkoutUrl' => 'https://x.test/c', 'clientSecret' => 'cs'], 201),
        ]);
        $booking = $this->hold();
        $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/pay");
        $payment = $booking->payments()->first();
        Http::fake(['*/api/v2/payments/enquiry' => Http::response(['status' => 'SUCCESS', 'txnId' => 'SP_7', 'paidAmount' => (float) $booking->total_amount, 'paymentMode' => 'CARD'], 200)]);

        $body = json_encode(['event' => 'payment.success', 'merchant_txn_id' => $payment->merchant_txn_id, 'status' => 'SUCCESS', 'idempotency_key' => 'SP_7_SUCCESS']);
        $ts = (string) now()->getTimestampMs();
        $sig = $ts.'.'.base64_encode(hash_hmac('sha256', $ts.'.'.$body, 'wh_secret', true));

        $this->call('POST', "/webhooks/sabpaisa/{$company->slug}", [], [], [], ['HTTP_X_SABPAISA_SIGNATURE' => 'bad.sig', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertSame(BookingStatus::Held, $booking->fresh()->status);

        $this->call('POST', "/webhooks/sabpaisa/{$company->slug}", [], [], [], ['HTTP_X_SABPAISA_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->call('POST', "/webhooks/sabpaisa/{$company->slug}", [], [], [], ['HTTP_X_SABPAISA_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(1, PaymentEvent::where('idempotency_key', 'SP_7_SUCCESS')->count());

        // Another company's webhook URL cannot touch this payment.
        $gg = Company::where('slug', 'greengrid-demo')->first();
        $this->connectSabPaisa($gg);
        $gg->fill(['sabpaisa_webhook_secret' => 'greengrid_own_secret'])->save();
        $this->call('POST', "/webhooks/sabpaisa/{$gg->slug}", [], [], [], ['HTTP_X_SABPAISA_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
    }

    public function test_paying_after_the_slot_was_taken_is_refunded_automatically(): void
    {
        $booking = $this->hold();
        $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/pay");
        $payment = $booking->payments()->first();

        // Hold expires, someone else books the same slot, then the first customer's payment arrives.
        Carbon::setTestNow(now()->addMinutes(20));
        app(\App\Services\BookingService::class)->expireHolds();
        $other = User::factory()->create(['phone' => '9123456789']);
        $this->customer = $other;
        $this->hold();
        $this->assertSame(2, Booking::count());

        [, $query] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        app(PaymentService::class)->handleReturn($payment, $query, '127.0.0.1');

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
        $refund = Refund::first();
        $this->assertSame($booking->total_amount, $refund->amount);
        $this->assertSame(Refund::SUCCESS, $refund->status);
    }

    public function test_customer_cancellation_refund_follows_the_policy(): void
    {
        $booking = $this->hold(null, '2026-11-24 14:00'); // 4 hours away
        $this->payWithSimulator($booking);
        $service = app(RefundService::class);

        $this->assertSame(100, $service->quote($booking->fresh())['percent']);
        Carbon::setTestNow(Carbon::parse('2026-11-24 13:00', 'Asia/Kolkata'));
        $q = $service->quote($booking->fresh());
        $this->assertSame(50, $q['percent']);
        $this->assertSame(\App\Services\GstService::round(bcdiv((string) $booking->total_amount, '2', 4)), $q['refund']);
        Carbon::setTestNow(Carbon::parse('2026-11-24 13:45', 'Asia/Kolkata'));
        $this->assertSame(0, $service->quote($booking->fresh())['percent']);

        Carbon::setTestNow(Carbon::parse('2026-11-24 13:00', 'Asia/Kolkata'));
        $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/cancel")->assertSessionHas('success');
        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $refund = $booking->refunds()->first();
        $this->assertSame($q['refund'], $refund->amount);
        $this->assertSame($q['retained'], $refund->fee_retained);
    }

    public function test_operator_cancellation_refunds_in_full_and_admin_screens_work(): void
    {
        $booking = $this->hold();
        $this->payWithSimulator($booking);
        $owner = User::factory()->create(['company_id' => $this->station->company_id]);
        $owner->assignRole(Role::CompanyOwner->value);

        app(RefundService::class)->cancel($booking->fresh(), 'Charger fault', $owner, true);
        $this->assertSame($booking->total_amount, $booking->refunds()->first()->amount);

        $this->actingAs($owner)->get('/admin/payments')->assertOk()->assertSee($booking->payments()->first()->merchant_txn_id);
        $this->actingAs($owner)->get('/admin/payments/'.$booking->payments()->first()->id)->assertOk();

        // GreenGrid staff cannot see Trans EV payments.
        $gg = User::factory()->create(['company_id' => Company::where('slug', 'greengrid-demo')->value('id')]);
        $gg->assignRole(Role::CompanyOwner->value);
        $this->actingAs($gg)->get('/admin/payments/'.$booking->payments()->first()->id)->assertNotFound();
        $this->assertFalse($gg->can('refund', $booking->payments()->first()));
    }

    public function test_refund_cannot_exceed_what_was_paid(): void
    {
        $booking = $this->hold();
        $this->payWithSimulator($booking);
        $payment = $booking->payments()->first();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RefundService::class)->refundPayment($payment, bcadd((string) $payment->paid_amount, '1', 2), 'too much', 'manual', null);
    }

    public function test_booking_is_refused_when_the_operator_has_no_payment_account(): void
    {
        config(['payments.sabpaisa.simulate' => false]);
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'name' => 'T', 'email' => 't@example.com', 'phone' => '9876543210',
            'state_code' => '10', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasErrors('start');
    }

    public function test_other_customers_cannot_pay_or_view_receipts(): void
    {
        $booking = $this->hold();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post("/account/bookings/{$booking->code}/pay")->assertForbidden();
        $this->payWithSimulator($booking);
        $this->actingAs($stranger)->get("/account/bookings/{$booking->code}/receipt")->assertForbidden();
    }
}
