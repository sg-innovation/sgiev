<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\SiteContentPage;
use App\Models\User;
use App\Support\SiteContent;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Top menu and footer links come from Admin → Content → Site content → Menu & footer. */
class SiteMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class]);
    }

    public function test_default_menu_and_footer_are_served_on_every_public_page(): void
    {
        foreach (['/', '/about', '/contact', '/pricing'] as $page) {
            $this->get($page)->assertOk()->assertInertia(fn ($p) => $p
                ->has('layout.menu', 5)
                ->where('layout.menu.0', ['label' => 'Find stations', 'url' => '/stations', 'newTab' => false])
                ->where('layout.button.label', 'Book a charge')
                ->has('layout.columns', 2)
                ->where('layout.columns.1.title', 'Company')
                ->where('layout.bottomLine', 'Prices include applicable GST at checkout'));
        }
    }

    public function test_admin_can_add_delete_and_reorder_links(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);

        Livewire::test(SiteContentPage::class)
            ->set('data.layout__menu.items', [
                ['label' => 'Contact', 'url' => '/contact', 'new_tab' => false],
                ['label' => 'Our app', 'url' => 'https://play.google.com/store', 'new_tab' => true],
            ])
            ->set('data.layout__footer_columns.items', [
                ['title' => 'Help', 'links' => [['label' => 'Call us', 'url' => 'tel:+91 95230 95958', 'new_tab' => false]]],
            ])
            ->set('data.layout__footer.bottom_line', 'tranzactaev.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->get('/about')->assertInertia(fn ($p) => $p
            ->where('layout.menu', [
                ['label' => 'Contact', 'url' => '/contact', 'newTab' => false],
                ['label' => 'Our app', 'url' => 'https://play.google.com/store', 'newTab' => true],
            ])
            ->where('layout.columns', [['title' => 'Help', 'links' => [['label' => 'Call us', 'url' => 'tel:+91 95230 95958', 'newTab' => false]]]])
            ->where('layout.bottomLine', 'tranzactaev.com'));
    }

    public function test_unsafe_links_are_rejected_and_never_rendered(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);

        Livewire::test(SiteContentPage::class)
            ->set('data.layout__menu.items', [['label' => 'Bad', 'url' => 'javascript:alert(1)', 'new_tab' => false]])
            ->call('save')
            ->assertHasErrors();

        foreach (['javascript:alert(1)', '//evil.example', 'about', 'data:text/html,x'] as $bad) {
            $this->assertNull(SiteContent::safeUrl($bad), $bad);
        }
        foreach (['/pricing', 'https://example.com/a?b=1', 'mailto:help@example.com', 'tel:+919523095958'] as $ok) {
            $this->assertSame($ok, SiteContent::safeUrl($ok));
        }
    }

    public function test_menu_tab_is_platform_admin_only(): void
    {
        $owner = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $owner->assignRole(Role::CompanyOwner->value);
        $this->actingAs($owner)->get(SiteContentPage::getUrl())->assertForbidden();
    }
}
