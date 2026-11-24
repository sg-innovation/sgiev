<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Station;
use App\Models\User;
use App\Notifications\PartnerUpdate;
use App\Services\ApprovalService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Partner self-registration, KYC and the approval workflow (SGI EV SRS §5.1). */
class PartnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'legal_name' => 'Volt Ways Private Limited', 'trade_name' => 'VoltWays',
            'gstin' => '27ABCDE1234F1Z0', 'pan' => 'abcde1234f',
            'address_line1' => '12 MG Road', 'city' => 'Pune', 'state_code' => '27', 'pincode' => '411001',
            'name' => 'Asha Rao', 'email' => 'asha@voltways.test', 'phone' => '98765 43210',
            'password' => 'Very-Strong-Pass-2026!', 'password_confirmation' => 'Very-Strong-Pass-2026!',
            'account_name' => 'Volt Ways Pvt Ltd', 'account_number' => '123456789012', 'ifsc' => 'hdfc0001234', 'bank_name' => 'HDFC Bank',
            'documents' => [
                'gst_certificate' => UploadedFile::fake()->create('gst.pdf', 120, 'application/pdf'),
                'pan_card' => UploadedFile::fake()->image('pan.jpg'),
                'address_proof' => UploadedFile::fake()->create('address.pdf', 80, 'application/pdf'),
                'cancelled_cheque' => UploadedFile::fake()->image('cheque.png'),
            ],
            'terms' => '1',
        ], $over);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::SuperAdmin->value);

        return $u;
    }

    public function test_partner_page_renders(): void
    {
        $this->get('/partner')->assertOk()->assertInertia(fn ($p) => $p->component('partner/register')->has('states')->has('docTypes'));
    }

    public function test_registration_creates_pending_company_owner_and_private_documents(): void
    {
        $this->post('/partner', $this->payload())->assertRedirect(route('partner.thanks'));

        $company = Company::where('gstin', '27ABCDE1234F1Z0')->firstOrFail();
        $this->assertSame('pending', $company->status);
        $this->assertSame('Maharashtra', $company->state);
        $this->assertSame('ABCDE1234F', $company->pan);
        $this->assertSame('free', $company->plan?->slug);
        $this->assertNotNull($company->terms_accepted_at);

        // Bank details are encrypted at rest.
        $raw = DB::table('companies')->where('id', $company->id)->value('bank_details');
        $this->assertStringNotContainsString('123456789012', $raw);
        $this->assertSame('123456789012', $company->bank_details['account_no']);
        $this->assertSame('HDFC0001234', $company->bank_details['ifsc']);

        $owner = User::where('email', 'asha@voltways.test')->firstOrFail();
        $this->assertSame($company->id, $owner->company_id);
        $this->assertTrue($owner->hasRole(Role::CompanyOwner->value));
        $this->assertSame('9876543210', $owner->phone);

        $docs = $company->documents;
        $this->assertCount(4, $docs);
        foreach ($docs as $doc) {
            Storage::disk('local')->assertExists($doc->path);
            $this->assertStringStartsWith("kyc/{$company->id}/", $doc->path);
            $this->assertSame(64, strlen($doc->sha256));
            $this->assertSame('pending', $doc->status);
        }
        // A pending company can log in to the panel to set up stations, but nothing is public.
        $this->assertTrue($owner->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_honeypot_silently_drops_bots(): void
    {
        $this->post('/partner', $this->payload(['website_url' => 'http://spam.example']))->assertRedirect(route('partner.thanks'));
        $this->assertDatabaseMissing('companies', ['gstin' => '27ABCDE1234F1Z5']);
    }

    public function test_validation_rejects_bad_identifiers_and_missing_documents(): void
    {
        $p = $this->payload(['ifsc' => 'BAD', 'phone' => '12345', 'terms' => null]);
        unset($p['documents']['cancelled_cheque']);
        $this->post('/partner', $p)->assertSessionHasErrors(['ifsc', 'phone', 'terms', 'documents.cancelled_cheque']);
        $this->assertSame(0, Company::where('trade_name', 'VoltWays')->count());
    }

    public function test_pan_must_match_gstin(): void
    {
        $this->post('/partner', $this->payload(['pan' => 'ZZZZZ9999Z']))->assertSessionHasErrors('pan');
        $this->assertDatabaseMissing('companies', ['trade_name' => 'VoltWays']);
    }

    public function test_dangerous_file_types_are_refused(): void
    {
        $p = $this->payload();
        $p['documents']['pan_card'] = UploadedFile::fake()->create('pan.php', 10, 'application/x-php');
        $this->post('/partner', $p)->assertSessionHasErrors('documents.pan_card');
    }

    public function test_kyc_download_is_restricted(): void
    {
        $this->post('/partner', $this->payload());
        $doc = CompanyDocument::firstOrFail();

        $this->get(route('kyc.show', $doc))->assertRedirect('/login');

        $other = User::where('email', 'owner@greengrid.test')->firstOrFail();
        $this->actingAs($other)->get(route('kyc.show', $doc))->assertForbidden();

        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $this->actingAs($customer)->get(route('kyc.show', $doc))->assertForbidden();

        $this->actingAs(User::where('email', 'asha@voltways.test')->first())->get(route('kyc.show', $doc))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->admin())->get(route('kyc.show', $doc))->assertOk();
    }

    public function test_approval_flow_company_then_station(): void
    {
        $this->post('/partner', $this->payload());
        $company = Company::where('trade_name', 'VoltWays')->firstOrFail();
        $owner = User::where('email', 'asha@voltways.test')->firstOrFail();
        $admin = $this->admin();
        $svc = app(ApprovalService::class);

        // Owner drafts a station: never auto-approved, never public.
        $template = Station::where('code', 'GG-PUN-BNR')->with(['chargers', 'tariffs'])->firstOrFail();
        $this->actingAs($owner);
        $station = $template->replicate(['uuid', 'approval_status', 'approved_at', 'approved_by', 'slug', 'code', 'chargers_count']);
        $station->fill(['company_id' => $company->id, 'name' => 'VoltWays Baner', 'code' => 'VW-PUN-1', 'slug' => 'voltways-baner']);
        $station->save();
        $station->refresh();
        $this->assertSame('draft', $station->approval_status);
        $this->assertFalse(Station::published()->whereKey($station->id)->exists());

        // Cannot submit without chargers and a tariff.
        try {
            $svc->submitStation($station, $owner);
            $this->fail('Expected validation error');
        } catch (ValidationException) {
        }
        foreach ($template->chargers as $c) {
            $station->chargers()->create($c->only(['code', 'name', 'type', 'power_kw', 'connectors', 'status']));
        }
        foreach ($template->tariffs as $t) {
            $station->tariffs()->create(collect($t->getAttributes())->except(['id', 'station_id', 'created_at', 'updated_at'])->all());
        }
        $svc->submitStation($station, $owner);
        $this->assertSame('submitted', $station->fresh()->approval_status);

        // Station can't be approved while the company is pending.
        $this->expectsValidation(fn () => $svc->approveStation($station->fresh(), $admin));

        $svc->requestCompanyChanges($company, $admin, 'Please upload a clearer PAN card.');
        $this->assertSame('changes_requested', $company->fresh()->status);
        Notification::assertSentTo($owner, PartnerUpdate::class);
        $svc->resubmitCompany($company->fresh(), $owner);
        $this->assertSame('pending', $company->fresh()->status);

        $svc->approveCompany($company->fresh(), $admin);
        $company->refresh();
        $this->assertSame('active', $company->status);
        $this->assertSame(0, $company->documents()->where('status', 'pending')->count());

        $this->assertFalse(Station::published()->whereKey($station->id)->exists());
        $svc->approveStation($station->fresh(), $admin);
        $this->assertTrue(Station::published()->whereKey($station->id)->exists());
        $this->get('/stations/voltways-baner')->assertOk();
    }

    public function test_company_cannot_be_approved_without_required_documents(): void
    {
        $this->post('/partner', $this->payload());
        $company = Company::where('trade_name', 'VoltWays')->firstOrFail();
        $company->documents()->where('type', 'cancelled_cheque')->update(['status' => 'rejected']);

        $this->expectsValidation(fn () => app(ApprovalService::class)->approveCompany($company, $this->admin()));
        $this->assertSame('pending', $company->fresh()->status);
    }

    public function test_rejected_company_staff_cannot_use_admin(): void
    {
        $this->post('/partner', $this->payload());
        $company = Company::where('trade_name', 'VoltWays')->firstOrFail();
        app(ApprovalService::class)->rejectCompany($company, $this->admin(), 'Documents could not be verified.');

        $owner = User::where('email', 'asha@voltways.test')->firstOrFail();
        $this->assertFalse($owner->fresh()->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_editing_key_fields_of_approved_partner_station_flags_review(): void
    {
        $owner = User::where('email', 'owner@greengrid.test')->firstOrFail();
        $station = Station::where('code', 'GG-PUN-BNR')->firstOrFail();
        $this->actingAs($owner);
        $station->update(['address_line1' => 'New address, Baner Road']);
        $this->assertTrue((bool) $station->fresh()->needs_review);
        $this->assertSame('approved', $station->fresh()->approval_status);
    }

    private function expectsValidation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a ValidationException');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
