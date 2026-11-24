<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\CompanyDocumentController;
use App\Http\Controllers\StationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', HomeController::class)->name('home');

Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
Route::get('/stations/search', [StationController::class, 'search'])
    ->middleware('throttle:60,1')
    ->name('stations.search');
Route::get('/stations/{station:slug}', [StationController::class, 'show'])->name('stations.show');

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/category/{category:slug}', [BlogController::class, 'category'])->name('blog.category');
Route::get('/blog/tag/{tag:slug}', [BlogController::class, 'tag'])->name('blog.tag');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('blog.show');

// Payments (SabPaisa). The return URL is hit by the customer's browser; the webhook by SabPaisa's servers.
Route::get('/payments/sabpaisa/return/{payment:uuid}', [PaymentController::class, 'return'])->middleware('throttle:30,1')->name('payments.return');
Route::post('/webhooks/sabpaisa/{company:slug}', [PaymentController::class, 'webhook'])->middleware('throttle:600,1')->name('payments.webhook');

if (config('payments.sabpaisa.simulate')) {
    Route::get('/dev/sabpaisa/checkout/{payment:uuid}', [PaymentController::class, 'simulatorCheckout'])->name('dev.sabpaisa.checkout');
    Route::post('/dev/sabpaisa/checkout/{payment:uuid}', [PaymentController::class, 'simulatorComplete'])->name('dev.sabpaisa.complete');
}

// Station owners: self-registration (reviewed by Trans EV before going live)
Route::get('/walk-in/paid/{payment:uuid}', function (\App\Models\Payment $payment) {
    abort_unless($payment->booking?->source === 'walk_in', 404);
    if ($payment->status === \App\Models\Payment::PENDING) {
        $payment = app(\App\Services\Payments\PaymentService::class)->reconcile($payment);
    }

    return view('checkin.walk-in-paid', ['payment' => $payment->load('booking.station', 'booking.invoice'),
        'invoiceUrl' => \Illuminate\Support\Facades\URL::temporarySignedRoute('walk-in.invoice', now()->addDays(30), ['payment' => $payment->uuid])]);
})->middleware('throttle:30,1')->name('walk-in.paid');
Route::get('/walk-in/{payment:uuid}/invoice', [\App\Http\Controllers\InvoiceController::class, 'walkIn'])->middleware(['signed', 'throttle:30,1'])->name('walk-in.invoice');
Route::get('/verify/invoice/{invoice:uuid}', [\App\Http\Controllers\InvoiceController::class, 'verify'])->middleware('throttle:30,1')->name('invoices.verify');
Route::get('/partner', [PartnerController::class, 'create'])->name('partner.create');
Route::post('/partner', [PartnerController::class, 'store'])->middleware('throttle:3,10')->name('partner.store');
Route::get('/partner/thanks', [PartnerController::class, 'thanks'])->name('partner.thanks');
Route::get('/kyc/{document}', [CompanyDocumentController::class, 'show'])->middleware('auth')->name('kyc.show');

Route::get('/p/{slug}', function (string $slug) {
    $dbPage = \App\Models\Page::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

    return Inertia::render('page', ['page' => 'custom', 'content' => \App\Support\PageContent::render($dbPage), 'topics' => []]);
})->where('slug', '[a-z0-9-]+')->name('pages.custom');

Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

Route::get('/{page}', function (string $page) {
    // Pages stored in the database (Admin → Content → Website pages) win over the built-in layouts.
    $dbPage = \App\Models\Page::query()->where('slug', $page)->where('is_published', true)->first();

    return Inertia::render('page', [
        'page' => $page,
        'content' => $dbPage ? \App\Support\PageContent::render($dbPage) : null,
        'site' => \App\Support\SiteContent::forPage($page),
        'live' => $page === 'pricing' ? \App\Http\Controllers\HomeController::liveData() : null,
        'topics' => $page === 'contact' ? collect(\App\Models\ContactMessage::topics())->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values() : [],
    ]);
})
    ->whereIn('page', ['about', 'contact', 'pricing', 'terms', 'privacy', 'refund-policy', 'safety'])
    ->name('page');

