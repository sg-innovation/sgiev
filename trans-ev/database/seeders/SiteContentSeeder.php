<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\SiteSection;
use App\Support\Messages;
use App\Support\SiteContent;
use Illuminate\Database\Seeder;

/** First-install website copy and message templates. Existing rows (edited in admin) are never overwritten. */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SiteContent::SECTIONS as $key => $def) {
            SiteSection::query()->firstOrCreate(['key' => $key], ['data' => $def['default']]);
        }
        foreach (Messages::TEMPLATES as $key => $def) {
            foreach (['mail', 'sms'] as $channel) {
                if (isset($def[$channel])) {
                    MessageTemplate::query()->firstOrCreate(['key' => $key, 'channel' => $channel], [
                        'subject' => $def[$channel]['subject'] ?? null, 'body' => $def[$channel]['body'],
                        'action_label' => $def[$channel]['action_label'] ?? null, 'is_active' => true,
                    ]);
                }
            }
        }
        SiteContent::flush();
        Messages::flush();
    }
}
