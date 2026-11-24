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
            foreach (['mail', 'sms', 'whatsapp', 'push'] as $channel) {
                if ($d = Messages::definition($key, $channel)) {
                    MessageTemplate::query()->firstOrCreate(['key' => $key, 'channel' => $channel], [
                        'subject' => $d['subject'] ?? null, 'body' => $d['body'],
                        'action_label' => $d['action_label'] ?? null, 'is_active' => true,
                    ]);
                }
            }
        }
        SiteContent::flush();
        Messages::flush();
    }
}
