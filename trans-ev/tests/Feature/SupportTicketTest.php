<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\TicketSetup\TicketCategoryResource;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketUpdate;
use App\Services\TicketService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Database\Seeders\TicketSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/** Support tickets: raising, routing, replies, internal notes, assignment and permission-based access. */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Company $platform;

    private Company $greengrid;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class, TicketSeeder::class]);
        $this->customer = User::factory()->create();
        $this->customer->assignRole(Role::Customer->value);
        $this->platform = Company::where('is_platform', true)->first();
        $this->greengrid = Company::where('slug', 'greengrid-demo')->first();
    }

    private function staff(Company $company, string $role): User
    {
        $u = User::factory()->create(['company_id' => $company->id]);
        $u->assignRole($role);

        return $u;
    }

    private function as(User $user): static
    {
        $this->app['session']->flush();

        return $this->actingAs($user);
    }

    private function open(string $slug = 'payment-refund', ?Booking $booking = null, array $extra = []): Ticket
    {
        $this->as($this->customer)->post('/account/support', array_merge([
            'category_id' => TicketCategory::where('slug', $slug)->value('id'),
            'booking_id' => $booking?->id,
            'subject' => 'Refund not received yet',
            'body' => 'I cancelled two days ago and the refund has not arrived.',
        ], $extra))->assertSessionHasNoErrors()->assertRedirect();

        return Ticket::latest('id')->first();
    }

    public function test_customer_opens_a_ticket_routed_to_trans_ev_with_sla(): void
    {
        $agent = $this->staff($this->platform, Role::OperationsAdmin->value); // has tickets.assign
        $t = $this->open();

        $this->assertMatchesRegularExpression('/^TKT-\d{6}$/', $t->number);
        $this->assertSame($this->platform->id, $t->company_id);
        $this->assertSame('open', $t->status);
        $this->assertNotNull($t->first_response_due_at);
        $this->assertTrue($t->resolution_due_at->gt($t->first_response_due_at));
        $this->assertSame(1, $t->messages()->count());
        Notification::assertSentTo($agent, TicketUpdate::class);

        $this->get('/account/support')->assertOk()->assertInertia(fn ($p) => $p->component('support/index')->has('tickets.data', 1)->has('categories'));
    }

    public function test_operator_topics_go_to_the_operator_of_the_booking(): void
    {
        $station = Station::where('code', 'GG-PUN-BNR')->first();
        $booking = Booking::factory()->forStation($station, $this->customer)->create(['status' => BookingStatus::Confirmed]);
        $t = $this->open('charger-fault', $booking);
        $this->assertSame($this->greengrid->id, $t->company_id);
        $this->assertSame($booking->station_id, $t->station_id);

        // A booking is required for that topic, and it must be the customer's own.
        $this->as($this->customer)->post('/account/support', ['category_id' => TicketCategory::where('slug', 'charger-fault')->value('id'), 'subject' => 'Charger broken', 'body' => 'It did not start at all.'])
            ->assertSessionHasErrors('booking_id');
        $other = Booking::factory()->forStation($station, User::factory()->create())->create();
        $this->as($this->customer)->post('/account/support', ['category_id' => TicketCategory::where('slug', 'charger-fault')->value('id'), 'booking_id' => $other->id, 'subject' => 'Charger broken', 'body' => 'It did not start at all.'])
            ->assertNotFound();
    }

    public function test_conversation_flow_and_internal_notes_stay_private(): void
    {
        $t = $this->open();
        $agent = $this->staff($this->platform, Role::OperationsAdmin->value);
        $svc = app(TicketService::class);

        $svc->reply($t, $agent, 'Internal: check SabPaisa refund status.', internal: true);
        $svc->reply($t, $agent, 'We have re-initiated your refund.');
        $t->refresh();
        $this->assertSame('waiting_on_customer', $t->status);
        $this->assertNotNull($t->first_responded_at);
        Notification::assertSentTo($this->customer, TicketUpdate::class);

        $this->as($this->customer)->get("/account/support/{$t->uuid}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('support/show')->has('ticket.messages', 2)
                ->where('ticket.messages.1.body', 'We have re-initiated your refund.'));
        $this->assertStringNotContainsString('Internal: check', $this->get("/account/support/{$t->uuid}")->getContent());

        $this->post("/account/support/{$t->uuid}/reply", ['body' => 'Thanks, received it.'])->assertSessionHasNoErrors();
        $this->assertSame('open', $t->fresh()->status);
        $this->post("/account/support/{$t->uuid}/status", ['status' => 'resolved', 'rating' => 5]);
        $this->assertSame('resolved', $t->fresh()->status);
        $this->assertSame(5, $t->fresh()->rating);
    }

    public function test_customers_cannot_see_other_tickets(): void
    {
        $t = $this->open();
        $stranger = User::factory()->create();
        $stranger->assignRole(Role::Customer->value);
        $this->as($stranger)->get("/account/support/{$t->uuid}")->assertNotFound();
        $this->post("/account/support/{$t->uuid}/reply", ['body' => 'hello there'])->assertNotFound();
    }

    public function test_admin_visibility_follows_permissions_and_company(): void
    {
        $platformTicket = $this->open();
        $station = Station::where('code', 'GG-PUN-BNR')->first();
        $ggTicket = $this->open('charger-fault', Booking::factory()->forStation($station, $this->customer)->create(['status' => BookingStatus::Confirmed]));

        // GreenGrid owner (tickets.view) sees GreenGrid tickets only.
        $this->as(User::where('email', 'owner@greengrid.test')->first());
        Livewire::test(ListTickets::class)->assertCanSeeTableRecords([$ggTicket])->assertCanNotSeeTableRecords([$platformTicket]);
        $this->get(\App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $platformTicket]))->assertNotFound();

        // A platform super admin sees everything.
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->as($admin);
        Livewire::test(ListTickets::class)->assertCanSeeTableRecords([$platformTicket, $ggTicket]);
    }

    public function test_designated_support_agent_role(): void
    {
        $t = $this->open();
        // Super admin creates a custom "Support agent" role with only ticket permissions and gives it to a Trans EV staff member.
        $role = RoleModel::create(['name' => 'support_agent', 'guard_name' => 'web']);
        $role->forceFill(['label' => 'Support agent', 'is_system' => false])->save();
        $role->syncPermissions(['tickets.view', 'tickets.reply']);
        \App\Support\RoleRegistry::flush();
        $agent = $this->staff($this->platform, 'support_agent');

        $this->as($agent);
        $this->assertTrue($agent->canAccessPanel(filament()->getPanel('admin')));
        Livewire::test(ListTickets::class)->assertCanSeeTableRecords([$t]);
        Livewire::test(ViewTicket::class, ['record' => $t->getRouteKey()])
            ->callAction('reply', ['body' => 'Looking into it now.'])->assertHasNoActionErrors()
            ->assertActionHidden('assign'); // no tickets.assign
        $this->assertSame('waiting_on_customer', $t->fresh()->status);

        // Staff without ticket permissions only see tickets they raised themselves.
        $plain = $this->staff($this->platform, Role::Finance->value);
        $plain->syncPermissions([]);
        $plain->roles()->first()->revokePermissionTo(['tickets.view', 'tickets.reply']);
        $this->as($plain->fresh());
        Livewire::test(ListTickets::class)->assertCanNotSeeTableRecords([$t]);
    }

    public function test_assignment_only_to_eligible_agents(): void
    {
        $t = $this->open();
        $lead = $this->staff($this->platform, Role::OperationsAdmin->value);
        $partner = User::where('email', 'owner@greengrid.test')->first();
        $svc = app(TicketService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->assign($t, $partner, $lead); // a partner cannot work Trans EV tickets
    }

    public function test_staff_can_raise_tickets_to_trans_ev(): void
    {
        $owner = User::where('email', 'owner@greengrid.test')->first();
        $this->as($owner);
        Livewire::test(\App\Filament\Resources\Tickets\Pages\CreateTicket::class)
            ->fillForm(['category_id' => TicketCategory::where('slug', 'partner-onboarding')->value('id'), 'subject' => 'Need help with KYC', 'body' => 'Which address proof is accepted?'])
            ->call('create')->assertHasNoFormErrors();
        $t = Ticket::latest('id')->first();
        $this->assertSame($this->platform->id, $t->company_id);
        $this->assertSame($this->greengrid->id, $t->requester_company_id);
    }

    public function test_attachments_are_private(): void
    {
        $t = $this->open(extra: ['files' => [UploadedFile::fake()->image('photo.jpg')]]);
        $msg = $t->messages()->first();
        $this->assertCount(1, $msg->attachments);
        Storage::disk('local')->assertExists($msg->attachments[0]['path']);

        $url = route('support.file', [$t, $msg->id, 0]);
        $this->as($this->customer)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $stranger = User::factory()->create();
        $this->as($stranger)->get($url)->assertNotFound();
    }

    public function test_messages_are_append_only(): void
    {
        $t = $this->open();
        $id = $t->messages()->value('id');
        $this->expectException(QueryException::class);
        DB::table('ticket_messages')->where('id', $id)->update(['body' => 'changed']);
    }

    public function test_topics_and_priorities_are_managed_by_trans_ev_ticket_managers(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->as($admin)->get(TicketCategoryResource::getUrl('index'))->assertOk();
        $this->as(User::where('email', 'owner@greengrid.test')->first())->get(TicketCategoryResource::getUrl('index'))->assertForbidden();
    }

    public function test_auto_close_after_resolution(): void
    {
        $t = $this->open();
        $t->forceFill(['status' => 'resolved', 'resolved_at' => now()->subDays(8)])->save();
        $this->assertSame(1, app(TicketService::class)->autoClose());
        $this->assertSame('closed', $t->fresh()->status);
        TicketMessage::where('ticket_id', $t->id)->where('event', 'system')->firstOrFail();
    }
}
