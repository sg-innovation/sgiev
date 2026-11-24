<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin → Customers: platform sees everyone, partners only their own customers. */
class CustomerAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;   // booked at a Trans EV station

    private User $bob;     // booked at a GreenGrid station

    private User $carol;   // registered, never booked

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);

        [$this->alice, $this->bob, $this->carol] = collect(['alice', 'bob', 'carol'])->map(function ($n) {
            $u = User::factory()->create(['name' => ucfirst($n), 'email' => "{$n}@example.test"]);
            $u->assignRole(Role::Customer->value);

            return $u;
        })->all();

        Booking::factory()->forStation(Station::where('code', 'DEL-CP')->first(), $this->alice)->create(['status' => BookingStatus::Confirmed, 'total_amount' => '236.00']);
        Booking::factory()->forStation(Station::where('code', 'GG-PUN-BNR')->first(), $this->bob)->create(['status' => BookingStatus::Confirmed, 'total_amount' => '118.00']);
    }

    private function staff(string $companySlug, Role $role): User
    {
        $u = User::factory()->create(['company_id' => Company::where('slug', $companySlug)->value('id')]);
        $u->assignRole($role->value);

        return $u;
    }

    public function test_platform_admin_sees_all_customers_but_no_staff(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);

        $this->get(CustomerResource::getUrl('index'))->assertOk();
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$this->alice, $this->bob, $this->carol])
            ->assertCanNotSeeTableRecords([$admin]);
    }

    public function test_partner_sees_only_its_own_customers_and_its_own_spend(): void
    {
        $owner = User::where('email', 'owner@greengrid.test')->firstOrFail();
        $this->actingAs($owner);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$this->bob])
            ->assertCanNotSeeTableRecords([$this->alice, $this->carol]);

        $row = CustomerResource::getEloquentQuery()->whereKey($this->bob->id)->first();
        $this->assertSame(1, (int) $row->bookings_count);
        $this->assertSame('118.00', number_format((float) $row->spent, 2, '.', ''));

        $this->get(CustomerResource::getUrl('view', ['record' => $this->bob]))->assertOk();
        $this->get(CustomerResource::getUrl('view', ['record' => $this->alice]))->assertNotFound();
    }

    public function test_finance_and_auditor_can_view_but_partners_cannot_block(): void
    {
        $finance = $this->staff('trans-ev', Role::Finance);
        $this->actingAs($finance)->get(CustomerResource::getUrl('index'))->assertOk();

        $auditor = $this->staff('trans-ev', Role::Auditor);
        $this->actingAs($auditor)->get(CustomerResource::getUrl('index'))->assertOk();

        $owner = User::where('email', 'owner@greengrid.test')->firstOrFail();
        $this->actingAs($owner);
        Livewire::test(ListCustomers::class)->assertTableActionHidden('block', $this->bob);
    }

    public function test_platform_admin_can_block_a_customer(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);

        Livewire::test(ListCustomers::class)
            ->callTableAction('block', $this->carol, data: ['reason' => 'Repeated no-shows'])
            ->assertHasNoTableActionErrors();
        $this->assertNotNull($this->carol->fresh()->blocked_at);
    }

    public function test_csv_cells_are_protected_from_formula_injection(): void
    {
        $this->assertSame("'=HYPERLINK(1)", ListCustomers::safeCell('=HYPERLINK(1)'));
        $this->assertSame('Alice', ListCustomers::safeCell('Alice'));
    }
}
