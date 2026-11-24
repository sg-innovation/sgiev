<?php

namespace App\Filament\Resources\Customers;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Station;
use App\Models\User;
use App\Support\IndianStates;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Customers (people who book charging). Platform admins see every customer;
 * a partner company only sees customers who have booked at its stations, and the
 * counts/spend shown are limited to that company's bookings.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings & Payments';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $modelLabel = 'customer';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public const PAID = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed];

    /** Restrict a bookings query to what the current admin may see (company, and station for managers). */
    public static function scopeBookings(Builder $query): Builder
    {
        Tenancy::scope($query, 'bookings.company_id');
        $user = auth()->user();
        if ($user && $user->hasRole(Role::StationManager->value) && ! $user->hasAnyRole(Role::companyWide())) {
            $query->whereIn('bookings.station_id', $user->stations()->pluck('stations.id'));
        }

        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        $paid = array_map(fn ($s) => $s->value, self::PAID);
        $query = parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('name', Role::staff()))
            ->withCount([
                'vehicles',
                'bookings as bookings_count' => fn (Builder $query) => static::scopeBookings($query)->whereIn('status', $paid),
            ])
            ->withSum(['bookings as spent' => fn (Builder $query) => static::scopeBookings($query)->whereIn('status', $paid)], 'total_amount')
            ->withMax(['bookings as last_booking_at' => fn (Builder $query) => static::scopeBookings($query)], 'start_at');

        if (! Tenancy::isPlatformAdmin()) {
            $query->whereHas('bookings', fn (Builder $query) => static::scopeBookings($query));
        }

        return $query;
    }

    // Customer access is decided here, not by the staff-oriented UserPolicy.
    public static function getViewAnyAuthorizationResponse(): Response
    {
        return (auth()->user()?->can('bookings.view') ?? false) ? Response::allow() : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::getViewAnyAuthorizationResponse()->allowed() && static::getEloquentQuery()->whereKey($record->getKey())->exists()
            ? Response::allow() : Response::denyAsNotFound();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('phone')->searchable()->placeholder('—')->copyable(),
                TextColumn::make('vehicles_count')->label('Vehicles')->alignCenter()->sortable(),
                TextColumn::make('bookings_count')->label('Paid bookings')->alignCenter()->sortable(),
                TextColumn::make('spent')->label('Spent')->money('INR')->sortable()->placeholder('₹0.00'),
                TextColumn::make('last_booking_at')->label('Last booking')->dateTime('d M Y', Station::TIMEZONE)->sortable()->placeholder('Never'),
                IconColumn::make('email_verified_at')->label('Verified')->boolean()->alignCenter()
                    ->state(fn (User $record) => $record->email_verified_at !== null),
                TextColumn::make('status')->badge()
                    ->state(fn (User $record) => $record->blocked_at ? 'Blocked' : 'Active')
                    ->color(fn (string $state) => $state === 'Blocked' ? 'danger' : 'success'),
                TextColumn::make('created_at')->label('Joined')->date('d M Y')->sortable()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('has_bookings')->label('Has paid bookings')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('bookings', fn (Builder $query) => static::scopeBookings($query)->whereIn('status', array_map(fn ($s) => $s->value, self::PAID))),
                        false: fn (Builder $query) => $query->whereDoesntHave('bookings', fn (Builder $query) => static::scopeBookings($query)->whereIn('status', array_map(fn ($s) => $s->value, self::PAID))),
                    ),
                TernaryFilter::make('email_verified_at')->label('Email verified')->nullable(),
                TernaryFilter::make('blocked_at')->label('Blocked')->nullable(),
                Filter::make('recent')->label('Joined in the last 30 days')
                    ->query(fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(30))),
            ])
            ->recordActions([
                ViewAction::make(),
                static::blockAction(),
                static::unblockAction(),
            ])
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('Customers appear here once they register (platform) or book at your stations (partners).');
    }

    public static function blockAction(): Action
    {
        return Action::make('block')->icon('heroicon-m-no-symbol')->color('danger')->requiresConfirmation()
            ->visible(fn (User $record) => $record->blocked_at === null && Tenancy::platformCan('customers.block'))
            ->modalDescription('The customer is signed out and can no longer log in or book. Existing bookings and invoices are kept.')
            ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(300)])
            ->action(function (User $record, array $data) {
                $record->forceFill(['blocked_at' => now()])->save();
                activity()->performedOn($record)->withProperties(['reason' => $data['reason']])->log('customer blocked');
                Notification::make()->success()->title('Customer blocked')->send();
            });
    }

    public static function unblockAction(): Action
    {
        return Action::make('unblock')->icon('heroicon-m-check-circle')->color('success')->requiresConfirmation()
            ->visible(fn (User $record) => $record->blocked_at !== null && Tenancy::platformCan('customers.block'))
            ->action(function (User $record) {
                $record->forceFill(['blocked_at' => null])->save();
                activity()->performedOn($record)->log('customer unblocked');
                Notification::make()->success()->title('Customer unblocked')->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')->icon('heroicon-o-user')->columnSpanFull()->schema([
                Grid::make(4)->schema([
                    TextEntry::make('name')->weight('semibold'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('phone')->placeholder('—')->copyable(),
                    TextEntry::make('state_code')->label('State')->formatStateUsing(fn (?string $state) => $state ? IndianStates::name($state) : null)->placeholder('—'),
                    TextEntry::make('gstin')->label('GSTIN (B2B invoices)')->placeholder('—'),
                    IconEntry::make('verified')->label('Email verified')->boolean()->state(fn (User $record) => $record->email_verified_at !== null),
                    TextEntry::make('created_at')->label('Joined')->dateTime('d M Y, h:i A', Station::TIMEZONE),
                    TextEntry::make('blocked_at')->label('Blocked')->dateTime('d M Y, h:i A', Station::TIMEZONE)->placeholder('No')->color('danger'),
                ]),
            ]),
            Section::make('Activity')->icon('heroicon-o-chart-bar')->columnSpanFull()->schema([
                Grid::make(4)->schema([
                    TextEntry::make('bookings_count')->label('Paid bookings')->size('lg')->weight('bold'),
                    TextEntry::make('spent')->label('Total spent (incl. GST)')->money('INR')->placeholder('₹0.00')->size('lg')->weight('bold'),
                    TextEntry::make('vehicles_count')->label('Vehicles')->size('lg')->weight('bold'),
                    TextEntry::make('last_booking_at')->label('Last booking')->dateTime('d M Y, h:i A', Station::TIMEZONE)->placeholder('Never'),
                ]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BookingsRelationManager::class,
            RelationManagers\VehiclesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }
}
