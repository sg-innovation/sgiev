<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Booking;
use App\Models\Station;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestBookings extends TableWidget
{
    use DashboardData;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest bookings';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::scopedBookings()->with(['station', 'charger'])->latest('created_at'))
            ->paginated([5])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('code')->weight('medium'),
                TextColumn::make('station.name')->label('Station')->limit(30),
                TextColumn::make('start_at')->label('Slot (IST)')
                    ->formatStateUsing(fn (Booking $record) => $record->start_at->setTimezone(Station::TIMEZONE)->format('d M, h:i A')),
                TextColumn::make('customer_snapshot.name')->label('Customer'),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_amount')->label('Amount')->money('INR')->alignEnd(),
            ])
            ->recordActions([
                Action::make('open')->label('View')->icon('heroicon-m-eye')->color('gray')
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
