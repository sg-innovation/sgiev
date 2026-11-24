<?php

namespace App\Support;

use App\Models\SiteSection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Website copy stored in the database (site_sections) and edited in Admin → Content → Site content.
 * SECTIONS describes each editable block (fields and their types) and its first-install text, which
 * SiteContentSeeder writes to the database. Text may contain live values in {braces}, filled in on the page:
 * {stations} {online} {cities} {chargers} {max_kw} {from_rate} {session_fee} {gst_rate} {idle_fee} {idle_grace}.
 */
final class SiteContent
{
    /** Icons editors can choose (names map to icons in the website code). */
    public const ICONS = [
        'zap' => 'Lightning', 'map-pinned' => 'Map pin', 'calendar-check' => 'Calendar', 'credit-card' => 'Card', 'receipt' => 'Receipt',
        'gauge' => 'Gauge', 'badge-rupee' => 'Rupee badge', 'file-text' => 'Document', 'shield-check' => 'Shield', 'leaf' => 'Leaf',
        'smartphone' => 'Phone', 'building' => 'Building', 'wifi' => 'Wi-Fi', 'target' => 'Target', 'heart-handshake' => 'Handshake heart',
        'users' => 'People', 'handshake' => 'Handshake', 'power' => 'Power', 'plug' => 'Plug', 'flame' => 'Flame', 'alert-triangle' => 'Warning',
        'info' => 'Info', 'clock' => 'Clock', 'car' => 'Car', 'battery' => 'Battery',
    ];

    public const TONES = ['volt' => 'Green', 'electric' => 'Blue', 'amber' => 'Amber', 'red' => 'Red'];

