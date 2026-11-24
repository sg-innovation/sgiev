<?php

namespace App\Filament\Resources\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Bookings\Schemas\BookingInfolist;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only booking register. Bookings are created only through the customer booking flow. */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings & Payments';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function infolist(Schema $schema): Schema
    {
        return BookingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = \App\Support\Tenancy::scope(parent::getEloquentQuery()->with(['station', 'charger', 'user', 'company', 'connectorPoint', 'payments', 'refunds', 'invoice', 'creditNotes']));
        $user = auth()->user();

        if ($user && $user->hasRole(Role::StationManager->value) && ! $user->hasAnyRole(Role::companyWide())) {
            $query->whereIn('station_id', $user->stations()->pluck('stations.id'));
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
            ->whereBetween('start_at', [now(\App\Models\Station::TIMEZONE)->startOfDay()->utc(), now(\App\Models\Station::TIMEZONE)->endOfDay()->utc()])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Confirmed bookings today';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code'];
    }
}
