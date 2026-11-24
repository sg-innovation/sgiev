<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\Gstin;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** GSTIN check character, and stations can't go live without a GSTIN for their state. */
class GstinTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_character(): void
    {
        $this->assertTrue(Gstin::valid('10AANCT1689C1ZG'));
        $this->assertTrue(Gstin::valid(' 10aanct1689c1zg '));
        $this->assertFalse(Gstin::valid('10AANCT1689C1ZH'), 'typo in the last character');
        $this->assertFalse(Gstin::valid('10AANCT1698C1ZG'), 'swapped digits');
        $this->assertFalse(Gstin::valid('10AANCT1689C1G'));
        $this->assertSame('AANCT1689C', Gstin::pan('10AANCT1689C1ZG'));

        $v = Validator::make(['g' => '27AANCT1689C1Z9'], ['g' => [new \App\Rules\ValidGstin]]);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('typos', $v->errors()->first('g'));
    }

    public function test_station_without_state_gstin_cannot_be_submitted_or_approved(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $admin = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $admin->assignRole(Role::SuperAdmin->value);
        // A Trans EV (Bihar GSTIN) station in Delhi with no Delhi GSTIN.
        $station = Station::where('state_code', '07')->firstOrFail();
        $station->forceFill(['gstin' => null, 'approval_status' => 'submitted'])->save();

        try {
            app(ApprovalService::class)->approveStation($station->fresh(), $admin);
            $this->fail('approved without a GSTIN');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('GSTIN', collect($e->errors())->flatten()->first());
        }

        $first = '07'.Company::where('is_platform', true)->value('pan').'1Z';
        $station->forceFill(['gstin' => $first.Gstin::checkCharacter($first)])->save();
        app(ApprovalService::class)->approveStation($station->fresh(), $admin);
        $this->assertSame('approved', $station->fresh()->approval_status);
    }

    public function test_customer_gstin_with_a_typo_is_rejected_at_booking(): void
    {
        $v = Validator::make(['gstin' => '27ABCDE1234F1Z5'], (new \App\Http\Requests\StoreBookingRequest)->rules());
        $this->assertTrue($v->errors()->has('gstin') || $v->fails());
        $this->assertContains('This GSTIN’s last character doesn’t match. Please check it for typos.', $v->errors()->get('gstin'));
    }
}
