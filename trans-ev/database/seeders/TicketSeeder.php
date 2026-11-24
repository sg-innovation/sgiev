<?php

namespace Database\Seeders;

use App\Models\TicketCategory;
use App\Models\TicketPriority;
use Illuminate\Database\Seeder;

/** Starting ticket priorities (with SLA targets) and categories. All editable in Admin → Support. */
class TicketSeeder extends Seeder
{
    public function run(): void
    {
        $p = [];
        foreach ([
            ['Low', 'low', 'gray', 72 * 60, 7 * 1440, true, false, 1],
            ['Normal', 'normal', 'info', 24 * 60, 3 * 1440, true, true, 2],
            ['High', 'high', 'warning', 4 * 60, 1440, true, false, 3],
            ['Urgent', 'urgent', 'danger', 60, 8 * 60, false, false, 4],
        ] as [$name, $slug, $color, $first, $resolve, $selectable, $default, $sort]) {
            $p[$slug] = TicketPriority::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name, 'color' => $color, 'first_response_minutes' => $first, 'resolution_minutes' => $resolve,
                'customer_selectable' => $selectable, 'is_default' => $default, 'sort' => $sort,
            ]);
        }

        foreach ([
            ['Booking or slot', 'booking', 'Problems with a booking, slot time or check-in.', 'customer', 'operator', true, 'normal'],
            ['Charger not working', 'charger-fault', 'The charger, connector or app session failed at the station.', 'customer', 'operator', true, 'high'],
            ['Payment or refund', 'payment-refund', 'Payment taken twice, refund not received, wrong amount.', 'all', 'platform', false, 'high'],
            ['Invoice or GST', 'invoice-gst', 'Invoice details, GSTIN, credit notes.', 'all', 'platform', false, 'normal'],
            ['Account and login', 'account', 'Login, email, phone number or account access.', 'all', 'platform', false, 'normal'],
            ['Partner onboarding', 'partner-onboarding', 'KYC, approval, payment setup or plan for partners.', 'partner', 'platform', false, 'normal'],
            ['Station listing', 'station-listing', 'Station approval, photos, prices or map location.', 'partner', 'platform', false, 'normal'],
            ['Feedback or suggestion', 'feedback', 'Ideas to make Trans EV better.', 'all', 'platform', false, 'low'],
            ['Something else', 'other', null, 'all', 'platform', false, 'normal'],
        ] as $i => [$name, $slug, $desc, $audience, $handled, $needsBooking, $priority]) {
            TicketCategory::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name, 'description' => $desc, 'audience' => $audience, 'handled_by' => $handled,
                'requires_booking' => $needsBooking, 'default_priority_id' => $p[$priority]->id, 'is_active' => true, 'sort' => $i + 1,
            ]);
        }
        // D2: contact-form topic → category (only where not set, so admin changes are kept).
        foreach (['booking' => 'booking', 'station' => 'charger-fault', 'payment' => 'payment-refund', 'feedback' => 'feedback', 'partner' => 'partner-onboarding', 'other' => 'other'] as $topic => $slug) {
            if (! TicketCategory::query()->where('contact_topic', $topic)->exists()) {
                TicketCategory::query()->where('slug', $slug)->whereNull('contact_topic')->update(['contact_topic' => $topic]);
            }
        }
    }
}
