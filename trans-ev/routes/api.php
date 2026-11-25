<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\SupportController;
use Illuminate\Support\Facades\Route;

/*
| Trans EV REST API v1 (Phase E) for the Android / iOS apps. JSON only; Sanctum bearer tokens.
| Documentation and a live console: /developers (6-digit PIN given by Trans EV in Admin → Settings → API developers).
*/
Route::prefix('v1')->name('api.v1.')->middleware(['api.json', 'throttle:api'])->group(function () {
    // Sign-in
    Route::post('auth/otp', [AuthController::class, 'requestOtp'])->middleware('throttle:otp')->name('auth.otp');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp')->name('auth.otp.verify');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:otp')->name('auth.login');
    Route::post('auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:otp')->name('auth.two-factor');

    // Public catalogue
    Route::get('config', [CatalogController::class, 'config'])->name('config');
    Route::get('stations', [CatalogController::class, 'stations'])->name('stations.index');
    Route::get('stations/{station:slug}', [CatalogController::class, 'station'])->name('stations.show');
    Route::get('stations/{station:slug}/reviews', [CatalogController::class, 'reviews'])->name('stations.reviews');

    // Signed downloads (links come from authenticated responses)
    Route::middleware('signed')->group(function () {
        Route::get('documents/invoices/{booking:code}', [SupportController::class, 'invoicePdf'])->name('documents.invoice');
        Route::get('documents/credit-notes/{creditNote:uuid}', [SupportController::class, 'creditNotePdf'])->name('documents.credit-note');
        Route::get('documents/tickets/{ticket:uuid}/{message}/{index}', [SupportController::class, 'ticketFile'])->whereNumber(['message', 'index'])->name('documents.ticket-file');
    });

    Route::middleware(['auth:sanctum', 'api.active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('me', [AccountController::class, 'me'])->name('me');
        Route::patch('me', [AccountController::class, 'update'])->name('me.update');
        Route::post('me/avatar', [AccountController::class, 'avatar'])->name('me.avatar');
        Route::delete('me/avatar', [AccountController::class, 'removeAvatar'])->name('me.avatar.remove');
        Route::get('me/notifications', [AccountController::class, 'notifications'])->name('me.notifications');
        Route::put('me/notifications', [AccountController::class, 'updateNotifications'])->name('me.notifications.update');
        Route::post('me/devices', [AccountController::class, 'registerDevice'])->name('me.devices.store');
        Route::delete('me/devices', [AccountController::class, 'removeDevice'])->name('me.devices.destroy');

        Route::get('vehicles', [AccountController::class, 'vehicles'])->name('vehicles.index');
        Route::post('vehicles', [AccountController::class, 'storeVehicle'])->name('vehicles.store');
        Route::patch('vehicles/{vehicle}', [AccountController::class, 'updateVehicle'])->name('vehicles.update');
        Route::delete('vehicles/{vehicle}', [AccountController::class, 'destroyVehicle'])->name('vehicles.destroy');

        Route::get('stations/{station:slug}/availability', [CatalogController::class, 'availability'])->name('stations.availability');
        Route::post('stations/{station:slug}/estimate', [CatalogController::class, 'estimate'])->name('stations.estimate');
        Route::post('stations/{station:slug}/bookings', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
        Route::post('stations/{station:slug}/waitlist', [SupportController::class, 'joinWaitlist'])->middleware('throttle:10,1')->name('waitlist.store');

        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking:code}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('bookings/{booking:code}/pay', [BookingController::class, 'pay'])->middleware('throttle:10,1')->name('bookings.pay');
        Route::post('bookings/{booking:code}/payment-status', [BookingController::class, 'paymentStatus'])->middleware('throttle:20,1')->name('bookings.payment-status');
        Route::post('bookings/{booking:code}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:10,1')->name('bookings.cancel');
        Route::get('bookings/{booking:code}/reschedule-options', [BookingController::class, 'rescheduleOptions'])->name('bookings.reschedule-options');
        Route::post('bookings/{booking:code}/reschedule', [BookingController::class, 'reschedule'])->middleware('throttle:10,1')->name('bookings.reschedule');
        Route::post('bookings/{booking:code}/running-late', [BookingController::class, 'runningLate'])->middleware('throttle:10,1')->name('bookings.running-late');
        Route::get('bookings/{booking:code}/pass', [BookingController::class, 'pass'])->name('bookings.pass');
        Route::get('bookings/{booking:code}/live', [BookingController::class, 'live'])->name('bookings.live');
        Route::post('bookings/{booking:code}/stop', [BookingController::class, 'stop'])->middleware('throttle:10,1')->name('bookings.stop');
        Route::post('bookings/{booking:code}/review', [BookingController::class, 'review'])->middleware('throttle:10,1')->name('bookings.review');
        Route::get('bookings/{booking:code}/documents', [BookingController::class, 'documents'])->name('bookings.documents');

        Route::get('waitlist', [SupportController::class, 'waitlist'])->name('waitlist.index');
        Route::delete('waitlist/{entry:uuid}', [SupportController::class, 'leaveWaitlist'])->name('waitlist.destroy');

        Route::get('support/options', [SupportController::class, 'ticketOptions'])->name('support.options');
        Route::get('support/tickets', [SupportController::class, 'tickets'])->name('support.index');
        Route::post('support/tickets', [SupportController::class, 'storeTicket'])->middleware('throttle:5,10')->name('support.store');
        Route::get('support/tickets/{ticket:uuid}', [SupportController::class, 'ticket'])->name('support.show');
        Route::post('support/tickets/{ticket:uuid}/reply', [SupportController::class, 'reply'])->middleware('throttle:20,10')->name('support.reply');
        Route::post('support/tickets/{ticket:uuid}/status', [SupportController::class, 'ticketStatus'])->middleware('throttle:20,10')->name('support.status');
    });
});
