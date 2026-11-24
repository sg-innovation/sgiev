<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Company;
use App\Models\ContactMessage;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Notifications\TicketUpdate;
use App\Services\TicketService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TicketSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** D2: ticket SLA pause, due-soon warning, breach + escalation, reporting, and contact form → tickets. */
class TicketSlaTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $lead;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, LookupSeeder::class, TicketSeeder::class]);
        $platform = Company::where('is_platform', true)->value('id');
        $this->customer = User::factory()->create();
        $this->customer->assignRole(Role::Customer->value);
        $this->lead = User::factory()->create(['company_id' => $platform]);
        $this->lead->assignRole(Role::CompanyOwner->value); // has tickets.manage
        $this->agent = User::factory()->create(['company_id' => $platform]);
        $this->agent->assignRole(Role::OperationsAdmin->value); // tickets.reply/assign
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(): Ticket
    {
        $cat = TicketCategory::where('slug', 'payment-refund')->first();

        return app(TicketService::class)->open($this->customer, $cat, 'Refund not received', 'I cancelled two days ago and have no refund yet.');
    }

    public function test_breach_escalates_once_and_warns_before(): void
    {
        $t = $this->ticket();
        $svc = app(TicketService::class);
        app(TicketService::class)->assign($t, $this->agent, $this->lead);
        $firstDue = $t->fresh()->first_response_due_at;

        // Shortly before the deadline: the assignee is warned once.
        $this->travelTo($firstDue->copy()->subMinutes(10));
        $this->assertSame(0, $svc->checkSla());
        Notification::assertSentTo($this->agent, TicketUpdate::class, fn ($n) => $n->template === 'ticket.sla_warning');
        $svc->checkSla();
        $this->assertCount(1, Notification::sent($this->agent, TicketUpdate::class)->filter(fn ($n) => $n->template === 'ticket.sla_warning'), 'warned once');

        $this->assertSame('due_soon', $t->fresh()->slaState());
        $this->travelTo($firstDue->copy()->addMinute());
        $this->assertSame(1, $svc->checkSla());
        $t->refresh();
        $this->assertNotNull($t->first_response_breached_at);
        $this->assertSame(1, $t->escalation_level);
        $this->assertSame('breached', $t->slaState());
        Notification::assertSentTo($this->lead, TicketUpdate::class, fn ($n) => $n->template === 'ticket.sla_breach' && $n->vars['what'] === 'first reply');
        $this->assertSame(0, $svc->checkSla(), 'not twice');
        $this->assertTrue($t->messages()->where('body', 'like', 'SLA breached%')->exists());
    }

    public function test_waiting_on_requester_pauses_the_resolution_clock(): void
    {
        $t = $this->ticket();
        $svc = app(TicketService::class);
        $due = $t->resolution_due_at->copy();

        $this->travel(30)->minutes();
        $svc->reply($t, $this->agent, 'Could you share the UPI reference?');
        $t->refresh();
        $this->assertSame('waiting_on_customer', $t->status);
        $this->assertNotNull($t->sla_paused_at);
        $this->assertSame('paused', $t->slaState());

        // A long wait for the customer does not count against the team.
        $this->travel(3)->days();
        $this->assertSame(0, $svc->checkSla());
        $svc->reply($t, $this->customer, 'Here it is: 412345678901');
        $t->refresh();
        $this->assertNull($t->sla_paused_at);
        $this->assertGreaterThanOrEqual(3 * 24 * 60, $t->sla_paused_minutes);
        $this->assertTrue($t->resolution_due_at->eq($due->copy()->addMinutes($t->sla_paused_minutes)));
        $this->assertNull($t->resolution_breached_at);
    }

    public function test_contact_form_creates_tickets_for_signed_in_users(): void
    {
        $this->actingAs($this->customer)->post('/contact', [
            'topic' => 'payment', 'name' => 'Ravi', 'email' => $this->customer->email, 'subject' => 'Charged twice',
            'message' => 'My card was charged two times for one booking.', 'consent' => '1',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'ticket'));
        $t = Ticket::sole();
        $this->assertSame('contact', $t->channel);
        $this->assertSame('payment-refund', $t->category->slug);
        $this->assertSame($t->id, ContactMessage::sole()->ticket_id);
        $this->assertSame('resolved', ContactMessage::sole()->status);
    }

    public function test_guest_message_converts_to_ticket_with_new_account(): void
    {
        $this->post('/contact', [
            'topic' => 'feedback', 'name' => 'Guest Person', 'email' => 'guest@example.com', 'phone' => '9876512345', 'subject' => 'Great app',
            'message' => 'Please add more chargers near the airport.', 'consent' => '1',
        ])->assertSessionHas('success');
        $msg = ContactMessage::sole();
        $this->assertNull($msg->ticket_id);
        $this->assertSame(0, Ticket::count());

        $admin = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);
        Livewire::test(ListContactMessages::class)->callTableAction('toTicket', $msg);

        $guest = User::where('email', 'guest@example.com')->sole();
        $this->assertTrue($guest->hasRole(Role::Customer->value));
        $this->assertSame('9876512345', $guest->phone);
        $t = Ticket::sole();
        $this->assertSame($guest->id, $t->requester_id);
        $this->assertSame('feedback', $t->category->slug);
        Notification::assertSentTo($guest, ResetPassword::class);
        // Converting again does nothing new.
        app(TicketService::class)->fromContactMessage($msg->fresh(), $admin);
        $this->assertSame(1, Ticket::count());
    }

    public function test_ticket_list_shows_sla_stats_and_breached_filter(): void
    {
        $t = $this->ticket();
        $this->travelTo($t->first_response_due_at->copy()->addMinute());
        app(TicketService::class)->checkSla();
        $this->actingAs($this->lead)->get(TicketResource::getUrl())->assertOk()->assertSee('overdue')->assertSee('Breached');
        $this->get(TicketResource::getUrl('view', ['record' => $t]))->assertOk()->assertSee('SLA breached');
    }
}
