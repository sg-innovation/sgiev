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
            Action::make('checkIn')->label('Check in')->icon('heroicon-m-qr-code')->color('success')
                ->visible(fn (Booking $record) => auth()->user()->can('checkIn', $record) && $record->status->value === 'confirmed')
                ->requiresConfirmation()
                ->modalDescription(fn (Booking $record) => 'Confirm the vehicle '.($record->vehicle_snapshot['registration_no'] ?? '').' is at charger '.$record->charger?->code.'.'
                    .(($b = app(\App\Services\CheckInService::class)->blocker($record)) ? ' '.$b : ''))
                ->schema([\Filament\Forms\Components\TextInput::make('note')->label('Note (optional)')->maxLength(255)])
                ->action(function (Booking $record, array $data) {
                    try {
                        app(\App\Services\CheckInService::class)->checkIn($record, 'manual', auth()->user(), null, $data['note'] ?? null);
                        Notification::make()->success()->title('Checked in')->send();
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                }),
            Action::make('retryTrueUp')->label('Retry final-bill refund')->icon('heroicon-m-arrow-path')->color('warning')
                ->visible(fn (Booking $record) => in_array($record->trueup_status, ['refund_failed', 'refund_pending'], true) && auth()->user()->can('payments.refund')
                    && \App\Support\Tenancy::owns($record->company_id))
                ->requiresConfirmation()
                ->modalDescription(fn (Booking $record) => 'Refund ₹'.ltrim((string) $record->trueup_amount, '-').' for energy not delivered.')
                ->action(function (Booking $record) {
                    app(\App\Services\TrueUpService::class)->refund($record);
                    $record->refresh()->trueup_status === 'refunded'
                        ? Notification::make()->success()->title('Refund started')->send()
                        : Notification::make()->danger()->title('Refund failed again')->body('Check the payment gateway status.')->send();
                }),
            Action::make('reschedule')->label('Move booking')->icon('heroicon-m-calendar-days')->color('gray')
                ->visible(fn (Booking $record) => $record->status->value === 'confirmed' && $record->start_at->isFuture() && auth()->user()->can('reschedule', $record))
                ->modalDescription('Move to another time or charger at this station (same length). The customer is emailed the new time; the price stays the same.')
                ->schema([
                    \Filament\Forms\Components\Select::make('date')->label('Date')->required()->live()
                        ->options(fn () => collect(app(\App\Services\AvailabilityService::class)->bookableDates())->mapWithKeys(fn ($d) => [$d => \Illuminate\Support\Carbon::parse($d, \App\Models\Station::TIMEZONE)->format('D, j M')])->all())
                        ->default(fn (Booking $record) => $record->start_at->timezone(\App\Models\Station::TIMEZONE)->toDateString())
                        ->afterStateUpdated(fn ($set) => $set('slot', null)),
                    \Filament\Forms\Components\Select::make('slot')->label('Charger and start time')->required()->searchable()
                        ->options(function (Booking $record, $get) {
                            if (! $get('date')) {
                                return [];
                            }
                            $out = [];
                            foreach (app(\App\Services\RescheduleService::class)->options($record->loadMissing('station'), $get('date')) as $o) {
                                foreach ($o['slots'] as $s) {
                                    if (! $s['current']) {
                                        $out[$o['label']][$o['connectorId'].'|'.$s['start']] = $s['label'];
                                    }
                                }
                            }

                            return $out;
                        }),
                    \Filament\Forms\Components\TextInput::make('reason')->label('Reason (shown to the customer)')->required()->maxLength(255)->placeholder('Charger under repair'),
                ])
                ->action(function (Booking $record, array $data) {
                    [$connectorId, $start] = explode('|', $data['slot'], 2);
                    try {
                        $b = app(\App\Services\RescheduleService::class)->reschedule($record, \App\Models\Connector::query()->findOrFail($connectorId), \Illuminate\Support\Carbon::parse($start), auth()->user(), true, $data['reason']);
                        Notification::make()->success()->title('Booking moved to '.$b->start_at->timezone(\App\Models\Station::TIMEZONE)->format('D, j M, g:i A'))->send();
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                }),
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
