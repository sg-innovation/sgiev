<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Page;
use App\Models\Station;
use Illuminate\Support\Str;

/**
 * Renders a database page for the website: fills placeholders from live data (company details, business rules),
 * sanitises the HTML, and splits it into sections at each <h2> for the table of contents.
 */
final class PageContent
{
    /** Placeholders editors can use in page text. */
    public const PLACEHOLDERS = [
        '{{company_legal_name}}' => 'Legal name of the platform company',
        '{{company_trade_name}}' => 'Brand name',
        '{{company_address}}' => 'Registered address',
        '{{company_gstin}}' => 'GSTIN',
        '{{support_email}}' => 'Support email',
        '{{support_phone}}' => 'Support phone / WhatsApp',
        '{{booking_hold_minutes}}' => 'Slot hold while paying (Business rules)',
        '{{no_show_minutes}}' => 'No-show window (Business rules)',
        '{{refund_tiers_table}}' => 'Refund table from the Trans EV standard cancellation policy (put it on its own line)',
    ];

    public static function render(Page $page): array
    {
        $html = self::fill($page->body);
        $html = PublicHtml::clean($html);

        $sections = [];
        $parts = preg_split('/<h2[^>]*>(.*?)<\/h2>/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $lead = trim(array_shift($parts) ?? '');
        for ($i = 0; $i < count($parts); $i += 2) {
            $title = trim(strip_tags($parts[$i]));
            $sections[] = ['id' => Str::slug(preg_replace('/^\d+\.\s*/', '', $title)) ?: 'section-'.($i / 2 + 1), 'title' => $title, 'html' => trim($parts[$i + 1] ?? '')];
        }

        return [
            'title' => $page->title,
            'intro' => $page->intro,
            'version' => $page->version,
            'updated' => ($page->published_at ?? $page->updated_at)?->setTimezone(Station::TIMEZONE)->format('j F Y'),
            'lead' => $lead,
            'sections' => $sections,
        ];
    }

    public static function fill(string $html): string
    {
        $c = Company::query()->where('is_platform', true)->first();
        $e = fn (?string $v) => e((string) $v);
        $address = $c ? collect([$c->address_line1, $c->address_line2, "{$c->city}, {$c->state} {$c->pincode}"])->filter()->implode(', ') : '';

        $map = [
            '{{company_legal_name}}' => $e($c?->legal_name),
            '{{company_trade_name}}' => $e($c?->trade_name),
            '{{company_address}}' => $e($address),
            '{{company_gstin}}' => $e($c?->gstin),
            '{{support_email}}' => $e($c?->support_email ?: $c?->email),
            '{{support_phone}}' => $e($c?->support_phone ?: $c?->phone),
            '{{booking_hold_minutes}}' => (string) BusinessRules::int('booking.hold_minutes'),
            '{{no_show_minutes}}' => (string) BusinessRules::int('booking.no_show_minutes'),
        ];
        $html = preg_replace('/<p>\s*\{\{refund_tiers_table\}\}\s*<\/p>/i', self::refundTable(), $html);
        $html = str_replace('{{refund_tiers_table}}', self::refundTable(), $html);

        return strtr($html, $map);
    }

    private static function refundTable(): string
    {
        $policy = \App\Models\CancellationPolicy::platformDefault();
        $rows = collect($policy?->sortedTiers() ?? [])->map(fn ($t) => '<tr><td>'.e($t['label']).'</td><td>'
            .((int) $t['refund_percent'] > 0 ? '<strong>'.(int) $t['refund_percent'].'%</strong> of the amount paid' : 'No refund')
            .((float) $t['fee'] > 0 ? ', less a ₹'.e(rtrim(rtrim($t['fee'], '0'), '.')).' cancellation fee' : '').'</td></tr>')->implode('');
        $ns = (int) ($policy?->no_show_refund_percent ?? 0);
        $noShow = '<tr><td>No-show (charging not started within '.BusinessRules::int('booking.no_show_minutes').' minutes of the start time)</td><td>'
            .($ns > 0 ? '<strong>'.$ns.'%</strong> of the amount paid' : 'No refund').'</td></tr>';
        $note = '<p>Some operators set their own policy for their stations. The policy that applies is shown before you pay and on your booking.</p>';

        return '<table><thead><tr><th>When you cancel</th><th>Refund</th></tr></thead><tbody>'.$rows.$noShow.'</tbody></table>'.$note;
    }
}
