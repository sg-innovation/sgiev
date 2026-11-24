<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receipt')->label('View / download receipt')->icon('heroicon-m-document-text')->color('gray')
                ->visible(fn (Payment $record) => $record->status === Payment::SUCCESS)
                ->url(fn (Payment $record) => route('bookings.receipt', $record->booking), shouldOpenInNewTab: true),
            Action::make('reconcile')->label('Check status with SabPaisa')->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn (Payment $record) => auth()->user()->can('reconcile', $record))
                ->action(function (Payment $record) {
                    $p = app(PaymentService::class)->reconcile($record);
                    Notification::make()->title('Status: '.$p->status.($p->gateway_status ? " ({$p->gateway_status})" : ''))->info()->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('refund')->label('Refund')->icon('heroicon-m-arrow-uturn-left')->color('danger')
                ->visible(fn (Payment $record) => auth()->user()->can('refund', $record)
                    && bccomp((string) ($record->paid_amount ?? $record->amount), $record->refundedAmount(), 2) > 0)
                ->requiresConfirmation()
                ->modalDescription('The amount is refunded to the customer’s original payment method through SabPaisa. This is logged and cannot be undone.')
                ->fillForm(fn (Payment $record) => ['amount' => bcsub((string) ($record->paid_amount ?? $record->amount), $record->refundedAmount(), 2)])
                ->schema([
                    TextInput::make('amount')->label('Amount (₹)')->required()->rule('decimal:0,2')->minValue(0.01),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(400),
                ])
                ->action(function (Payment $record, array $data) {
                    try {
                        $refund = app(RefundService::class)->refundPayment($record, number_format((float) $data['amount'], 2, '.', ''), $data['reason'], 'manual (staff)', auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                        return;
                    }
                    Notification::make()->title($refund->status === 'failed' ? 'Refund failed: '.$refund->failure_reason : 'Refund started')
                        ->color($refund->status === 'failed' ? 'danger' : 'success')->send();
                }),
        ];
    }
}
