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
    /** What the money was for. */
    public const PURPOSES = ['booking' => 'Booking', 'balance' => 'Final-bill balance', 'wallet' => 'Wallet recharge'];

    /** How it was taken: online gateways, or at the station. */
    public const GATEWAYS = ['sabpaisa' => 'SabPaisa', 'razorpay' => 'Razorpay', 'cash' => 'Cash at station', 'upi_manual' => 'UPI to station'];

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
                TextColumn::make('purpose')->label('For')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => self::PURPOSES[$state] ?? ucfirst((string) $state))
                    ->description(fn (Payment $record) => $record->booking?->code ?? ($record->purpose === 'wallet' ? 'Trans EV wallet' : null)),
                TextColumn::make('booking.code')->label('Booking')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('company.trade_name')->label('Company')->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('amount')->money('INR')->sortable()->alignEnd()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('Total')->money('INR')),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::color($state))
                    ->description(fn (Payment $record) => $record->gateway_status),
                TextColumn::make('gateway')->label('Via')->formatStateUsing(fn (?string $state) => self::GATEWAYS[$state] ?? ucfirst((string) $state))
                    ->description(fn (Payment $record) => $record->collectedBy?->name ? 'by '.$record->collectedBy->name : null),
                TextColumn::make('method')->label('Method')->state(fn (Payment $record) => $record->methodLabel())->placeholder('—'),
                TextColumn::make('gateway_txn_id')->label('Gateway txn / UTR')->placeholder('—')->copyable()->toggleable(),
                TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y, h:i A', Station::TIMEZONE)->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('environment')->badge()->color(fn (string $state) => $state === 'production' ? 'success' : 'gray')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([Payment::SUCCESS => 'Success', Payment::PENDING => 'Pending', Payment::FAILED => 'Failed', Payment::CREATED => 'Created']),
                SelectFilter::make('purpose')->label('For')->options(self::PURPOSES)->multiple(),
                SelectFilter::make('gateway')->label('Via')->options(self::GATEWAYS)->multiple(),
                SelectFilter::make('company_id')->label('Company')->relationship('company', 'trade_name')->searchable()->preload()
                    ->visible(fn () => Tenancy::isPlatformAdmin()),
                \Filament\Tables\Filters\Filter::make('paid_between')->label('Paid between')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Paid from'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Paid until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('paid_at', '>=', \Carbon\Carbon::parse($d, Station::TIMEZONE)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('paid_at', '<=', \Carbon\Carbon::parse($d, Station::TIMEZONE)->endOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Paid from '.\Carbon\Carbon::parse($data['from'])->format('j M Y') : null,
                        ($data['until'] ?? null) ? 'Paid until '.\Carbon\Carbon::parse($data['until'])->format('j M Y') : null,
                    ])),
                SelectFilter::make('environment')->options(['production' => 'Production', 'staging' => 'Staging', 'test' => 'Test (Razorpay)', 'simulator' => 'Simulator', 'offline' => 'At the station']),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
