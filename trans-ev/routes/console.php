<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bring stations back online when their Offline / Maintenance window ends.
Schedule::call(fn () => app(\App\Services\StationStatusService::class)->revertExpired())
    ->name('stations:revert-expired-status')
    ->everyMinute()
    ->withoutOverlapping();

// Release slot holds whose payment window has passed.
Schedule::call(fn () => app(\App\Services\BookingService::class)->expireHolds())
    ->name('bookings:expire-holds')
    ->everyMinute()
    ->withoutOverlapping();

// Confirm payments whose customer never came back from checkout (and close abandoned ones).
Schedule::call(function () {
    $service = app(\App\Services\Payments\PaymentService::class);
    \App\Models\Payment::query()->where('status', \App\Models\Payment::PENDING)
        ->where('created_at', '<', now()->subMinutes(2))->where('created_at', '>', now()->subDays(2))
        ->where(fn ($q) => $q->whereNull('last_enquiry_at')->orWhere('last_enquiry_at', '<', now()->subMinutes(2)))
        ->limit(200)->get()->each(fn ($p) => $service->reconcile($p));
})->name('payments:reconcile')->everyTwoMinutes()->withoutOverlapping();

// Track refunds until the bank confirms them.
Schedule::call(fn () => app(\App\Services\Payments\RefundService::class)->pollPending())
    ->name('refunds:poll')->everyTenMinutes()->withoutOverlapping();
Schedule::command('invoices:issue-missing')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(\App\Services\TicketService::class)->autoClose())->name('tickets:auto-close')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(\App\Services\PlatformFeeService::class)->generateAll(now('Asia/Kolkata')->subMonthNoOverflow()))->name('platform-fees:generate')->monthlyOn(1, '06:00')->timezone('Asia/Kolkata')->withoutOverlapping();

// B8: daily reconciliation (time set in Business rules → Reconciliation).
$reconcileAt = (string) rescue(fn () => \App\Support\BusinessRules::get('reconciliation.run_time'), '05:30', false);
Schedule::command('reconciliation:run')->dailyAt(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $reconcileAt) ? $reconcileAt : '05:30')
    ->timezone('Asia/Kolkata')->withoutOverlapping();

// C5: reminders before the slot and automatic no-shows after the grace period.
Schedule::call(fn () => app(\App\Services\NoShowService::class)->sendReminders())->name('bookings:reminders')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => app(\App\Services\NoShowService::class)->sweep())->name('bookings:auto-no-show')->everyMinute()->withoutOverlapping();
