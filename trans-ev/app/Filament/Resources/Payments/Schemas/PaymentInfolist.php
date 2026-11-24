<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use App\Models\Station;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $tz = Station::TIMEZONE;

        return $schema->columns(3)->components([
            Section::make('Payment')->columnSpan(2)->columns(3)->schema([
                TextEntry::make('merchant_txn_id')->label('Order ID')->copyable()->weight('bold'),
                TextEntry::make('status')->badge()->color(fn (string $state) => PaymentsTable::color($state)),
                TextEntry::make('gateway_status')->label('Gateway status')->placeholder('—'),
                TextEntry::make('amount')->money('INR'),
                TextEntry::make('paid_amount')->money('INR')->placeholder('—'),
                TextEntry::make('payment_mode')->label('Mode')->placeholder('—'),
                TextEntry::make('gateway_txn_id')->label('SabPaisa transaction')->copyable()->placeholder('—'),
                TextEntry::make('bank_txn_id')->label('Bank reference')->placeholder('—'),
                TextEntry::make('bank_rrn')->label('RRN')->placeholder('—'),
                TextEntry::make('created_at')->label('Started')->dateTime('d M Y, h:i:s A', $tz),
                TextEntry::make('paid_at')->label('Paid')->dateTime('d M Y, h:i:s A', $tz)->placeholder('—'),
                TextEntry::make('verified_via')->label('Verified by')->placeholder('—'),
                TextEntry::make('failure_reason')->label('Note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Booking')->columnSpan(1)->schema([
                TextEntry::make('booking.code')->label('Booking')
                    ->url(fn (Payment $record) => BookingResource::getUrl('view', ['record' => $record->booking])),
                TextEntry::make('booking.status')->label('Booking status')->badge(),
                TextEntry::make('company.trade_name')->label('Paid to'),
                TextEntry::make('environment')->badge(),
            ]),
            Section::make('Refunds')->columnSpanFull()->schema([
                RepeatableEntry::make('refunds')->hiddenLabel()->columns(5)->placeholder('No refunds')->schema([
                    TextEntry::make('amount')->money('INR'),
                    TextEntry::make('status')->badge()->color(fn (string $state) => match ($state) { 'success' => 'success', 'failed' => 'danger', default => 'warning' }),
                    TextEntry::make('policy_rule')->label('Rule'),
                    TextEntry::make('gateway_refund_id')->label('Refund ID')->placeholder('—'),
                    TextEntry::make('created_at')->label('Requested')->dateTime('d M Y, h:i A', $tz),
                ]),
            ]),
            Section::make('Gateway event log (append-only)')->columnSpanFull()->collapsed()->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label('Time')->dateTime('d M, h:i:s A', $tz),
                    TextEntry::make('source'),
                    TextEntry::make('event')->placeholder('—'),
                    TextEntry::make('signature_valid')->label('Signature')
                        ->formatStateUsing(fn ($state) => $state === null ? 'n/a' : ($state ? 'valid' : 'INVALID'))
                        ->color(fn ($state) => $state === false ? 'danger' : 'gray'),
                ]),
            ]),
        ]);
    }
}
