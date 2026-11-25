<?php

namespace App\Notifications;

use App\Models\ChargerWorkOrder;
use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Maintenance work order assigned to a technician or staff member (wording in Message templates). */
class WorkOrderUpdate extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public ChargerWorkOrder $workOrder, public string $template) {}

    public function messageKey(): string
    {
        return $this->template;
    }

    public function messageVars(object $notifiable): array
    {
        $w = $this->workOrder->loadMissing(['charger', 'station']);

        return [
            'name' => $notifiable->name ?? '',
            'number' => (string) $w->number,
            'title' => $w->title,
            'type' => ChargerWorkOrder::TYPES[$w->type] ?? $w->type,
            'priority' => ChargerWorkOrder::PRIORITIES[$w->priority] ?? $w->priority,
            'charger' => (string) $w->charger?->code,
            'station' => (string) $w->station?->name,
        ];
    }

    public function messageUrl(object $notifiable): ?string
    {
        return \App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('view', ['record' => $this->workOrder], panel: 'admin');
    }
}
