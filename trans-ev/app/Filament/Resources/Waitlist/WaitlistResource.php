<?php

namespace App\Filament\Resources\Waitlist;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Station;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Bookings & Payments → Waiting list (C7): who is queuing at each station, offers made and outcomes. Read-only. */
class WaitlistResource extends Resource
{
    protected static ?string $model = WaitlistEntry::class;

    protected static ?string $slug = 'waiting-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings & Payments';

    protected static ?string $navigationLabel = 'Waiting list';

    protected static ?string $modelLabel = 'waiting-list entry';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('bookings.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'waiting')->count();

        return $n ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return Tenancy::scope(parent::getEloquentQuery()->with(['station', 'user', 'booking']));
    }

    public static function table(Table $table): Table
    {
        $svc = app(WaitlistService::class);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('station.name')->label('Station')->description(fn (WaitlistEntry $record) => $record->connector)->searchable(),
                TextColumn::make('date')->date('D, j M')->description(fn (WaitlistEntry $record) => Carbon::parse($record->window_from)->format('g:i A').' – '.Carbon::parse($record->window_to)->format('g:i A').' · '.$record->duration.' min'),
                TextColumn::make('customer.name')->label('Customer')->description(fn (WaitlistEntry $record) => ($record->vehicle['registration_no'] ?? '').' · '.($record->customer['phone'] ?? '')),
                TextColumn::make('position')->label('Place')->state(fn (WaitlistEntry $record) => $svc->position($record))->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => WaitlistEntry::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'waiting' => 'info', 'offered' => 'warning', 'booked' => 'success', default => 'gray' })
                    ->description(fn (WaitlistEntry $record) => $record->closed_reason),
                TextColumn::make('booking.code')->label('Offer / booking')->fontFamily('mono')->placeholder('—')
                    ->url(fn (WaitlistEntry $record) => $record->booking ? BookingResource::getUrl('view', ['record' => $record->booking]) : null)
                    ->description(fn (WaitlistEntry $record) => $record->offer_expires_at && $record->status === 'offered' ? 'Pay by '.$record->offer_expires_at->timezone(Station::TIMEZONE)->format('g:i A') : null),
                TextColumn::make('created_at')->label('Joined')->since(Station::TIMEZONE),
            ])
            ->filters([
                SelectFilter::make('status')->options(WaitlistEntry::STATUSES)->default('waiting'),
                SelectFilter::make('station_id')->label('Station')->options(fn () => Tenancy::scope(Station::query())->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            ])
            ->emptyStateHeading('Nobody is waiting')
            ->emptyStateDescription('Customers join from the booking page when the times they want are full.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWaitlist::route('/')];
    }
}
