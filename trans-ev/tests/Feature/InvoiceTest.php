<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Notifications\GstDocumentIssued;
use App\Services\Gstr1Export;
use App\Services\InvoiceService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;
use ZipArchive;

/** B5: GST tax invoices and credit notes. */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->station = Station::where('code', 'PAT-BLY')->first(); // Bihar: Tranzacta's GSTIN applies
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidBooking(string $at = '2026-11-25 12:00', string $gstin = '', ?Station $station = null): Booking
    {
        $station ??= $this->station;
        $charger = $station->chargers()->where('type', 'DC')->first() ?? $station->chargers()->first();
        $this->actingAs($this->customer)->post("/book/{$station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => $gstin, 'state_code' => $station->state_code, 'rule_set_id' => RuleSet::activeFor($station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $booking = Booking::latest('id')->first();
        $this->post("/account/bookings/{$booking->code}/pay");
        $payment = $booking->payments()->first();
        [, $query] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($query));

        return $booking->fresh();
    }

    public function test_payment_issues_a_numbered_invoice_matching_the_booking(): void
    {
        $b = $this->paidBooking();
        $inv = $b->invoice;

        $this->assertNotNull($inv);
        $this->assertSame('TEV/2627/000001', $inv->number);
        $this->assertLessThanOrEqual(16, strlen($inv->number));
        $this->assertSame('2026-27', $inv->financial_year);
        $this->assertSame('10AANCT1689C1ZG', $inv->supplier_gstin);
        $this->assertSame('B2C', $inv->supply_type);
        $this->assertSame((string) $b->total_amount, (string) $inv->total);
        $this->assertSame((string) $b->taxable_amount, (string) $inv->taxable_amount);
        $this->assertSame(bcadd((string) $inv->taxable_amount, (string) $inv->total_tax, 2), (string) $inv->total);
        $this->assertSame(64, strlen($inv->content_hash));
        $this->assertStringEndsWith(' Only', $inv->amount_in_words);
        Notification::assertSentTo($this->customer, GstDocumentIssued::class);

        $second = $this->paidBooking('2026-11-25 14:00', '10ABCDE1234F1ZF');
        $this->assertSame('TEV/2627/000002', $second->invoice->number, 'gapless, consecutive');
        $this->assertSame('B2B', $second->invoice->supply_type);
        $this->assertSame('10ABCDE1234F1ZF', $second->invoice->recipient_gstin);
    }

    public function test_issuing_is_idempotent(): void
    {
        $b = $this->paidBooking();
        $again = app(InvoiceService::class)->issueForBooking($b);
        $this->assertSame($b->invoice->id, $again->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_numbering_restarts_each_financial_year(): void
    {
        $this->paidBooking();
        Carbon::setTestNow(Carbon::parse('2027-04-01 09:00', 'Asia/Kolkata'));
        $b = $this->paidBooking('2027-04-01 12:00');
        $this->assertSame('TEV/2728/000001', $b->invoice->number);
        $this->assertSame('2027-28', $b->invoice->financial_year);
    }

    public function test_invoices_are_immutable_and_cannot_be_deleted(): void
    {
        $inv = $this->paidBooking()->invoice;

        try {
            $inv->update(['total' => '1.00']);
            $this->fail('model update should throw');
        } catch (LogicException) {
        }
        try {
            DB::table('invoices')->where('id', $inv->id)->update(['total' => '1.00']);
            $this->fail('database should refuse the update');
        } catch (QueryException) {
        }
        try {
            DB::table('invoices')->where('id', $inv->id)->delete();
            $this->fail('database should refuse the delete');
        } catch (QueryException) {
        }
        // Delivery tracking may still be recorded.
        $inv = $inv->fresh();
        $inv->forceFill(['emailed_at' => now()])->save();
        $this->assertSame((string) $inv->total, (string) $inv->fresh()->total);
    }

    public function test_refund_issues_a_credit_note_with_gst_reversed(): void
    {
        $b = $this->paidBooking('2026-11-24 14:00');
        Carbon::setTestNow(Carbon::parse('2026-11-24 13:00', 'Asia/Kolkata')); // 50% refund window
        $this->actingAs($this->customer)->post("/account/bookings/{$b->code}/cancel")->assertSessionHas('success');

        $refund = $b->refunds()->first();
        $note = CreditNote::where('refund_id', $refund->id)->first();
        $this->assertNotNull($note);
        $this->assertSame('TEVC/2627/00001', $note->number);
        $this->assertSame((string) $refund->amount, (string) $note->total);
        $this->assertSame((string) $note->total, bcadd((string) $note->taxable_amount, (string) $note->total_tax, 2));
        $this->assertSame((string) $note->total_tax, bcadd((string) $note->cgst, (string) $note->sgst, 2));
        $this->assertSame($b->invoice->id, $note->invoice_id);
        $this->assertLessThanOrEqual((float) $b->invoice->total, (float) $note->total);
    }

    public function test_no_invoice_without_a_gstin_in_the_station_state(): void
    {
        $delhi = Station::where('code', 'DEL-CP')->first(); // platform has no Delhi GSTIN
        $b = $this->paidBooking('2026-11-25 12:00', '', $delhi);
        $this->assertSame('confirmed', $b->status->value);
        $this->assertNull($b->invoice);
    }

    public function test_access_to_pdfs(): void
    {
        $b = $this->paidBooking();
        $this->actingAs($this->customer)->get(route('bookings.invoice', $b))->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');

        $stranger = User::factory()->create();
        $stranger->assignRole(Role::Customer->value);
        $this->actingAs($stranger)->get(route('bookings.invoice', $b))->assertForbidden();

        $gg = User::where('email', 'owner@greengrid.test')->first();
        $this->actingAs($gg)->get(route('bookings.invoice', $b))->assertForbidden();
    }

    public function test_public_verification(): void
    {
        $inv = $this->paidBooking()->invoice;
        $this->get(route('invoices.verify', ['invoice' => $inv->uuid, 'h' => substr($inv->content_hash, 0, 16)]))
            ->assertOk()->assertSee('Genuine invoice')->assertSee($inv->number)->assertDontSee('driver@example.com');
        $this->get(route('invoices.verify', ['invoice' => $inv->uuid, 'h' => 'wrong']))->assertOk()->assertSee('Could not verify');
    }

    public function test_gstr1_export_files(): void
    {
        $this->paidBooking();
        $this->paidBooking('2026-11-25 14:00', '10ABCDE1234F1ZF');
        $path = app(Gstr1Export::class)->build('10AANCT1689C1ZG', Carbon::parse('2026-11-01', 'Asia/Kolkata'), sys_get_temp_dir());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        foreach (['b2b.csv', 'b2cs.csv', 'cdnr.csv', 'hsn.csv', 'docs.csv'] as $f) {
            $this->assertNotFalse($zip->locateName($f), $f);
        }
        $this->assertStringContainsString('10ABCDE1234F1ZF', $zip->getFromName('b2b.csv'));
        $this->assertStringContainsString('10-Bihar', $zip->getFromName('b2cs.csv'));
        $this->assertStringContainsString('TEV/2627/000001', $zip->getFromName('docs.csv'));
        $zip->close();
        @unlink($path);
    }

    public function test_admin_register_is_scoped_to_the_company(): void
    {
        $inv = $this->paidBooking()->invoice;
        $gg = User::where('email', 'owner@greengrid.test')->first();
        $this->actingAs($gg);
        Livewire::test(ListInvoices::class)->assertCanNotSeeTableRecords([$inv]);

        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);
        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$inv]);
    }
}
