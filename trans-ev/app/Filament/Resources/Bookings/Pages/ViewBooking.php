<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receipt')->label('View / download receipt')->icon('heroicon-m-document-text')->color('gray')
                ->visible(fn (Booking $record) => $record->payments()->where('status', 'success')->exists())
                ->url(fn (Booking $record) => route('bookings.receipt', $record), shouldOpenInNewTab: true),
            Action::make('noShow')->label('Mark no-show')->icon('heroicon-m-user-minus')->color('warning')
                ->visible(fn (Booking $record) => auth()->user()->can('staffCancel', $record) && $record->status->value === 'confirmed'
                    && now()->gte($record->start_at->copy()->addMinutes(\App\Support\BusinessRules::int('booking.no_show_minutes'))))
                ->requiresConfirmation()
                ->modalDescription(fn (Booking $record) => 'The customer did not start charging. They get the no-show refund agreed at checkout: '
                    .(int) (\App\Services\Payments\RefundService::policyOf($record)['no_show_refund_percent'] ?? 0).'% of the amount paid.')
                ->action(function (Booking $record) {
                    try {
                        app(\App\Services\Payments\RefundService::class)->markNoShow($record, auth()->user());
                        Notification::make()->success()->title('Marked as no-show')->send();
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                }),
            Action::make('cancel')
                ->label('Cancel booking')
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->visible(fn (Booking $record) => auth()->user()->can('staffCancel', $record) && in_array($record->status->value, ['held', 'confirmed'], true))
                ->requiresConfirmation()
                ->modalDescription('Paid bookings cancelled by the operator are refunded in full to the customer’s original payment method.')
                ->schema([Textarea::make('reason')->label('Reason (shown to the customer)')->required()->minLength(5)->maxLength(255)])
                ->action(function (Booking $record, array $data) {
                    app(\App\Services\Payments\RefundService::class)->cancel($record, 'Cancelled by operator: '.$data['reason'], auth()->user(), true);
                    Notification::make()->success()->title('Booking cancelled')->send();
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
