<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\PaymentEvent;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Notifications\TemplateTest;
use App\Services\Messaging\Channels;
use App\Services\Messaging\Providers\SgiConnects;
use App\Services\Messaging\Providers\SmsBirds;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\RazorpayGateway;
use App\Services\Payments\SabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Each channel can use either provider: SabPaisa or Razorpay (per company), MSG91 or SMS Birds, Meta or SGI Connects. */
class ProviderChoiceTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hold(): Booking
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->station->state_code, 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();

        return Booking::latest('id')->first();
    }

    private function connectRazorpay(Company $company): void
    {
        config(['payments.sabpaisa.simulate' => false]);
        $company->fill(['payment_gateway' => 'razorpay', 'razorpay_key_id' => 'rzp_test_abc123', 'razorpay_key_secret' => 'rzp_secret', 'razorpay_webhook_secret' => 'rzp_wh'])->save();
    }

    private function fakeRazorpay(Booking $booking, string $linkStatus = 'paid'): void
    {
        $paise = (int) round((float) $booking->total_amount * 100);
        Http::fake([
            '*/v1/payment_links/plink_1' => Http::response(['id' => 'plink_1', 'status' => $linkStatus, 'amount' => $paise, 'amount_paid' => $linkStatus === 'paid' ? $paise : 0,
                'payments' => $linkStatus === 'paid' ? [['payment_id' => 'pay_1', 'amount' => $paise, 'status' => 'captured', 'method' => 'upi']] : []], 200),
            '*/v1/payment_links' => Http::response(['id' => 'plink_1', 'short_url' => 'https://rzp.io/i/abc', 'status' => 'created'], 200),
            '*/v1/payments/pay_1/refund' => Http::response(['id' => 'rfnd_1', 'status' => 'pending'], 200),
            '*/v1/payments/pay_1?*' => Http::response(['id' => 'pay_1', 'method' => 'upi', 'vpa' => 'ravi.kumar@okhdfcbank', 'acquirer_data' => ['rrn' => '123456789012', 'upi_transaction_id' => 'UPI1']], 200),
            '*/v1/refunds/rfnd_1' => Http::response(['id' => 'rfnd_1', 'status' => 'processed', 'acquirer_data' => ['arn' => 'ARN1']], 200),
        ]);
    }

    public function test_company_can_choose_razorpay_and_pay_through_a_payment_link(): void
    {
        $company = $this->station->company;
        $this->assertSame('sabpaisa', $company->payment_gateway);
        $this->connectRazorpay($company);
        $this->assertTrue($company->fresh()->hasPaymentSetup());
        $this->assertInstanceOf(RazorpayGateway::class, app(GatewayFactory::class)->for($company->fresh()));

        $booking = $this->hold();
        $this->fakeRazorpay($booking);
        $res = $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/pay");
        $this->assertSame('https://rzp.io/i/abc', $res->headers->get('Location'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payment_links') && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_abc123:rzp_secret'))
            && $r['amount'] === (int) round((float) $booking->total_amount * 100) && $r['reference_id'] === $booking->payments()->first()->merchant_txn_id
            && $r['customer']['contact'] === '+919876543210');

        $payment = $booking->payments()->first();
        $this->assertSame(['razorpay', 'test', 'plink_1'], [$payment->gateway, $payment->environment, $payment->gateway_payment_id]);

        // Razorpay sends the customer back with a signed query; status still comes from the API.
        $q = ['razorpay_payment_id' => 'pay_1', 'razorpay_payment_link_id' => 'plink_1', 'razorpay_payment_link_reference_id' => $payment->merchant_txn_id, 'razorpay_payment_link_status' => 'paid'];
        $q['razorpay_signature'] = hash_hmac('sha256', "plink_1|{$payment->merchant_txn_id}|paid|pay_1", 'rzp_secret');
        $this->assertTrue((new RazorpayGateway($company->fresh()))->verifyReturn($q));
        $this->assertFalse((new RazorpayGateway($company->fresh()))->verifyReturn(['razorpay_signature' => 'forged'] + $q));

        $this->actingAs($this->customer)->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($q));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $payment->refresh();
        $this->assertSame(['pay_1', 'UPI', '123456789012', 'UPI ra•••@okhdfcbank'], [$payment->gateway_txn_id, $payment->payment_mode, $payment->bank_rrn, $payment->payment_instrument]);

        // Refunds go to the gateway that took the payment.
        $gateway = app(GatewayFactory::class)->forPayment($payment);
        $this->assertInstanceOf(RazorpayGateway::class, $gateway);
        $this->assertSame('INITIATED', $gateway->refund($payment, '10.00', 'test')['status']);
        $this->assertSame('SUCCESS', $gateway->refundStatus('rfnd_1')['status']);

        // Switching the company back to SabPaisa does not change how this payment is handled.
        $company->fill(['payment_gateway' => 'sabpaisa'])->save();
        $this->assertInstanceOf(RazorpayGateway::class, app(GatewayFactory::class)->forPayment($payment->fresh()));
        $this->assertFalse($company->fresh()->hasPaymentSetup());
    }

    public function test_razorpay_webhook_is_verified_and_deduplicated(): void
    {
        $company = $this->station->company;
        $this->connectRazorpay($company);
        $booking = $this->hold();
        $this->fakeRazorpay($booking);
        $this->actingAs($this->customer)->post("/account/bookings/{$booking->code}/pay");
        $payment = $booking->payments()->first();

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_1', 'reference_id' => $payment->merchant_txn_id, 'status' => 'paid']]]]);
        $sig = hash_hmac('sha256', $body, 'rzp_wh');
        $headers = fn (string $s) => ['HTTP_X_RAZORPAY_SIGNATURE' => $s, 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_1', 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', "/webhooks/razorpay/{$company->slug}", [], [], [], $headers('bad'), $body)->assertStatus(401);
        $this->assertSame(BookingStatus::Held, $booking->fresh()->status);
        $this->call('POST', "/webhooks/razorpay/{$company->slug}", [], [], [], $headers($sig), $body)->assertOk();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->call('POST', "/webhooks/razorpay/{$company->slug}", [], [], [], $headers($sig), $body)->assertOk();
        $this->assertSame(1, PaymentEvent::where('idempotency_key', 'rzp:evt_1')->count());
    }

    public function test_sabpaisa_stays_the_default_gateway(): void
    {
        $company = $this->station->company;
        config(['payments.sabpaisa.simulate' => false]);
        $company->fill(['sabpaisa_env' => 'staging', 'sabpaisa_merchant_id' => 'MID', 'sabpaisa_api_key' => 'k', 'sabpaisa_secret_key' => 's'])->save();
        $this->assertInstanceOf(SabPaisaGateway::class, app(GatewayFactory::class)->for($company->fresh()));
        $this->assertArrayNotHasKey('razorpay_key_secret', $company->fresh()->toArray());
    }

    private function useSmsBirds(): void
    {
        config(['services.sms.provider' => 'smsbirds', 'services.smsbirds.url' => 'http://smsbirds.test/sms-panel/api/http/index.php',
            'services.smsbirds.username' => 'transev', 'services.smsbirds.api_key' => 'birdkey', 'services.smsbirds.sender' => 'TRNSEV', 'services.smsbirds.route' => 'TRANS']);
    }

    public function test_sms_can_go_through_sms_birds(): void
    {
        $this->assertSame('msg91', Channels::provider('sms'));
        $this->useSmsBirds();
        $this->assertSame('smsbirds', Channels::provider('sms'));
        $this->assertTrue(Channels::configured('sms'));

        Http::fake(['smsbirds.test/*' => Http::response(['status' => 'success', 'message_id' => 'SB123'], 200)]);
        $this->customer->notifyNow(new TemplateTest('booking.confirmed', 'sms', ['booking_code' => 'TEV1', 'station' => 'Patna', 'slot' => '12:00', 'amount' => '100']));

        Http::assertSent(fn (Request $r) => $r['username'] === 'transev' && $r['apikey'] === 'birdkey' && $r['mobile'] === '9876543210'
            && $r['sender'] === 'TRNSEV' && $r['route'] === 'TRANS' && $r['apirequest'] === 'Text' && str_contains($r['message'], 'TEV1'));
        $row = NotificationDelivery::latest('id')->first();
        $this->assertSame(['sms', 'sent', 'smsbirds', 'SB123'], [$row->channel, $row->status, $row->provider, $row->provider_message_id]);

        // Provider errors are logged as failures.
        $this->assertFalse(SmsBirds::result(200, '{"status":"error","message":"Invalid API key"}', ['status' => 'error', 'message' => 'Invalid API key'])['ok']);
        $this->assertSame('Invalid sender', SmsBirds::result(200, 'Invalid sender', [])['error']);
        $this->assertTrue(SmsBirds::result(200, 'ok', ['status' => 'Success'])['ok']);
    }

    private function useSgiConnects(): void
    {
        config(['services.whatsapp_provider' => 'sgiconnects', 'services.sgiconnects.base_url' => 'https://sgi.test/api',
            'services.sgiconnects.license' => 'LIC-TEST', 'services.sgiconnects.api_key' => 'KEY-TEST']);
    }

    public function test_whatsapp_can_go_through_sgi_connects(): void
    {
        $this->assertSame('meta', Channels::provider('whatsapp'));
        $this->useSgiConnects();
        $this->assertTrue(Channels::configured('whatsapp'));

        Http::fake([
            'sgi.test/api/accountvalidity.php' => Http::response(['status' => 'success', 'data' => ['phone_number_id' => '111']], 200),
            'sgi.test/api/sendtemplate.php' => Http::response(['status' => 'success', 'wam_id' => 'wamid.1'], 200),
            'sgi.test/api/conversationvalidity.php' => Http::response(['status' => 'success', 'exists' => false], 200),
            'sgi.test/api/sendtextmessage.php' => Http::response(['status' => 'success', 'wam_id' => 'wamid.2'], 200),
        ]);
        $this->assertTrue(SgiConnects::checkAccount()['ok']);

        \App\Models\MessageTemplate::query()->updateOrCreate(['key' => 'booking.confirmed', 'channel' => 'whatsapp'],
            ['body' => 'Booking {{booking_code}} at {{station}}', 'dlt_template_id' => 'booking_confirmed', 'is_active' => true]);
        \App\Support\Messages::flush();
        $this->customer->notifyNow(new TemplateTest('booking.confirmed', 'whatsapp', ['booking_code' => 'TEV1', 'station' => 'Patna, Bailey Road']));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'sendtemplate.php') && $r['LicenseNumber'] === 'LIC-TEST' && $r['APIKey'] === 'KEY-TEST'
            && $r['Contact'] === '919876543210' && $r['Template'] === 'booking_confirmed' && $r['Param'] === 'TEV1,Patna  Bailey Road');
        $row = NotificationDelivery::latest('id')->first();
        $this->assertSame(['whatsapp', 'sent', 'sgiconnects', 'wamid.1'], [$row->channel, $row->status, $row->provider, $row->provider_message_id]);

        // Without an approved template, free text only goes when a conversation is open (here it is not).
        $this->assertFalse(SgiConnects::conversationOpen('919876543210'));
    }
}
