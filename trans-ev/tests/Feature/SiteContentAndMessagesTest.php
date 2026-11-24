<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\SiteContentPage;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Models\SiteSection;
use App\Models\User;
use App\Notifications\PartnerUpdate;
use App\Support\Messages;
use App\Support\SiteContent;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Website copy and email wording come from the database and are editable in admin. */
class SiteContentAndMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SuperAdmin->value);
    }

    public function test_every_section_is_seeded_and_served(): void
    {
        $this->assertSame(count(SiteContent::SECTIONS), SiteSection::count());
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->component('home')
            ->where('site.hero.title_line1', 'Charge faster.')
            ->where('site.hero.badge', '{online} of {stations} stations live now · {cities} cities')
            ->has('site.faq.items', 7)
            ->has('pricing.idleFee'));
        $this->get('/safety')->assertInertia(fn ($p) => $p->where('site.header.title', 'Charge safely, every time')->has('site.tips.items', 6));
        $this->get('/pricing')->assertInertia(fn ($p) => $p->has('live.pricing.sessionFee')->has('site.facts.items', 4));
    }

    public function test_editing_content_changes_the_website(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SiteContentPage::class)
            ->set('data.home__hero.title_line1', 'Plug in. <b>Power up.</b>')
            ->set('data.home__faq.items', [['q' => 'Only question?', 'a' => 'Only answer.']])
            ->call('save');

        $this->get('/')->assertInertia(fn ($p) => $p
            ->where('site.hero.title_line1', 'Plug in. Power up.') // tags stripped
            ->where('site.faq.items', [['q' => 'Only question?', 'a' => 'Only answer.']]));
    }

    public function test_email_wording_comes_from_templates(): void
    {
        MessageTemplate::where('key', 'partner.approved')->where('channel', 'mail')->update(['subject' => 'Welcome aboard, {{company}}!', 'body' => "# Hi {{name}}\n\nGreat news for {{company}}."]);
        Messages::flush();

        $owner = User::factory()->create(['name' => 'Asha']);
        $mail = (new PartnerUpdate('partner.approved', ['company' => 'VoltWays']))->toMail($owner);
        $this->assertSame('Welcome aboard, VoltWays!', $mail->subject);
        $this->assertSame('Hi Asha', $mail->greeting);
        $this->assertSame(['Great news for VoltWays.'], $mail->introLines);
    }

    public function test_switched_off_templates_are_not_sent(): void
    {
        MessageTemplate::where('key', 'partner.approved')->update(['is_active' => false]);
        Messages::flush();
        $this->assertSame([], (new PartnerUpdate('partner.approved', []))->via(User::factory()->create()));
    }

    public function test_customer_text_cannot_inject_links_into_emails(): void
    {
        $mail = Messages::mail('ticket.agent_reply', ['name' => '[Click](https://evil.example)', 'number' => 'TKT-1', 'subject' => 'x', 'status' => 'Open']);
        $this->assertStringNotContainsString('[Click](', $mail->greeting);
    }

    public function test_account_emails_use_templates(): void
    {
        MessageTemplate::where('key', 'auth.reset_password')->update(['subject' => 'Your reset link']);
        Messages::flush();
        $user = User::factory()->create();
        $mail = (new ResetPassword('token123'))->toMail($user);
        $this->assertSame('Your reset link', $mail->subject);
        $this->assertStringContainsString('token123', $mail->actionUrl);
    }

    public function test_only_content_managers_edit(): void
    {
        $this->actingAs($this->admin)->get(SiteContentPage::getUrl())->assertOk();
        $this->actingAs($this->admin)->get(MessageTemplateResource::getUrl('index'))->assertOk();
        $owner = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $owner->assignRole(Role::CompanyOwner->value);
        $this->app['session']->flush();
        $this->actingAs($owner)->get(SiteContentPage::getUrl())->assertForbidden();
    }
}
