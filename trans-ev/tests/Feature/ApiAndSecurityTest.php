<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ApiDeveloper;
use App\Models\Booking;
use App\Models\OtpCode;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Database\Seeders\TicketSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Phase E: REST API v1, mobile OTP login (API + website), authenticator-app two-step login and the developer portal. */
class ApiAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class, LookupSeeder::class, TicketSeeder::class]);
        $this->station = Station::where('code', 'PAT-BLY')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function otp(string $phone): string
    {
        $r = $this->postJson('/api/v1/auth/otp', ['phone' => $phone])->assertOk();

        return (string) $r->json('debug_code');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_otp_sign_up_and_login_issue_tokens_with_limits(): void
    {
        $code = $this->otp('+91 98765 43210');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertFalse(Hash::check($code, '') || OtpCode::sole()->code_hash === $code, 'stored hashed');
        // A new number needs name and email.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code, 'device_name' => 'Pixel'])->assertStatus(422)->assertJsonValidationErrors('name');
        $r = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code, 'device_name' => 'Pixel', 'name' => 'Asha Kumar', 'email' => 'asha@example.com'])
            ->assertCreated()->assertJsonPath('is_new', true)->assertJsonPath('user.phone', '9876543210');
        $token = $r->json('token');
        $user = User::where('email', 'asha@example.com')->sole();
        $this->assertTrue($user->hasRole(Role::Customer->value));
        $this->assertNotNull($user->phone_verified_at);
        // A used code does not work again; resend has a cooldown.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code, 'device_name' => 'Pixel'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/otp', ['phone' => '9876543210'])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk()->assertJsonPath('data.name', 'Asha Kumar');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();

        // Wrong codes run out after the attempt limit.
        $this->travel(1)->minutes();
        $good = $this->otp('9876543210');
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $good === '000000' ? '111111' : '000000', 'device_name' => 'Pixel'])->assertStatus(422);
        }
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $good, 'device_name' => 'Pixel'])->assertStatus(422);

        // Someone else's email is never taken over by a new number.
        $this->travel(1)->minutes();
        $c2 = $this->otp('9123456789');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9123456789', 'code' => $c2, 'device_name' => 'X', 'name' => 'Intruder', 'email' => 'asha@example.com'])->assertStatus(422)->assertJsonValidationErrors('email');

        // Logout revokes the token.
        $this->postJson('/api/v1/auth/logout', [], $this->bearer($token))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_two_factor_applies_to_the_api_and_the_website(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com', 'password' => Hash::make('secret-pass-1'), 'phone' => '9811122233']);
        $user->assignRole(Role::Customer->value);
        $tfa = app(TwoFactorService::class);
        $secret = $tfa->newSecret();
        $recovery = $tfa->enable($user, $secret);
        $this->assertCount(8, $recovery);
        $this->assertNotSame($secret, \DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'secret encrypted at rest');

        // API: password → challenge → code → token. A code can't be replayed.
        $r = $this->postJson('/api/v1/auth/login', ['email' => 'ravi@example.com', 'password' => 'secret-pass-1', 'device_name' => 'iPhone'])->assertOk()->assertJsonPath('two_factor_required', true);
        $challenge = $r->json('challenge_token');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $code])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $code])->assertStatus(422);
        // A recovery code works once.
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $recovery[0]])->assertOk();
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $recovery[0]])->assertStatus(422);

        // Website: password → two-step page → signed in.
        $this->post('/login', ['email' => 'ravi@example.com', 'password' => 'secret-pass-1'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('two-factor.challenge'))->assertOk();
        $this->post(route('two-factor.verify'), ['code' => $recovery[1]])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // Website mobile login also asks for the second step.
        auth()->logout();
        $this->travel(2)->minutes();
        $c = $this->postJson(route('login.otp.send'), ['phone' => '9811122233'])->assertOk()->json('debug_code');
        $this->post(route('login.otp.verify'), ['phone' => '9811122233', 'code' => $c])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_two_factor_settings_page_turns_it_on_and_off(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw-123456')]);
        $user->assignRole(Role::Customer->value);
        $this->actingAs($user)->get('/settings/two-factor')->assertOk();
        $setup = $this->postJson('/settings/two-factor/setup')->assertOk()->json();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup['qr']);
        $this->post('/settings/two-factor/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/settings/two-factor/confirm', ['code' => (new Google2FA)->getCurrentOtp($setup['secret'])])->assertSessionHas('two_factor.recovery_codes');
        $this->assertTrue(TwoFactorService::enabled($user->fresh()));
        $this->delete('/settings/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->delete('/settings/two-factor', ['password' => 'pw-123456'])->assertSessionHasNoErrors();
        $this->assertFalse(TwoFactorService::enabled($user->fresh()));

        // Staff must keep it on (Business rule).
        $staff = User::factory()->create(['company_id' => $this->station->company_id, 'password' => Hash::make('pw-123456')]);
        $staff->assignRole(Role::OperationsAdmin->value);
        app(TwoFactorService::class)->enable($staff, app(TwoFactorService::class)->newSecret());
        $this->actingAs($staff)->delete('/settings/two-factor', ['password' => 'pw-123456'])->assertSessionHasErrors('password');
        $this->assertTrue(TwoFactorService::enabled($staff->fresh()));
    }

    public function test_full_booking_flow_over_the_api(): void
    {
        $code = $this->otp('9876501234');
        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876501234', 'code' => $code, 'device_name' => 'Pixel', 'name' => 'Api Driver', 'email' => 'api@example.com'])->json('token');
        $h = $this->bearer($token);

        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('app.api_version', 'v1');
        $this->getJson('/api/v1/stations?q=Patna')->assertOk()->assertJsonFragment(['slug' => $this->station->slug]);
        $detail = $this->getJson("/api/v1/stations/{$this->station->slug}")->assertOk()->json('data');
        $charger = $this->station->chargers()->where('type', 'DC')->first();

        $v = $this->postJson('/api/v1/vehicles', ['make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'br 01 ab 1234', 'battery_kwh' => 40, 'connector' => 'ccs2'], $h)->assertCreated();
        $this->assertSame(['BR01AB1234', true], [$v->json('data.registration_no'), $v->json('data.is_default')]);

        $b = $this->postJson("/api/v1/stations/{$this->station->slug}/bookings", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2', 'battery_from' => 20, 'battery_to' => 80,
            'name' => 'Api Driver', 'email' => 'api@example.com', 'phone' => '9876501234', 'state_code' => $this->station->state_code,
            'rule_set_id' => $detail['rules']['id'] ?? RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ], $h)->assertCreated();
        $bookingCode = $b->json('data.code');
        $this->assertSame('held', $b->json('data.status'));

        $pay = $this->postJson("/api/v1/bookings/{$bookingCode}/pay", [], $h)->assertOk()->json('url');
        $this->assertStringContainsString('/dev/sabpaisa/checkout/', $pay);
        $payment = Booking::where('code', $bookingCode)->sole()->payments()->first();
        SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->postJson("/api/v1/bookings/{$bookingCode}/payment-status", [], $h)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->getJson('/api/v1/bookings?tab=upcoming', $h)->assertOk()->assertJsonPath('data.0.code', $bookingCode);
        $this->getJson("/api/v1/bookings/{$bookingCode}/documents", $h)->assertOk()->assertJsonStructure(['data' => ['invoice', 'credit_notes']]);
        $docs = $this->getJson("/api/v1/bookings/{$bookingCode}/documents", $h)->json('data.invoice.pdf_url');
        if ($docs) {
            $this->get($docs)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->getJson("/api/v1/bookings/{$bookingCode}/live", $h)->assertOk();

        // Another customer cannot see it.
        $other = User::factory()->create();
        $other->assignRole(Role::Customer->value);
        $otherToken = $other->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/bookings/{$bookingCode}", $this->bearer($otherToken))->assertForbidden();

        // Support ticket over the API.
        $this->app['auth']->forgetGuards();
        $cat = $this->getJson('/api/v1/support/options', $h)->assertOk()->json('data.categories.0.id');
        $t = $this->postJson('/api/v1/support/tickets', ['category_id' => $cat, 'subject' => 'Question about my booking', 'body' => 'When can I check in for this booking?', 'booking_code' => $bookingCode], $h)->assertCreated();
        $this->postJson('/api/v1/support/tickets/'.$t->json('data.uuid').'/reply', ['body' => 'Thanks!'], $h)->assertCreated();
        $this->postJson('/api/v1/bookings/'.$bookingCode.'/cancel', [], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_developer_portal_needs_the_pin_and_gives_docs_console_and_sandbox_token(): void
    {
        $this->get('/developers')->assertOk()->assertInertia(fn ($p) => $p->component('developers/unlock'));
        $this->get('/developers/openapi.json')->assertForbidden();
        $dev = ApiDeveloper::create(['name' => 'Flutter Dev', 'pin_hash' => Hash::make('482913'), 'is_active' => true]);
        $this->post('/developers/unlock', ['pin' => '111111'])->assertSessionHasErrors('pin');
        $this->post('/developers/unlock', ['pin' => '482913'])->assertRedirect(route('developers.show'));
        $this->get('/developers')->assertOk()->assertInertia(fn ($p) => $p->component('developers/portal')->where('developer.name', 'Flutter Dev')->has('groups', 7));
        $spec = $this->get('/developers/openapi.json')->assertOk()->json();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('/stations/{slug}/bookings', $spec['paths']);

        $sandbox = $this->postJson('/developers/sandbox-token')->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $this->bearer($sandbox['token']))->assertOk()->assertJsonPath('data.id', $sandbox['user']['id']);
        $this->assertSame($sandbox['user']['id'], $dev->fresh()->test_user_id);

        // Expired or switched-off access no longer opens it.
        $dev->forceFill(['is_active' => false])->save();
        $this->get('/developers')->assertInertia(fn ($p) => $p->component('developers/unlock'));

        // Admin screen.
        $admin = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $admin->assignRole(Role::SuperAdmin->value);
        $resp = $this->actingAs($admin, 'web')->get(\App\Filament\Resources\ApiDevelopers\ApiDeveloperResource::getUrl());
        $resp->assertOk();
        \Livewire\Livewire::test(\App\Filament\Resources\ApiDevelopers\Pages\ManageApiDevelopers::class)
            ->callAction('create', ['name' => 'Agency Dev', 'pin' => '555666', 'is_active' => true])->assertHasNoActionErrors();
        $this->assertTrue(Hash::check('555666', ApiDeveloper::where('name', 'Agency Dev')->value('pin_hash')));
    }

    public function test_staff_must_set_up_two_step_login_in_the_admin_panel(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('admin');
        config(['auth.staff_2fa_enforced' => true]);
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired(), 'required for staff by default');
        $this->assertArrayHasKey('app', $panel->getMultiFactorAuthenticationProviders());
        \App\Support\BusinessRules::save(['security.staff_2fa_required' => '0'], null);
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired(), 'Business rules can switch it off');

        // The admin panel and the website share one secret: a staff member set up on the website passes the admin check.
        $staff = User::factory()->create(['company_id' => $this->station->company_id]);
        $staff->assignRole(Role::OperationsAdmin->value);
        $secret = app(TwoFactorService::class)->newSecret();
        app(TwoFactorService::class)->enable($staff, $secret);
        $this->assertTrue($panel->getMultiFactorAuthenticationProviders()['app']->isEnabled($staff->fresh()));
        $this->assertSame($secret, $staff->fresh()->getAppAuthenticationSecret());
    }
}
