<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
use App\Models\Station;
use App\Support\Tenancy;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function color(string $status): string
    {
        return match ($status) {
            Payment::SUCCESS => 'success',
            Payment::FAILED => 'danger',
            Payment::PENDING => 'warning',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('merchant_txn_id')->label('Order ID')->searchable()->copyable()->weight('medium')
                    ->description(fn (Payment $record) => $record->created_at->setTimezone(Station::TIMEZONE)->format('d M Y, h:i A')),
                TextColumn::make('booking.code')->label('Booking')->searchable(),
                TextColumn::make('company.trade_name')->label('Company')->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('amount')->money('INR')->sortable()->alignEnd(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::color($state))
                    ->description(fn (Payment $record) => $record->gateway_status),
                TextColumn::make('payment_mode')->label('Mode')->placeholder('—'),
                TextColumn::make('gateway_txn_id')->label('SabPaisa txn')->placeholder('—')->copyable()->toggleable(),
                TextColumn::make('environment')->badge()->color(fn (string $state) => $state === 'production' ? 'success' : 'gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([Payment::SUCCESS => 'Success', Payment::PENDING => 'Pending', Payment::FAILED => 'Failed', Payment::CREATED => 'Created']),
                SelectFilter::make('environment')->options(['production' => 'Production', 'staging' => 'Staging', 'simulator' => 'Simulator']),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
