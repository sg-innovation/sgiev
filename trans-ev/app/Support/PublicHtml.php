<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitiser for admin-authored HTML shown to the public (blog posts, station rules).
 * Stricter than Filament's editor sanitiser: no `style` or `class` attributes, so content cannot hide text
 * (e.g. a rule clause with display:none that customers "accept" without seeing) or overlay the page.
 * Links get rel="noopener noreferrer" and only http(s)/mailto/tel schemes.
 */
final class PublicHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): string
    {
        self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
                ->allowMediaSchemes(['https', 'http'])
                ->dropAttribute('class', '*')
                ->dropAttribute('style', '*')
                ->dropAttribute('id', '*')
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                ->allowAttribute('width', 'img')
                ->allowAttribute('height', 'img')
                ->withMaxInputLength(500000),
        );

        return self::$sanitizer->sanitize((string) $html);
    }

    /** Plain text for places that end up in <head> (titles, meta descriptions): no markup at all. */
    public static function text(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5))) ?? '');
    }
}
