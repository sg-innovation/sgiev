<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\RolesMatrix;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/** Admin → Roles & permissions: custom roles, permission edits, safety rails. */
class RolesManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SuperAdmin->value);
    }

    public function test_super_admin_creates_a_custom_role_that_staff_can_use(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(RolesMatrix::class)
            ->callAction('createRole', ['label' => 'Site supervisor', 'description' => 'Night shift', 'perm_stations' => ['stations.view', 'stations.status'], 'perm_bookings' => ['bookings.view']])
            ->assertHasNoActionErrors();

        $role = RoleModel::findByName('site_supervisor', 'web');
        $this->assertFalse((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(['stations.view', 'stations.status', 'bookings.view'], $role->permissions->pluck('name')->all());
        $this->assertArrayHasKey('site_supervisor', UserResource::roleOptions());
        $this->assertTrue(Activity::where('description', 'role created')->exists());

        // Staff with the custom role can open the admin panel.
        $staff = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $staff->assignRole('site_supervisor');
        $this->assertTrue($staff->fresh()->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_editing_a_built_in_role_changes_permissions_and_is_logged(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(RolesMatrix::class)
            ->callAction('editRole', ['perm_tariffs' => [], 'perm_coupons' => ['coupons.view', 'coupons.manage']], arguments: ['role' => Role::Auditor->value])
            ->assertHasNoActionErrors();

        $perms = RoleModel::findByName('auditor', 'web')->permissions->pluck('name')->all();
        $this->assertNotContains('tariffs.view', $perms);
        $this->assertContains('coupons.manage', $perms);
        $this->assertContains('audit.view', $perms, 'untouched areas keep their permissions');
        $log = Activity::where('description', 'role permissions changed')->latest('id')->first();
        $this->assertContains('tariffs.view', $log->properties['revoked']);
    }

    public function test_super_admin_role_cannot_be_edited_and_built_in_roles_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(RolesMatrix::class)->callAction('editRole', ['perm_stations' => []], arguments: ['role' => Role::SuperAdmin->value]);
        $this->assertTrue(RoleModel::findByName('super_admin', 'web')->hasPermissionTo('stations.manage'));

        Livewire::test(RolesMatrix::class)->callAction('deleteRole', arguments: ['role' => Role::Finance->value]);
        $this->assertNotNull(RoleModel::where('name', 'finance')->first());
    }

    public function test_custom_role_in_use_cannot_be_deleted(): void
    {
        $role = RoleModel::create(['name' => 'temp_role', 'guard_name' => 'web']);
        $role->forceFill(['label' => 'Temp', 'is_system' => false])->save();
        $u = User::factory()->create();
        $u->assignRole('temp_role');

        $this->actingAs($this->admin);
        Livewire::test(RolesMatrix::class)->callAction('deleteRole', arguments: ['role' => 'temp_role']);
        $this->assertNotNull(RoleModel::where('name', 'temp_role')->first());

        $u->removeRole('temp_role');
        Livewire::test(RolesMatrix::class)->callAction('deleteRole', arguments: ['role' => 'temp_role']);
        $this->assertNull(RoleModel::where('name', 'temp_role')->first());
    }

    public function test_company_owner_sees_roles_but_cannot_change_them(): void
    {
        $owner = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $owner->assignRole(Role::CompanyOwner->value);
        $this->actingAs($owner);

        $this->get(RolesMatrix::getUrl())->assertOk()->assertDontSee('New role');
        Livewire::test(RolesMatrix::class)->assertActionHidden('createRole');
    }

    public function test_duplicate_role_names_are_rejected(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(RolesMatrix::class)->callAction('createRole', ['label' => 'Finance']);
        $this->assertSame(1, RoleModel::where('name', 'finance')->count());
    }
}
