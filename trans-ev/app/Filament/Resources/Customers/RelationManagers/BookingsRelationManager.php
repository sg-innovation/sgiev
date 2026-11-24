<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Booking;
use App\Models\Station;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => CustomerResource::scopeBookings($query)->with(['station']))
            ->defaultSort('start_at', 'desc')
            ->columns([
                TextColumn::make('code')->weight('medium')->searchable()->copyable(),
                TextColumn::make('station.name')->label('Station')->description(fn (Booking $record) => $record->station?->city),
                TextColumn::make('start_at')->label('Slot')->dateTime('d M Y, h:i A', Station::TIMEZONE)->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_amount')->label('Total')->money('INR')->alignEnd(),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
