<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/** First-install website pages (Admin → Content → Website pages). Existing pages are never overwritten. */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['terms', 'Terms of service', 'The rules for using transevrecharge.com and Trans EV charging stations.', 'terms.html'],
            ['privacy', 'Privacy policy', 'How we collect, use and protect your personal data.', 'privacy.html'],
            ['refund-policy', 'Refund & cancellation policy', 'Plans change. Here is exactly how cancellations and refunds work.', 'refund-policy.html'],
        ] as [$slug, $title, $intro, $file]) {
            if (! Page::query()->where('slug', $slug)->exists()) {
                Page::query()->create([
                    'slug' => $slug, 'title' => $title, 'intro' => $intro, 'is_published' => true,
                    'body' => (string) file_get_contents(database_path('seeders/content/'.$file)),
                ]);
            }
        }
    }
}