Route::middleware(['auth'])->group(function () {
    Route::middleware('verified')->group(function () {
        Route::get('/book/{station:slug}', [BookingController::class, 'create'])->name('bookings.create');
        Route::get('/book/{station:slug}/availability', [BookingController::class, 'availability'])->middleware('throttle:120,1')->name('bookings.availability');
        Route::post('/book/{station:slug}/estimate', [BookingController::class, 'estimate'])->middleware('throttle:120,1')->name('bookings.estimate');
        Route::post('/book/{station:slug}', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
    });

    Route::get('/account/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('/account/bookings/{booking:code}', [BookingController::class, 'show'])->name('bookings.show');
    Route::post('/account/bookings/{booking:code}/running-late', [BookingController::class, 'runningLate'])->middleware('throttle:10,1')->name('bookings.running-late');
    Route::post('/account/bookings/{booking:code}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:10,1')->name('bookings.cancel');
    Route::post('/account/bookings/{booking:code}/pay', [PaymentController::class, 'start'])->middleware(['verified', 'throttle:10,1'])->name('bookings.pay');
    Route::post('/account/bookings/{booking:code}/payment-status', [PaymentController::class, 'refresh'])->middleware('throttle:20,1')->name('bookings.payment-status');
    Route::get('/account/bookings/{booking:code}/receipt', [PaymentController::class, 'receipt'])->name('bookings.receipt');
    Route::get('/account/bookings/{booking:code}/invoice', [\App\Http\Controllers\InvoiceController::class, 'invoice'])->middleware('throttle:30,1')->name('bookings.invoice');
    Route::get('/platform-fees/{statement:uuid}/pdf', function (\Illuminate\Http\Request $request, \App\Models\PlatformFeeStatement $statement) {
        $user = $request->user();
        $allowed = \App\Filament\Resources\PlatformFees\PlatformFeeResource::manager()
            || ($user->can('payments.view') && $user->company_id === $statement->company_id && in_array($statement->status, ['issued', 'paid'], true));
        abort_unless($allowed, 404);
        activity()->performedOn($statement)->causedBy($user)->log('platform fee pdf downloaded');

        return response(app(\App\Services\GstDocumentPdf::class)->renderStatement($statement), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$statement->filename().'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    })->middleware('throttle:30,1')->name('platform-fees.pdf');
    // C2 staff console
    Route::prefix('staff')->name('staff.')->middleware('throttle:120,1')->group(function () {
        Route::get('/', [\App\Http\Controllers\StaffConsoleController::class, 'index'])->name('console');
        Route::post('/scan', [\App\Http\Controllers\StaffConsoleController::class, 'scan'])->name('scan');
        Route::post('/bookings/{booking:code}/check-in', [\App\Http\Controllers\StaffConsoleController::class, 'checkIn'])->name('check-in');
        Route::post('/bookings/{booking:code}/start', [\App\Http\Controllers\StaffConsoleController::class, 'start'])->name('start');
        Route::post('/bookings/{booking:code}/no-show', [\App\Http\Controllers\StaffConsoleController::class, 'noShow'])->name('no-show');
        Route::post('/sessions/{session:uuid}/stop', [\App\Http\Controllers\StaffConsoleController::class, 'stop'])->name('stop');
        Route::post('/connectors/{connector}/status', [\App\Http\Controllers\StaffConsoleController::class, 'connectorStatus'])->name('connector-status');
        Route::post('/walk-ins', [\App\Http\Controllers\StaffConsoleController::class, 'walkIn'])->name('walk-in');
        Route::post('/sessions/{session:uuid}/reading', [\App\Http\Controllers\StaffConsoleController::class, 'reading'])->name('reading');
        Route::post('/bookings/{booking:code}/collect', [\App\Http\Controllers\StaffConsoleController::class, 'collect'])->name('collect');
        Route::post('/bookings/{booking:code}/payment-link', [\App\Http\Controllers\StaffConsoleController::class, 'paymentLink'])->name('payment-link');
    });
    // C1 check-in
    Route::get('/account/bookings/{booking:code}/pass', [\App\Http\Controllers\CheckInController::class, 'pass'])->middleware('throttle:30,1')->name('bookings.pass');
    Route::get('/checkin/{token}', [\App\Http\Controllers\CheckInController::class, 'show'])->middleware('throttle:60,1')->name('checkin.show');
    Route::post('/checkin/{token}', [\App\Http\Controllers\CheckInController::class, 'store'])->middleware('throttle:30,1')->name('checkin.store');
    Route::get('/charge/{key}', [\App\Http\Controllers\CheckInController::class, 'charger'])->middleware('throttle:30,1')->name('charge.show');
    Route::post('/charge/{key}', [\App\Http\Controllers\CheckInController::class, 'chargerCheckIn'])->middleware('throttle:10,1')->name('charge.checkin');
    Route::get('/print/chargers/{charger}/qr', [\App\Http\Controllers\CheckInController::class, 'sticker'])->name('chargers.sticker');
    Route::get('/account/support', [\App\Http\Controllers\SupportController::class, 'index'])->name('support.index');
    Route::post('/account/support', [\App\Http\Controllers\SupportController::class, 'store'])->middleware('throttle:5,10')->name('support.store');
    Route::get('/account/support/{ticket:uuid}', [\App\Http\Controllers\SupportController::class, 'show'])->name('support.show');
    Route::post('/account/support/{ticket:uuid}/reply', [\App\Http\Controllers\SupportController::class, 'reply'])->middleware('throttle:20,10')->name('support.reply');
    Route::post('/account/support/{ticket:uuid}/status', [\App\Http\Controllers\SupportController::class, 'status'])->middleware('throttle:20,10')->name('support.status');
    Route::get('/support-files/{ticket:uuid}/{message}/{index}', [\App\Http\Controllers\SupportController::class, 'file'])->whereNumber(['message', 'index'])->name('support.file');
    Route::get('/account/credit-notes/{creditNote:uuid}', [\App\Http\Controllers\InvoiceController::class, 'creditNote'])->middleware('throttle:30,1')->name('credit-notes.show');

    Route::get('dashboard', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        $paid = [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::CheckedIn, \App\Enums\BookingStatus::Completed];

        return Inertia::render('dashboard', [
            'stats' => [
                'upcoming' => $user->bookings()->occupying()->where('start_at', '>', now())->count(),
                'kwh' => (float) $user->bookings()->whereIn('status', $paid)->sum('est_kwh'),
                'spent' => (float) $user->bookings()->whereIn('status', $paid)->sum('total_amount'),
                'bookings' => $user->bookings()->count(),
                'saved' => (float) $user->bookings()->whereIn('status', $paid)->sum('discount_amount'),
            ],
            // Latest GST documents (invoices and credit notes), newest first.
            'documents' => \App\Models\Invoice::query()->where('user_id', $user->id)->with('booking')->latest('issued_at')->limit(5)->get()
                ->map(fn (\App\Models\Invoice $i) => ['kind' => 'Tax invoice', 'number' => $i->number, 'ts' => $i->issued_at->timestamp, 'date' => $i->issued_at->setTimezone(\App\Models\Station::TIMEZONE)->format('j M Y'),
                    'amount' => (float) $i->total, 'url' => route('bookings.invoice', $i->booking), 'booking' => $i->booking->code, 'station' => $i->site['station'] ?? null])
                ->concat(\App\Models\CreditNote::query()->whereHas('booking', fn ($q) => $q->where('user_id', $user->id))->with('booking')->latest('issued_at')->limit(3)->get()
                    ->map(fn (\App\Models\CreditNote $c) => ['kind' => 'Credit note', 'number' => $c->number, 'ts' => $c->issued_at->timestamp, 'date' => $c->issued_at->setTimezone(\App\Models\Station::TIMEZONE)->format('j M Y'),
                        'amount' => -1 * (float) $c->total, 'url' => route('credit-notes.show', $c), 'booking' => $c->booking->code, 'station' => null]))
                ->sortByDesc('ts')->values(),
            'offers' => \App\Models\Coupon::query()->live()->where('is_public', true)->whereNull('company_id')->orderBy('ends_at')->limit(3)->get()
                ->map(fn ($c) => \App\Services\CouponService::present($c)),
            'upcoming' => $user->bookings()->with(['station', 'charger'])->occupying()->where('start_at', '>', now())
                ->orderBy('start_at')->limit(3)->get()->map(fn ($b) => \App\Http\Resources\BookingPresenter::summary($b)),
            'vehicles' => $user->vehicles()->limit(3)->get()->map(fn ($v) => ['id' => $v->id, 'label' => $v->label(), 'battery' => (float) $v->battery_kwh]),
        ]);
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
