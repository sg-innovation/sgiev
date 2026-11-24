<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Station;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('start_at', 'desc')
            ->columns([
                TextColumn::make('code')->searchable()->copyable()->weight('medium')
                    ->description(fn (Booking $r) => $r->created_at->setTimezone(Station::TIMEZONE)->format('d M, h:i A')),
                TextColumn::make('station.name')->label('Station')->searchable()->limit(28)
                    ->description(fn (Booking $r) => $r->charger->code),
                TextColumn::make('start_at')->label('Slot (IST)')->sortable()
                    ->formatStateUsing(fn (Booking $r) => $r->start_at->setTimezone(Station::TIMEZONE)->format('d M Y'))
                    ->description(fn (Booking $r) => $r->start_at->setTimezone(Station::TIMEZONE)->format('h:i A').' – '.$r->end_at->setTimezone(Station::TIMEZONE)->format('h:i A')),
                TextColumn::make('customer_snapshot.name')->label('Customer')->searchable(query: fn (Builder $query, string $search) => $query->where('customer_snapshot->name', 'like', "%{$search}%"))
                    ->description(fn (Booking $r) => $r->vehicle_snapshot['registration_no'] ?? null),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_amount')->label('Amount')->money('INR')->sortable()->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')->options(BookingStatus::class)->multiple(),
                SelectFilter::make('station_id')->label('Station')->relationship('station', 'name')->searchable()->preload(),
                Filter::make('slot_date')
                    ->schema([DatePicker::make('from')->label('Slot from'), DatePicker::make('until')->label('Slot until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('start_at', '>=', \Illuminate\Support\Carbon::parse($v, Station::TIMEZONE)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('start_at', '<=', \Illuminate\Support\Carbon::parse($v, Station::TIMEZONE)->endOfDay()->utc()))),
            ])
            ->recordActions([
                ViewAction::make(),
                \Filament\Actions\Action::make('receipt')->label('Receipt')->icon('heroicon-m-document-text')->color('gray')
                    ->visible(fn (Booking $record) => in_array($record->status->value, ['confirmed', 'checked_in', 'completed'], true))
                    ->url(fn (Booking $record) => route('bookings.receipt', $record), shouldOpenInNewTab: true),
            ])
            ->toolbarActions([]);
    }
}
