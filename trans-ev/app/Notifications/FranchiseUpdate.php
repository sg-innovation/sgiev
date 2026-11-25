<?php

namespace App\Notifications;

use App\Models\Franchise;
use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

/** Franchise application received, approved or rejected, and commission paid (wording in Message templates). */
class FranchiseUpdate extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public Franchise $franchise, public string $template, public array $vars = []) {}

    public function messageKey(): string
    {
        return $this->template;
    }

    public function messageVars(object $notifiable): array
    {
        return [
            'name' => $notifiable instanceof AnonymousNotifiable ? $this->franchise->contact_name : ($notifiable->name ?? ''),
            'franchise' => $this->franchise->name,
            'code' => (string) $this->franchise->code,
            'region' => (string) ($this->franchise->region ?? $this->franchise->city ?? ''),
            'percent' => rtrim(rtrim((string) $this->franchise->commission_percent, '0'), '.'),
            'reason' => (string) ($this->franchise->review_note ?? ''),
        ] + $this->vars;
    }

    public function messageUrl(object $notifiable): ?string
    {
        return $notifiable instanceof AnonymousNotifiable
            ? url('/franchise')
            : \App\Filament\Resources\Franchises\FranchiseResource::getUrl('edit', ['record' => $this->franchise], panel: 'admin');
    }
}