    /** Fields: text | textarea | list (of strings) | items (repeater with 'item' sub-fields). */
    public const SECTIONS = [
        // ───────── Home ─────────
        'home.seo' => ['page' => 'home', 'label' => 'Search engines', 'fields' => ['title' => 'text', 'description' => 'textarea'], 'default' => [
            'title' => 'EV charging stations – find, book & pay online',
            'description' => 'Find the nearest Trans EV charging station, book a slot and pay online. Fast DC and AC EV charging across India with GST invoices.',
        ]],
        'home.hero' => ['page' => 'home', 'label' => 'Hero', 'fields' => ['badge' => 'text', 'title_line1' => 'text', 'title_line2' => 'text', 'text' => 'textarea', 'search_placeholder' => 'text', 'bullets' => 'list'], 'default' => [
            'badge' => '{online} of {stations} stations live now · {cities} cities',
            'title_line1' => 'Charge faster.', 'title_line2' => 'Drive further.',
            'text' => 'India’s smart EV charging network. Find the nearest Trans EV charger, book your slot in seconds and pay online. Ultra-fast DC up to {max_kw} kW, with a GST invoice for every charge.',
            'search_placeholder' => 'City, area or PIN code',
            'bullets' => ['Live charger status', 'Secure UPI & card payments', 'GST tax invoice'],
        ]],
        'home.vehicles' => ['page' => 'home', 'label' => 'Compatible vehicles strip', 'fields' => ['lead' => 'text', 'items' => 'list'], 'default' => [
            'lead' => 'Works with all CCS2 & Type 2 EVs, including',
            'items' => ['Tata Nexon EV', 'Tata Punch EV', 'MG ZS EV', 'MG Windsor EV', 'Mahindra XUV400', 'Mahindra BE 6', 'Hyundai Creta Electric', 'BYD Atto 3', 'Kia EV6', 'Ather & Ola scooters'],
        ]],
        'home.steps' => ['page' => 'home', 'label' => 'How it works', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'text' => 'textarea', 'items' => ['icon' => 'icon', 'title' => 'text', 'text' => 'textarea']], 'default' => [
            'eyebrow' => 'How it works', 'title' => 'From empty to on-the-road in four steps', 'text' => 'No queues, no cash, no paperwork. Everything happens on your phone.',
            'items' => [
                ['icon' => 'map-pinned', 'title' => 'Find a charger', 'text' => 'Open the live map, share your location and see the nearest stations with real-time status, power and price.'],
                ['icon' => 'calendar-check', 'title' => 'Book your slot', 'text' => 'Choose a charger, date and time. Add your vehicle and accept the station rules in one tap.'],
                ['icon' => 'credit-card', 'title' => 'Pay securely', 'text' => 'UPI, cards, net-banking or wallets via a PCI-compliant gateway. Your slot is confirmed instantly.'],
                ['icon' => 'receipt', 'title' => 'Charge & get invoice', 'text' => 'Plug in, charge, and download your payment receipt and GST tax invoice from your account.'],
            ],
        ]],
        'home.chargers' => ['page' => 'home', 'label' => 'Charger types & pricing', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'text' => 'textarea', 'items' => ['name' => 'text', 'kind' => 'kind', 'rate' => 'rate', 'power' => 'text', 'connector' => 'text', 'time' => 'text', 'best' => 'text']], 'default' => [
            'eyebrow' => 'Chargers & pricing', 'title' => 'The right charger for every journey',
            'text' => 'Simple per-kWh pricing. A ₹{session_fee} booking fee per session and {gst_rate}% GST are added at checkout.',
            'items' => [
                ['name' => 'AC Slow', 'kind' => 'ac', 'rate' => 'acMin', 'power' => '3.3 – 7.4 kW', 'connector' => 'Type 2 · Bharat AC-001', 'time' => '6 – 10 hrs (full car)', 'best' => 'Two/three-wheelers, overnight top-ups'],
                ['name' => 'AC Fast', 'kind' => 'ac', 'rate' => 'acMax', 'power' => '22 kW', 'connector' => 'Type 2', 'time' => '2 – 4 hrs', 'best' => 'Shopping, office and dining stops'],
                ['name' => 'DC Fast', 'kind' => 'dc', 'rate' => 'dcMin', 'power' => '30 – 60 kW', 'connector' => 'CCS2', 'time' => '45 – 75 min', 'best' => 'City fast-charging, quick top-ups'],
                ['name' => 'DC Ultra-fast', 'kind' => 'dc', 'rate' => 'dcMax', 'power' => '120 – 180 kW', 'connector' => 'CCS2', 'time' => '20 – 35 min', 'best' => 'Highway trips and fleets'],
            ],
        ]],
        'home.calculator' => ['page' => 'home', 'label' => 'Cost calculator', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'text' => 'textarea', 'items' => ['icon' => 'icon', 'text' => 'text'], 'note' => 'textarea'], 'default' => [
            'eyebrow' => 'Cost calculator', 'title' => 'Know your cost before you plug in', 'text' => 'Move the sliders to estimate the energy, time and cost of your next charge, including GST.',
            'items' => [
                ['icon' => 'leaf', 'text' => 'Typically much cheaper per km than petrol or diesel.'],
                ['icon' => 'receipt', 'text' => 'The same numbers appear on your GST tax invoice.'],
                ['icon' => 'smartphone', 'text' => 'Track every session and download invoices from your account.'],
            ],
            'note' => 'Estimate only. Actual cost depends on the station tariff and the energy your vehicle draws.',
        ]],
        'home.featured' => ['page' => 'home', 'label' => 'Popular stations', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'link_label' => 'text'], 'default' => [
            'eyebrow' => 'Fastest hubs', 'title' => 'Popular charging stations', 'link_label' => 'View all on map',
        ]],
        'home.network' => ['page' => 'home', 'label' => 'Our network (cities)', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'text' => 'textarea'], 'default' => [
            'eyebrow' => 'Our network', 'title' => 'Charging in {cities} cities and growing', 'text' => 'From city centres to expressways, there is a Trans EV charger on your route.',
        ]],
        'home.features' => ['page' => 'home', 'label' => 'Why Trans EV', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'text' => 'textarea', 'items' => ['icon' => 'icon', 'title' => 'text', 'text' => 'textarea']], 'default' => [
            'eyebrow' => 'Why Trans EV', 'title' => 'Built for drivers. Ready for audits.', 'text' => 'Reliable hardware, honest pricing and paperwork that just works, for individuals and fleets alike.',
            'items' => [
                ['icon' => 'zap', 'title' => 'Ultra-fast DC charging', 'text' => 'CCS2 chargers up to {max_kw} kW at highway hubs. Most cars go from 20% to 80% in about 30 minutes.'],
                ['icon' => 'gauge', 'title' => 'Live availability', 'text' => 'See which chargers are online, offline or under maintenance before you leave home.'],
                ['icon' => 'calendar-check', 'title' => 'Guaranteed slots', 'text' => 'Book ahead and skip the queue. Your charger is held for you at the booked time.'],
                ['icon' => 'badge-rupee', 'title' => 'Transparent pricing', 'text' => 'Clear ₹/kWh rates with GST shown upfront. No surprise charges at the end of your session.'],
                ['icon' => 'file-text', 'title' => 'GST invoices', 'text' => 'Every booking comes with a tax invoice. Add your GSTIN to claim input tax credit for business travel.'],
                ['icon' => 'shield-check', 'title' => 'Safe, monitored sites', 'text' => 'CCTV, certified equipment, emergency stops and trained staff at every Trans EV hub.'],
            ],
        ]],
        'home.business' => ['page' => 'home', 'label' => 'Fleets & partners', 'fields' => ['items' => ['icon' => 'icon', 'tone' => 'tone', 'title' => 'text', 'text' => 'textarea', 'link_label' => 'text', 'link_url' => 'text']], 'default' => [
            'items' => [
                ['icon' => 'building', 'tone' => 'electric', 'title' => 'For fleets & businesses', 'text' => 'Centralised billing, GST invoices with your GSTIN, and priority booking at highway hubs for cabs, delivery and corporate fleets.', 'link_label' => 'Talk to our team', 'link_url' => '/contact'],
                ['icon' => 'wifi', 'tone' => 'volt', 'title' => 'Host a Trans EV charger', 'text' => 'Own a mall, hotel, office or highway site? Partner with us to install and operate EV chargers and earn from every session.', 'link_label' => 'Register your station', 'link_url' => '/partner'],
            ],
        ]],
        'home.faq' => ['page' => 'home', 'label' => 'FAQ', 'fields' => ['eyebrow' => 'text', 'title' => 'text', 'items' => ['q' => 'text', 'a' => 'textarea']], 'default' => [
            'eyebrow' => 'FAQ', 'title' => 'Frequently asked questions',
            'items' => [
                ['q' => 'How is the price calculated?', 'a' => 'You pay for the energy delivered (₹ per kWh) plus a small booking fee. GST is added at checkout and shown separately on your tax invoice.'],
                ['q' => 'Which cars and connectors are supported?', 'a' => 'DC fast chargers use CCS2, the standard for most new Indian EVs. AC chargers support Type 2, and selected stations have Bharat AC-001 / Type 6 sockets for two- and three-wheelers. Each station page lists its connectors.'],
                ['q' => 'Do I need to book in advance?', 'a' => 'Booking guarantees your charger at a set time. You can book minutes before you arrive, or days ahead for highway trips.'],
                ['q' => 'Can I cancel or reschedule?', 'a' => 'Yes. Refunds depend on how early you cancel; see our Refund & Cancellation Policy. If a station goes offline during your slot you get a full refund or a free reschedule.'],
                ['q' => 'Do I get a GST invoice?', 'a' => 'Every booking includes a payment receipt and a GST-compliant tax invoice. Add your GSTIN at checkout to claim input tax credit.'],
                ['q' => 'What happens if I stay plugged in after charging?', 'a' => 'An idle fee applies per minute after a short grace period, so chargers stay free for the next driver. The fee and grace period are shown on every station page.'],
                ['q' => 'Is my payment secure?', 'a' => 'Payments are processed by an RBI-authorised payment gateway. Trans EV never sees or stores your card or UPI details.'],
            ],
        ]],
        'home.cta' => ['page' => 'home', 'label' => 'Closing call to action', 'fields' => ['title' => 'text', 'text' => 'textarea', 'primary_label' => 'text', 'secondary_label' => 'text'], 'default' => [
            'title' => 'Ready to charge?', 'text' => 'Find the nearest Trans EV station and book your slot in under a minute.',
            'primary_label' => 'Chargers near me', 'secondary_label' => 'Create free account',
        ]],

        // ───────── About ─────────
        'about.header' => ['page' => 'about', 'label' => 'Page header', 'fields' => ['title' => 'text', 'intro' => 'textarea'], 'default' => [
            'title' => 'Powering India’s electric future', 'intro' => 'Trans EV builds and operates a reliable, transparent EV charging network for drivers, fleets and businesses.',
        ]],
        'about.values' => ['page' => 'about', 'label' => 'Values cards', 'fields' => ['items' => ['icon' => 'icon', 'tone' => 'tone', 'title' => 'text', 'text' => 'textarea']], 'default' => [
            'items' => [
                ['icon' => 'target', 'tone' => 'volt', 'title' => 'Our mission', 'text' => 'Make EV charging as simple as refuelling: easy to find, fairly priced and always working.'],
                ['icon' => 'leaf', 'tone' => 'electric', 'title' => 'Cleaner mobility', 'text' => 'Every kWh delivered through our network moves India closer to cleaner air and lower transport emissions.'],
                ['icon' => 'heart-handshake', 'tone' => 'amber', 'title' => 'Driver first', 'text' => 'Live status, guaranteed bookings, honest pricing and a GST invoice for every session.'],
            ],
        ]],
        'about.what' => ['page' => 'about', 'label' => 'What we do', 'fields' => ['title' => 'text', 'points' => 'list', 'items' => ['icon' => 'icon', 'title' => 'text', 'text' => 'text']], 'default' => [
            'title' => 'What we do',
            'points' => [
                'Fast DC (CCS2) and AC (Type 2, Bharat AC-001) charging hubs in cities and on highways',
                'Online slot booking and secure payments',
                'Fleet and business charging with centralised GST billing',
                'Site partnerships with malls, hotels, offices and highway operators',
            ],
            'items' => [
                ['icon' => 'users', 'title' => 'Drivers', 'text' => 'Cars, two- and three-wheelers'],
                ['icon' => 'building', 'title' => 'Businesses', 'text' => 'Fleets and corporates'],
                ['icon' => 'handshake', 'title' => 'Partners', 'text' => 'Site hosts and investors'],
                ['icon' => 'shield-check', 'title' => 'Compliance', 'text' => 'GST, DPDP and audit ready'],
            ],
        ]],

        // ───────── Pricing (rates and fees come from live tariffs) ─────────
        'pricing.header' => ['page' => 'pricing', 'label' => 'Page header', 'fields' => ['title' => 'text', 'intro' => 'textarea'], 'default' => [
            'title' => 'Simple, transparent pricing', 'intro' => 'Pay only for the energy you use. GST is always shown separately on your invoice.',
        ]],
        'pricing.facts' => ['page' => 'pricing', 'label' => 'Fees table', 'fields' => ['items' => ['label' => 'text', 'text' => 'textarea'], 'note' => 'textarea', 'button_label' => 'text'], 'default' => [
            'items' => [
                ['label' => 'Booking fee', 'text' => '₹{session_fee} per session'],
                ['label' => 'Idle fee', 'text' => '₹{idle_fee} per minute after a {idle_grace}-minute grace period once charging ends'],
                ['label' => 'GST', 'text' => '{gst_rate}% on the taxable value (SAC 998714), shown on your tax invoice'],
                ['label' => 'Payment methods', 'text' => 'UPI, debit/credit cards, net-banking and wallets'],
            ],
            'note' => 'Exact rates can vary by station and are always shown on the station page before you book.',
            'button_label' => 'See station prices',
        ]],

        // ───────── Safety ─────────
        'safety.header' => ['page' => 'safety', 'label' => 'Page header', 'fields' => ['title' => 'text', 'intro' => 'textarea'], 'default' => [
            'title' => 'Charge safely, every time', 'intro' => 'A few simple habits keep you, your vehicle and other drivers safe at the charger.',
        ]],
        'safety.tips' => ['page' => 'safety', 'label' => 'Safety tips', 'fields' => ['items' => ['icon' => 'icon', 'tone' => 'tone', 'title' => 'text', 'text' => 'textarea']], 'default' => [
            'items' => [
                ['icon' => 'power', 'tone' => 'volt', 'title' => 'Before you plug in', 'text' => 'Switch the vehicle off, apply the handbrake and check that the connector matches your vehicle (CCS2, Type 2 or Bharat AC-001).'],
                ['icon' => 'plug', 'tone' => 'electric', 'title' => 'Inspect the equipment', 'text' => 'Look for damaged cables, bent pins or burn marks. Never use damaged equipment; report it to staff or the helpline.'],
                ['icon' => 'flame', 'tone' => 'red', 'title' => 'No flames or smoking', 'text' => 'No smoking, lighters or open flames within 5 metres of any charger.'],
                ['icon' => 'alert-triangle', 'tone' => 'amber', 'title' => 'If something feels wrong', 'text' => 'Sparks, smoke or a burning smell? Stop charging, press the emergency stop and move away. Call the helpline.'],
                ['icon' => 'info', 'tone' => 'electric', 'title' => 'Weather & water', 'text' => 'Never charge while standing in water or with a wet charging inlet. Chargers are rated for rain, but use common sense.'],
                ['icon' => 'users', 'tone' => 'volt', 'title' => 'Children & pets', 'text' => 'Keep children and pets away from charging equipment and cables at all times.'],
            ],
        ]],

        // ───────── Contact ─────────
        'contact.header' => ['page' => 'contact', 'label' => 'Page header', 'fields' => ['title' => 'text', 'intro' => 'textarea'], 'default' => [
            'title' => 'We’re here to help', 'intro' => 'Questions about a booking, a refund, an invoice or a station? Send us a message or reach us directly.',
        ]],
        'contact.details' => ['page' => 'contact', 'label' => 'Contact details labels', 'fields' => ['phone_label' => 'text', 'whatsapp_label' => 'text', 'email_label' => 'text', 'office_label' => 'text', 'hours' => 'textarea'], 'default' => [
            'phone_label' => 'Call us (24×7 charging support)', 'whatsapp_label' => 'Chat on WhatsApp', 'email_label' => 'Email', 'office_label' => 'Registered office',
            'hours' => 'Office hours: Monday to Saturday, 9:30 AM – 6:30 PM IST. On-site charging help is available 24×7.',
        ]],
    ];

    public const PAGES = ['home' => 'Homepage', 'about' => 'About us', 'pricing' => 'Pricing', 'safety' => 'Charging safety', 'contact' => 'Contact'];

    /** @var array<string, array>|null */
    private static ?array $cache = null;

    /** All sections of a page: short key ("hero") => data. */
    public static function forPage(string $page): array
    {
        return collect(self::SECTIONS)->filter(fn ($d) => $d['page'] === $page)
            ->mapWithKeys(fn ($d, $key) => [substr($key, strlen($page) + 1) => self::get($key)])->all();
    }

    public static function get(string $key): array
    {
        $stored = self::stored()[$key] ?? null;

        return $stored ?? (self::SECTIONS[$key]['default'] ?? []);
    }

    private static function stored(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            self::$cache = Schema::hasTable('site_sections') ? SiteSection::query()->pluck('data', 'key')->all() : [];
        } catch (Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
