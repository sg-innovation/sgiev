<?php

namespace App\Notifications;

use App\Filament\Resources\PlatformFees\PlatformFeeResource;
use App\Models\PlatformFeeStatement;
use App\Services\GstDocumentPdf;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails an operator their monthly platform-fee invoice (wording from Message templates). */
class PlatformFeeIssued extends Notification
{
    use Queueable;

    public function __construct(public PlatformFeeStatement $statement) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled('platform_fees.issued') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->statement;

        return Messages::mail('platform_fees.issued', [
            'name' => $notifiable->name ?? '', 'company' => $s->company->trade_name, 'number' => (string) $s->number,
            'period' => $s->periodLabel(), 'total' => number_format((float) $s->total, 2), 'reimbursement' => number_format((float) $s->reimbursement_amount, 2),
            'net_payable' => number_format((float) $s->net_payable, 2), 'due_on' => $s->due_on?->format('j M Y') ?? '',
        ], PlatformFeeResource::getUrl('index', panel: 'admin'))
            ->attachData(app(GstDocumentPdf::class)->renderStatement($s), $s->filename(), ['mime' => 'application/pdf']);
    }
}
