<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Messaging\Channels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** D1: the customer chooses which channels they get booking updates on (email is always on for receipts and invoices). */
class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('settings/notifications', [
            'prefs' => Channels::prefs($u) + ($u->isStaff() ? ['operator_bookings' => ($u->notification_prefs['operator_bookings'] ?? true) !== false] : []),
            'isStaff' => $u->isStaff(),
            'phone' => $u->phone,
            'email' => $u->email,
            'devices' => DeviceToken::query()->where('user_id', $u->id)->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['sms' => ['required', 'boolean'], 'whatsapp' => ['required', 'boolean'], 'push' => ['required', 'boolean'], 'operator_bookings' => ['sometimes', 'boolean']]);
        if (! $request->user()->isStaff()) {
            unset($d['operator_bookings']);
        }
        // Keep any other saved choices; only the ones on this form change.
        $request->user()->forceFill(['notification_prefs' => array_map('boolval', $d) + (array) ($request->user()->notification_prefs ?? [])])->save();
        activity()->performedOn($request->user())->causedBy($request->user())->withProperties($d)->log('notification preferences changed');

        return back()->with('success', 'Notification preferences saved.');
    }
}
