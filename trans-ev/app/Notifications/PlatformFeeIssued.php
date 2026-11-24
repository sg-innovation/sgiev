<?php

namespace App\Notifications;

use App\Filament\Resources\PlatformFees\PlatformFeeResource;
use App\Models\PlatformFeeStatement;
use App\Notifications\Concerns\TemplatedMessage;
use App\Services\GstDocumentPdf;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails an operator their monthly platform-fee invoice (wording from Message templates). */
class PlatformFeeIssued extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public PlatformFeeStatement $statement) {}

    public function messageKey(): string
    {
        return 'platform_fees.issued';
    }

    public function messageVars(object $notifiable): array
    {
        $s = $this->statement;

        return [
            'name' => $notifiable->name ?? '', 'company' => $s->company->trade_name, 'number' => (string) $s->number,
            'period' => $s->periodLabel(), 'total' => number_format((float) $s->total, 2), 'reimbursement' => number_format((float) $s->reimbursement_amount, 2),
            'net_payable' => number_format((float) $s->net_payable, 2), 'due_on' => $s->due_on?->format('j M Y') ?? '',
        ];
    }

    public function messageUrl(object $notifiable): ?string
    {
        return PlatformFeeResource::getUrl('index', panel: 'admin');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return Messages::mail($this->messageKey(), $this->messageVars($notifiable), $this->messageUrl($notifiable))
            ->attachData(app(GstDocumentPdf::class)->renderStatement($this->statement), $this->statement->filename(), ['mime' => 'application/pdf']);
    }
}
