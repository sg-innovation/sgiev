<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketUpdate;
use App\Services\Support\InboundEmailService;
use App\Services\TicketService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TicketSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Support as one thread: guest tickets on a private link, and replies by email into the thread. */
class GuestTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $customer;

    private const TOKEN = 'inbound-secret-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, LookupSeeder::class, TicketSeeder::class]);
        $platform = Company::where('is_platform', true)->value('id');
        $this->agent = User::factory()->create(['company_id' => $platform, 'email' => 'agent@transev.test']);
        $this->agent->assignRole(Role::OperationsAdmin->value);
        $this->customer = User::factory()->create(['email' => 'ravi@example.com']);
        $this->customer->assignRole(Role::Customer->value);
        config(['services.support_inbound.address' => 'support@transev.test', 'services.support_inbound.token' => self::TOKEN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guestTicket(): Ticket
    {
        return app(TicketService::class)->openForGuest('Asha Guest', 'asha@example.com', '9876512345',
            TicketCategory::where('slug', 'feedback')->firstOrFail(), 'Charger near airport', 'Please add chargers near the airport.');
    }

    public function test_guest_reads_and_replies_through_the_private_link(): void
    {
        $t = $this->guestTicket();
        $this->get($t->guestUrl())->assertOk()->assertInertia(fn ($page) => $page->component('support/guest')
            ->where('ticket.number', $t->number)->where('ticket.messages.0.mine', true)->where('guestName', 'Asha Guest'));

        // Without the signature, with a tampered one, or with an old link version: not found / forbidden.
        $this->get("/support/t/{$t->uuid}?v=1")->assertForbidden();
        $this->get($t->guestUrl().'x')->assertForbidden();
        $old = $t->guestUrl();
        $t->forceFill(['guest_link_version' => 2])->save();
        $this->get($old)->assertNotFound();
        $t->refresh();

        // Agent replies → the guest gets the reply text by email, with a Reply-To into the thread.
        app(TicketService::class)->reply($t, $this->agent, 'We are adding two chargers there next month.');
        Notification::assertSentOnDemand(TicketUpdate::class, function (TicketUpdate $n, $channels, $notifiable) use ($t) {
            if ($n->template !== 'ticket.agent_reply') {
                return false;
            }
            $mail = $n->toMail($notifiable);

            return str_contains(implode("\n", $mail->introLines), 'two chargers') && $mail->replyTo[0][0] === "support+{$t->number}@transev.test"
                && str_contains($mail->actionUrl, '/support/t/'.$t->uuid);
        });
        $this->assertSame('waiting_on_customer', $t->fresh()->status);

        // Guest replies with an attachment on the link → back to open, the team is told.
        $this->post($t->guestUrl('support.guest.reply'), ['body' => 'Thank you! Which month?', 'files' => [UploadedFile::fake()->image('photo.jpg')]])->assertRedirect();
        $t->refresh();
        $this->assertSame('open', $t->status);
        $msg = TicketMessage::where('ticket_id', $t->id)->where('via', 'guest')->latest('id')->first();
        $this->assertSame(['guest', 'Asha Guest'], [$msg->via, $msg->author_name]);
        $this->get($t->guestUrl('support.guest.file', ['message' => $msg->id, 'index' => 0]))->assertOk();

        // Internal notes never reach the guest page.
        app(TicketService::class)->reply($t, $this->agent, 'Internal: ask the ops team.', true);
        $this->get($t->guestUrl())->assertOk()->assertDontSee('ask the ops team');

        // Guest marks it solved and rates it.
        $this->post($t->guestUrl('support.guest.status'), ['status' => 'closed', 'rating' => 5])->assertRedirect();
        $this->assertSame(['closed', 5], [$t->fresh()->status, $t->fresh()->rating]);
        $this->post($t->guestUrl('support.guest.reply'), ['body' => 'One more thing'])->assertSessionHasErrors('body');
    }

    public function test_email_replies_land_in_the_thread_from_known_senders_only(): void
    {
        $t = $this->guestTicket();
        $url = '/webhooks/inbound-email/'.self::TOKEN;

        // Postmark-style JSON from the guest, to the plus address; quoted history is removed.
        $this->postJson($url, [
            'FromFull' => ['Email' => 'Asha@Example.com', 'Name' => 'Asha'], 'OriginalRecipient' => "support+{$t->number}@transev.test",
            'Subject' => "Re: We have received your message · {$t->number}",
            'TextBody' => "Thanks, that helps!\n\nOn Tue, 24 Nov 2026 at 10:05, Trans EV support <support@transev.test> wrote:\n> Hello Asha,\n> We have opened ticket",
            'Attachments' => [['Name' => 'receipt.pdf', 'ContentType' => 'application/pdf', 'Content' => base64_encode("%PDF-1.4\n%%EOF")]],
        ])->assertOk()->assertJson(['status' => 'added', 'ticket' => $t->number]);
        $m = TicketMessage::where('ticket_id', $t->id)->where('via', 'email')->sole();
        $this->assertSame('Thanks, that helps!', $m->body);
        $this->assertCount(1, $m->attachments);
        $this->assertTrue($t->fresh()->isFromRequester($m));

        // The same email delivered twice is stored once.
        $this->postJson($url, ['from' => 'asha@example.com', 'to' => "support+{$t->number}@transev.test", 'subject' => 'Re', 'text' => 'Thanks, that helps!'])
            ->assertJson(['status' => 'duplicate']);

        // Mailgun-style form post from an agent, found by the subject.
        $this->post($url, ['sender' => 'agent@transev.test', 'recipient' => 'support@transev.test', 'subject' => "Re: Customer replied on {$t->number}",
            'body-plain' => "Adding them in December.\n\n-- \nAgent signature"])->assertOk()->assertJson(['status' => 'added']);
        $this->assertSame('Adding them in December.', TicketMessage::where('ticket_id', $t->id)->where('user_id', $this->agent->id)->sole()->body);
        $this->assertSame('agent', $t->fresh()->last_reply_by);

        // Strangers, unknown tickets and a wrong token are ignored or refused.
        $this->postJson($url, ['from' => 'stranger@example.com', 'to' => "support+{$t->number}@transev.test", 'text' => 'Hijack attempt'])
            ->assertJson(['status' => 'ignored_unknown_sender']);
        $this->postJson($url, ['from' => 'asha@example.com', 'to' => 'support@transev.test', 'subject' => 'Hello', 'text' => 'No ticket number'])
            ->assertJson(['status' => 'ignored_no_ticket']);
        $this->postJson('/webhooks/inbound-email/wrong-token', ['from' => 'asha@example.com'])->assertForbidden();
        $this->assertSame(2, TicketMessage::where('ticket_id', $t->id)->where('via', 'email')->count());
    }

    public function test_account_holders_can_reply_by_email_too_and_off_without_settings(): void
    {
        $t = app(TicketService::class)->open($this->customer, TicketCategory::where('slug', 'payment-refund')->firstOrFail(), 'Refund', 'Where is my refund?');
        $this->postJson('/webhooks/inbound-email/'.self::TOKEN, ['from' => 'ravi@example.com', 'to' => "support+{$t->number}@transev.test", 'text' => "Any update?\n> quoted"])
            ->assertJson(['status' => 'added']);
        $m = TicketMessage::where('ticket_id', $t->id)->where('via', 'email')->sole();
        $this->assertSame([$this->customer->id, 'Any update?'], [$m->user_id, $m->body]);
        $this->actingAs($this->customer)->get(route('support.show', $t))->assertOk()
            ->assertInertia(fn ($page) => $page->where('ticket.messages.1.viaEmail', true)->where('replyByEmail', true));

        config(['services.support_inbound.address' => null]);
        $this->assertNull(InboundEmailService::replyAddress($t));
        $this->postJson('/webhooks/inbound-email/'.self::TOKEN, ['from' => 'ravi@example.com'])->assertForbidden();
    }

    public function test_quote_stripping_handles_common_clients(): void
    {
        $this->assertSame('Yes please', InboundEmailService::stripQuoted("Yes please\r\n\r\nOn Mon, 23 Nov 2026, 9:00 pm Trans EV <s@x.in>\r\nwrote:\r\n> old"));
        $this->assertSame('Done', InboundEmailService::stripQuoted("Done\n\n-----Original Message-----\nFrom: x"));
        $this->assertSame('Fine', InboundEmailService::stripQuoted("Fine\n\nFrom: Trans EV <s@x.in>\nSent: Monday\nTo: me"));
        $this->assertSame("Line one\nLine two", InboundEmailService::stripQuoted("Line one\n> quoted\nLine two"));
    }

    public function test_admin_sees_guest_details_and_can_reissue_the_link(): void
    {
        $t = $this->guestTicket();
        $this->actingAs($this->agent)->get(\App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $t]))->assertOk()
            ->assertSee('Asha Guest')->assertSee('asha@example.com')->assertSee('Guest (no account)');
        $this->get(\App\Filament\Resources\Tickets\TicketResource::getUrl())->assertOk()->assertSee('Asha Guest');
        Livewire::test(ViewTicket::class, ['record' => $t->uuid])->callAction('newLink');
        $this->assertSame(2, $t->fresh()->guest_link_version);
        Notification::assertSentOnDemandTimes(TicketUpdate::class, 2); // received on creation + the new link
    }
}
