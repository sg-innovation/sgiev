<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\BusinessRulesPage;
use App\Filament\Resources\Lookups\LookupResource;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Booking;
use App\Models\Lookup;
use App\Models\Page;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Support\BusinessRules;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Business rules, lists and website pages come from the database and are editable in admin. */
class DatabaseConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SuperAdmin->value);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rules_are_seeded_into_the_database(): void
    {
        $this->assertDatabaseHas('system_settings', ['key' => 'rule.booking.hold_minutes', 'value' => '10']);
        $this->assertSame(count(BusinessRules::DEFINITIONS), \App\Models\SystemSetting::where('key', 'like', 'rule.%')->count());
    }

    public function test_changing_the_hold_time_changes_bookings(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(BusinessRulesPage::class)
            ->set('data.booking__hold_minutes', 20)
            ->call('save');
        $this->assertSame(20, BusinessRules::int('booking.hold_minutes'));

        $customer = User::factory()->create(['phone' => '9876543210']);
        $customer->assignRole(Role::Customer->value);
        $station = Station::where('code', 'PAT-BLY')->first();
        $charger = $station->chargers()->where('type', 'DC')->first();
        $this->actingAs($customer)->post("/book/{$station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'A Driver', 'email' => 'a@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '10', 'rule_set_id' => RuleSet::activeFor($station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Booking::latest('id')->first()->hold_expires_at->equalTo(now()->addMinutes(20)));
    }

    public function test_pages_fill_placeholders_from_the_company_and_keep_history(): void
    {
        $page = Page::where('slug', 'terms')->first();
        $this->get('/terms')->assertInertia(fn ($p) => $p->where('content.version', 1)
            ->where('content.sections', fn ($sections) => collect($sections)->contains(fn ($s) => str_contains($s['html'], 'TRANZACTA PRIVATE LIMITED') || str_contains($s['html'], '10AANCT1689C1ZG'))));
        $this->assertStringNotContainsString('{{', json_encode($this->get('/terms')->viewData('page')));

        $this->actingAs($this->admin);
        $page->changeNote = 'Clarified eligibility';
        $page->update(['body' => $page->body."\n<h2>99. Test section</h2><p>New text.</p>"]);
        $page->refresh();
        $this->assertSame(2, $page->version);
        $this->assertSame(2, $page->versions()->count());
        $this->assertSame('Clarified eligibility', $page->versions()->first()->change_note);
    }

    public function test_unsafe_html_is_removed_from_pages(): void
    {
        Page::create(['slug' => 'test-page', 'title' => 'Test', 'body' => '<h2>Hi</h2><p onclick="x()">Safe</p><script>alert(1)</script>']);
        $this->get('/p/test-page')->assertOk()->assertInertia(fn ($p) => $p->where('content.sections.0.html', '<p>Safe</p>'));
        $this->get('/p/missing-page')->assertNotFound();
    }

    public function test_lists_drive_forms(): void
    {
        Lookup::create(['type' => 'kyc_document', 'value' => 'trade_licence', 'label' => 'Trade licence', 'meta' => ['required' => true, 'on_registration' => true], 'sort' => 99]);
        Lookup::flush();
        $this->get('/partner')->assertInertia(fn ($p) => $p->where('docTypes', fn ($docs) => collect($docs)->contains(fn ($d) => $d['value'] === 'trade_licence' && $d['required'])));
        $this->assertContains('trade_licence', \App\Models\CompanyDocument::required());

        Lookup::where('type', 'contact_topic')->where('value', 'fleet')->update(['is_active' => false]);
        Lookup::flush();
        $this->get('/contact')->assertInertia(fn ($p) => $p->where('topics', fn ($t) => ! collect($t)->contains('value', 'fleet')));
    }

    public function test_only_permitted_trans_ev_staff_manage_settings(): void
    {
        $this->actingAs($this->admin)->get(BusinessRulesPage::getUrl())->assertOk();
        $this->actingAs($this->admin)->get(LookupResource::getUrl('index'))->assertOk();
        $this->actingAs($this->admin)->get(PageResource::getUrl('index'))->assertOk();

        $owner = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $owner->assignRole(Role::CompanyOwner->value);
        $this->app['session']->flush();
        $this->actingAs($owner)->get(BusinessRulesPage::getUrl())->assertForbidden();
        $this->actingAs($owner)->get(PageResource::getUrl('index'))->assertForbidden();
    }
}
