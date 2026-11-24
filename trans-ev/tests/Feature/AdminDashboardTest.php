<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Widgets\BookingsChart;
use App\Filament\Widgets\BookingStatusChart;
use App\Filament\Widgets\KpiOverview;
use App\Filament\Widgets\LatestBookings;
use App\Filament\Widgets\NetworkStatusChart;
use App\Filament\Widgets\PeakHoursChart;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\StationRevenueChart;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_dashboard_widgets_render(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);

        foreach ([KpiOverview::class, RevenueChart::class, BookingsChart::class, StationRevenueChart::class, PeakHoursChart::class, NetworkStatusChart::class, BookingStatusChart::class, LatestBookings::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }
        Livewire::test(KpiOverview::class)->assertSee('Revenue this month');
    }

    public function test_station_managers_do_not_see_revenue(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class]);
        $manager = User::factory()->create();
        $manager->assignRole(Role::StationManager->value);
        $this->actingAs($manager);

        $this->assertFalse(RevenueChart::canView());
        $this->assertFalse(StationRevenueChart::canView());
        Livewire::test(KpiOverview::class)->assertDontSee('Revenue this month');
    }
}
