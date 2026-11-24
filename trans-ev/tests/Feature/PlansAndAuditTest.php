<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\RolesMatrix;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\Stations\StationResource;
use App\Filament\Widgets\PeakHoursChart;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Plans & feature switches (A6), audit log viewer and roles matrix (A8b). */
class PlansAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ggOwner;

    private Company $greengrid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SuperAdmin->value);
        $this->ggOwner = User::where('email', 'owner@greengrid.test')->firstOrFail();
        $this->greengrid = Company::where('slug', 'greengrid-demo')->firstOrFail();
    }

    /** Switch user between HTTP requests (Filament's AuthenticateSession would log out a swapped user). */
    private function as(User $user): static
    {
        $this->app['session']->flush();

        return $this->actingAs($user);
    }

    public function test_plans_screen_is_platform_only(): void
    {
        $this->actingAs($this->admin)->get(PlanResource::getUrl('index'))->assertOk()->assertSee('Premium');
        $this->as($this->ggOwner)->get(PlanResource::getUrl('index'))->assertForbidden();
    }

    public function test_only_one_default_plan(): void
    {
        $premium = Plan::where('slug', 'premium')->first();
        $this->actingAs($this->admin);
        Livewire::test(EditPlan::class, ['record' => $premium->getRouteKey()])
            ->fillForm(['is_default' => true])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['premium'], Plan::where('is_default', true)->pluck('slug')->all());
    }

    public function test_platform_admin_changes_a_company_plan_and_owner_cannot(): void
    {
        $premium = Plan::where('slug', 'premium')->first();
        $this->actingAs($this->admin);
        Livewire::test(EditCompany::class, ['record' => $this->greengrid->getRouteKey()])
            ->fillForm(['plan_id' => $premium->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($premium->id, $this->greengrid->fresh()->plan_id);
        $logged = Activity::where('subject_type', Company::class)->where('subject_id', $this->greengrid->id)->latest('id')->first();
        $this->assertSame($premium->id, $logged?->attribute_changes?->get('attributes')['plan_id'] ?? null, 'plan change is audit-logged');

        $free = Plan::where('slug', 'free')->first();
        $this->actingAs($this->ggOwner);
        Livewire::test(EditCompany::class, ['record' => $this->greengrid->getRouteKey()])
            ->fillForm(['plan_id' => $free->id])->call('save');
        $this->assertSame($premium->id, $this->greengrid->fresh()->plan_id, 'disabled field must not be saved');
    }

    public function test_station_limit_from_plan(): void
    {
        $this->greengrid->plan->update(['max_stations' => 2]); // GreenGrid already has 2
        $this->actingAs($this->ggOwner);

        $this->assertFalse($this->ggOwner->can('create', Station::class));
        $this->get(StationResource::getUrl('create'))->assertForbidden();

        $this->expectException(ValidationException::class);
        $template = Station::where('code', 'GG-PUN-BNR')->first();
        $copy = $template->replicate(['uuid', 'slug', 'code', 'approval_status']);
        $copy->fill(['code' => 'GG-NEW', 'slug' => 'gg-new'])->save();
    }

    public function test_platform_company_has_no_station_limit(): void
    {
        $this->assertTrue(Company::where('is_platform', true)->first()->canAddStation());
    }

    public function test_advanced_analytics_follows_the_plan(): void
    {
        $this->actingAs($this->ggOwner);
        $this->assertFalse(PeakHoursChart::canView(), 'Free plan has no advanced analytics');

        $this->greengrid->forceFill(['plan_id' => Plan::where('slug', 'premium')->value('id')])->save();
        $this->actingAs($this->ggOwner->fresh());
        $this->assertTrue(PeakHoursChart::canView());

        $this->actingAs($this->admin);
        $this->assertTrue(PeakHoursChart::canView());
    }

    public function test_online_booking_can_be_switched_off_by_plan(): void
    {
        $free = $this->greengrid->plan;
        $free->update(['features' => array_values(array_diff($free->features, ['online_booking']))]);
        $this->assertFalse($this->greengrid->fresh()->hasFeature('online_booking'));
        $this->assertTrue(Company::where('is_platform', true)->first()->hasFeature('online_booking'));
    }

    public function test_audit_log_is_scoped_to_the_company(): void
    {
        $platformStation = Station::where('code', 'DEL-CP')->first();
        $ggStation = Station::where('code', 'GG-PUN-BNR')->first();
        activity()->performedOn($platformStation)->causedBy($this->admin)->log('platform test entry');
        activity()->performedOn($ggStation)->causedBy($this->admin)->log('greengrid test entry');

        $platformEntry = Activity::where('description', 'platform test entry')->first();
        $ggEntry = Activity::where('description', 'greengrid test entry')->first();

        $this->actingAs($this->admin)->get(ActivityLogResource::getUrl('index'))->assertOk();
        Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$platformEntry, $ggEntry]);

        $this->as($this->ggOwner);
        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$ggEntry])
            ->assertCanNotSeeTableRecords([$platformEntry]);
        $this->get(ActivityLogResource::getUrl('view', ['record' => $platformEntry]))->assertNotFound();
        $this->get(ActivityLogResource::getUrl('view', ['record' => $ggEntry]))->assertOk();
    }

    public function test_audit_log_is_append_only(): void
    {
        activity()->log('immutable entry');
        $id = Activity::where('description', 'immutable entry')->value('id');

        try {
            DB::table('activity_log')->where('id', $id)->update(['description' => 'tampered']);
            $this->fail('Update should be blocked');
        } catch (QueryException) {
        }
        try {
            DB::table('activity_log')->where('id', $id)->delete();
            $this->fail('Delete should be blocked');
        } catch (QueryException) {
        }
        $this->assertSame('immutable entry', Activity::find($id)->description);
    }

    public function test_customers_cannot_see_audit_or_roles(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $this->actingAs($customer)->get(ActivityLogResource::getUrl('index'))->assertForbidden();
        $this->as($customer)->get(RolesMatrix::getUrl())->assertForbidden();
    }

    public function test_roles_matrix_renders(): void
    {
        $this->actingAs($this->admin)->get(RolesMatrix::getUrl())->assertOk()->assertSee('Station manager')->assertSee('Issue refunds');
        $this->as($this->ggOwner)->get(RolesMatrix::getUrl())->assertOk();
    }
}
