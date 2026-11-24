<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Filament\Resources\NotificationLog\NotificationLogResource;
use App\Models\Booking;
use App\Models\DeviceToken;
use App\Models\MessageTemplate;
use App\Models\NotificationDelivery;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Services\Messaging\Channels;
use App\Services\Messaging\PushChannel;
use App\Services\Messaging\SmsChannel;
use App\Services\Messaging\WhatsAppChannel;
use App\Support\Messages;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\SiteContentSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** D1: channel selection, simulated and live sends (MSG91, WhatsApp Cloud, FCM), delivery log, preferences, admin. */
class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class, SiteContentSeeder::class]);
        Messages::flush();
        $this->customer = User::factory()->create(['phone' => '9876543210', 'name' => 'Asha']);
        $this->customer->assignRole(Role::Customer->value);
    }

    private function booking(): Booking
    {
        $station = Station::where('code', 'GG-PUN-BNR')->first();
        $charger = $station->chargers()->first();

        return Booking::query()->create([
            'company_id' => $station->company_id, 'user_id' => $this->customer->id, 'station_id' => $station->id, 'charger_id' => $charger->id,
            'connector_id' => $charger->connectorPoints()->first()->id, 'tariff_id' => $station->tariffs()->first()->id, 'connector' => $charger->connectorPoints()->first()->type->value,
            'start_at' => now()->addHour(), 'end_at' => now()->addHours(2), 'blocked_until' => now()->addHours(2), 'status' => 'confirmed',
            'battery_from' => 20, 'battery_to' => 80, 'est_kwh' => 10, 'rate_per_kwh' => 15, 'energy_amount' => 150, 'session_fee' => 0, 'taxable_amount' => 150,
            'gst_rate' => 18, 'total_amount' => 177, 'sac_code' => '998714', 'place_of_supply' => '27', 'supplier_state_code' => '27',
            'customer_snapshot' => ['name' => 'Asha', 'phone' => '9876543210'], 'vehicle_snapshot' => ['registration_no' => 'MH12AB1234'],
            'rule_set_id' => \App\Models\RuleSet::activeFor($station)->id,
        ]);
    }

    public function test_channels_follow_templates_preferences_and_devices(): void
    {
        $this->assertSame(['mail', SmsChannel::class], Channels::for($this->customer, 'booking.reminder'));

        $this->customer->forceFill(['notification_prefs' => ['sms' => false, 'whatsapp' => true, 'push' => true]])->save();
        $this->assertSame(['mail', WhatsAppChannel::class], Channels::for($this->customer->fresh(), 'booking.reminder'));

        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'dev-1', 'platform' => 'android']);
        $this->assertContains(PushChannel::class, Channels::for($this->customer->fresh(), 'booking.reminder'));

        // A message with no SMS version never goes by SMS; switching a channel's template off stops it.
        $this->assertNotContains(SmsChannel::class, Channels::for($this->customer->fresh(), 'gst.invoice'));
        MessageTemplate::where(['key' => 'booking.reminder', 'channel' => 'whatsapp'])->update(['is_active' => false]);
        Messages::flush();
        $this->assertNotContains(WhatsAppChannel::class, Channels::for($this->customer->fresh(), 'booking.reminder'));
        // No phone and no app → email only.
        DeviceToken::query()->delete();
        $this->customer->forceFill(['phone' => null, 'notification_prefs' => null])->save();
        $this->assertSame(['mail'], Channels::for($this->customer->fresh(), 'booking.reminder'));
    }

    public function test_simulated_sends_are_logged_without_providers(): void
    {
        Http::fake();
        $this->customer->notify(new BookingNotice('booking.reminder', $this->booking(), ['minutes' => '15', 'no_show_minutes' => '10']));

        $mail = NotificationDelivery::where('channel', 'mail')->sole();
        $this->assertSame('sent', $mail->status);
        $this->assertSame($this->customer->email, $mail->recipient);
        $sms = NotificationDelivery::where('channel', 'sms')->sole();
        $this->assertSame('simulated', $sms->status);
        $this->assertSame('919876543210', $sms->recipient);
        $this->assertStringContainsString('starts at', $sms->preview);
        Http::assertNothingSent();
    }

    public function test_msg91_sms_uses_the_dlt_flow(): void
    {
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.sender_id' => 'TRNSEV']);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'REQ123'])]);
        $b = $this->booking();

        // No DLT template ID yet → logged as failed, nothing sent.
        $this->customer->notify(new BookingNotice('booking.reminder', $b, ['minutes' => '15']));
        $this->assertSame('failed', NotificationDelivery::where('channel', 'sms')->latest('id')->first()->status);
        Http::assertNothingSent();

        MessageTemplate::where(['key' => 'booking.reminder', 'channel' => 'sms'])->update(['dlt_template_id' => '65f0aa11bb22']);
        Messages::flush();
        $this->customer->notify(new BookingNotice('booking.reminder', $b, ['minutes' => '15']));
        Http::assertSent(fn ($r) => $r->url() === 'https://control.msg91.com/api/v5/flow' && $r->header('authkey')[0] === 'test-key'
            && $r['template_id'] === '65f0aa11bb22' && $r['recipients'][0]['mobiles'] === '919876543210' && $r['recipients'][0]['booking_code'] === $b->code);
        $d = NotificationDelivery::where('channel', 'sms')->latest('id')->first();
        $this->assertSame('sent', $d->status);
        $this->assertSame('REQ123', $d->provider_message_id);
    }

    public function test_whatsapp_cloud_template_with_ordered_parameters(): void
    {
        config(['services.whatsapp.token' => 'wa-token', 'services.whatsapp.phone_number_id' => '1122']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        $this->customer->forceFill(['notification_prefs' => ['sms' => false, 'whatsapp' => true, 'push' => false]])->save();
        MessageTemplate::where(['key' => 'booking.reminder', 'channel' => 'whatsapp'])->update(['dlt_template_id' => 'slot_reminder']);
        Messages::flush();
        $b = $this->booking();

        $this->customer->fresh()->notify(new BookingNotice('booking.reminder', $b, ['minutes' => '15']));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/1122/messages') && $r['template']['name'] === 'slot_reminder'
            && $r['template']['components'][0]['parameters'][0]['text'] === $b->code && $r['to'] === '919876543210');
        $this->assertSame('wamid.X', NotificationDelivery::where('channel', 'whatsapp')->sole()->provider_message_id);
    }

    public function test_fcm_push_with_service_account_and_dead_tokens_removed(): void
    {
        // Windows PHP builds need an explicit openssl.cnf to generate keys.
        $cnf = collect([getenv('OPENSSL_CONF'), dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'])->first(fn ($f) => $f && is_file($f));
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + ($cnf ? ['config' => $cnf] : []);
        $key = openssl_pkey_new($opts);
        if (! $key) {
            $this->markTestSkipped('OpenSSL key generation is not available here.');
        }
        openssl_pkey_export($key, $pem, null, $cnf ? ['config' => $cnf] : []);
        config(['services.fcm.project_id' => 'trans-ev', 'services.fcm.service_account' => json_encode(['client_email' => 'push@trans-ev.iam.gserviceaccount.com', 'private_key' => $pem])]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'projects/trans-ev/messages/1'])->push(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);
        $this->customer->forceFill(['notification_prefs' => ['sms' => false, 'whatsapp' => false, 'push' => true]])->save();
        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'good', 'platform' => 'android']);
        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'stale', 'platform' => 'ios']);

        $this->customer->fresh()->notify(new BookingNotice('booking.reminder', $this->booking(), ['minutes' => '15']));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/trans-ev/messages:send') && $r->header('Authorization')[0] === 'Bearer ya29.test'
            && $r['message']['notification']['title'] === 'Slot starts in 15 min');
        $this->assertSame('sent', NotificationDelivery::where('channel', 'push')->sole()->status);
        $this->assertSame(['good'], DeviceToken::pluck('token')->all());
    }

    public function test_preferences_page_and_admin_screens(): void
    {
        $this->actingAs($this->customer)->get('/settings/notifications')->assertOk()->assertInertia(fn ($p) => $p->component('settings/notifications')->where('prefs.whatsapp', false));
        $this->put('/settings/notifications', ['sms' => false, 'whatsapp' => true, 'push' => true])->assertSessionHas('success');
        $this->assertEquals(['sms' => false, 'whatsapp' => true, 'push' => true], $this->customer->fresh()->notification_prefs);

        $this->get(NotificationLogResource::getUrl())->assertForbidden();
        $admin = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $admin->assignRole(Role::SuperAdmin->value);
        NotificationDelivery::record($this->customer, 'booking.reminder', 'sms', 'simulated', ['recipient' => '919876543210', 'preview' => 'Reminder text']);
        $this->app['session']->flush();
        $this->actingAs($admin)->get(NotificationLogResource::getUrl())->assertOk()->assertSee('Reminder text')->assertSee('simulated');

        // Send test from the template editor.
        $t = MessageTemplate::where(['key' => 'booking.reminder', 'channel' => 'mail'])->first();
        Livewire::test(ListMessageTemplates::class)->callTableAction('test', $t);
        $this->assertTrue(NotificationDelivery::where('user_id', $admin->id)->where('channel', 'mail')->exists());
    }
}
