<?php

namespace App\Filament\Resources\NotificationLog\Pages;

use App\Filament\Resources\NotificationLog\NotificationLogResource;
use App\Models\NotificationDelivery;
use App\Services\Messaging\Channels;
use Filament\Resources\Pages\ListRecords;

class ListNotificationLog extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;

    protected static ?string $title = 'Notification log';

    public function getSubheading(): ?string
    {
        $live = collect(['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'push' => 'Push'])
            ->map(fn ($label, $c) => $label.': '.(Channels::configured($c) ? 'live' : (app()->environment('local', 'testing') ? 'simulated' : 'not set up')))->implode(' · ');
        $failed = NotificationDelivery::query()->where('status', 'failed')->where('created_at', '>', now()->subDay())->count();

        return "Providers — {$live}. Failed in the last 24 h: {$failed}. Set keys in Settings → Integrations; wording and template IDs in Content → Message templates.";
    }
}
