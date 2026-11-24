<?php

namespace App\Filament\Resources\Coupons;

use App\Enums\BookingStatus;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Models\Coupon;
use App\Models\Station;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/** Coupons & discounts. Platform coupons are funded by Trans EV; company coupons by that operator. */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings & Payments';

    protected static ?string $navigationLabel = 'Coupons & offers';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        $paid = [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value, BookingStatus::Completed->value, BookingStatus::NoShow->value];
        $query = parent::getEloquentQuery()->with('company')
            ->withCount(['bookings as used_count' => fn (Builder $query) => $query->whereIn('status', $paid)])
            ->withSum(['bookings as discount_given' => fn (Builder $query) => $query->whereIn('status', $paid)], 'discount_amount');

        // Companies see only their own coupons (platform-funded coupons are managed by Trans EV).
        return Tenancy::isPlatformAdmin() ? $query : $query->where('company_id', Tenancy::companyId() ?? -1);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Coupon')->icon('heroicon-o-ticket')->columns(3)->columnSpanFull()->schema([
                TextInput::make('code')->required()->maxLength(40)->regex('/^[A-Za-z0-9_-]+$/')
                    ->unique(ignoreRecord: true)->disabledOn('edit')
                    ->helperText('Letters, numbers, - and _. Saved in capitals. Cannot be changed later.')
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : $state)
                    ->suffixAction(Action::make('generate')->icon('heroicon-m-sparkles')->tooltip('Generate a code')
                        ->visible(fn (string $operation) => $operation === 'create')
                        ->action(fn (Set $set) => $set('code', 'TEV'.strtoupper(Str::random(6))))),
                TextInput::make('name')->required()->maxLength(120)->notRegex('/[<>]/')->placeholder('Weekend DC offer')->columnSpan(2),
                Select::make('company_id')->label('Funded by')
                    ->options(fn () => \App\Models\Company::query()->where('status', 'active')->where('is_platform', false)->orderBy('trade_name')->pluck('trade_name', 'id')->all())
                    ->placeholder('Trans EV (platform, valid at every operator)')
                    ->visible(fn () => Tenancy::isPlatformAdmin())->disabledOn('edit')->live()
                    ->helperText('Platform coupons are paid for by Trans EV; operator coupons by that operator.'),
                Textarea::make('description')->rows(2)->maxLength(300)->columnSpanFull()
                    ->placeholder('Shown to customers with public offers, e.g. "20% off DC fast charging every weekend".'),
            ]),
            Section::make('Discount')->icon('heroicon-o-receipt-percent')->columns(4)->columnSpanFull()->schema([
                Select::make('type')->options(Coupon::TYPES)->required()->default('percent')->live()->native(false),
                TextInput::make('value')->label(fn (Get $get) => $get('type') === 'flat' ? 'Amount off' : 'Percent off')
                    ->required()->numeric()->minValue(0.01)
                    ->maxValue(fn (Get $get) => $get('type') === 'flat' ? 100000 : 100)
                    ->prefix(fn (Get $get) => $get('type') === 'flat' ? '₹' : null)
                    ->suffix(fn (Get $get) => $get('type') === 'flat' ? null : '%'),
                TextInput::make('max_discount')->label('Maximum discount')->numeric()->minValue(1)->prefix('₹')
                    ->visible(fn (Get $get) => $get('type') !== 'flat')->helperText('Optional cap'),
                TextInput::make('min_order')->label('Minimum order (before GST)')->numeric()->minValue(0)->prefix('₹'),
            ]),
            Section::make('Where & when')->icon('heroicon-o-map')->columns(4)->columnSpanFull()->schema([
                Select::make('station_ids')->label('Stations')->multiple()->searchable()->columnSpan(2)
                    ->options(fn (Get $get) => Tenancy::scope(Station::query())
                        ->when(filled($get('company_id')), fn (Builder $q) => $q->where('company_id', $get('company_id')))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('All stations')
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? array_map('intval', $state) : null),
                Select::make('charger_type')->label('Charger type')->options(['AC' => 'AC only', 'DC' => 'DC fast only'])->placeholder('Any charger'),
                Toggle::make('first_booking_only')->label('First booking only')->inline(false),
                DateTimePicker::make('starts_at')->label('Starts')->seconds(false)->timezone(Station::TIMEZONE)->native(false),
                DateTimePicker::make('ends_at')->label('Ends')->seconds(false)->timezone(Station::TIMEZONE)->native(false)->after('starts_at'),
                TextInput::make('usage_limit')->label('Total uses')->numeric()->integer()->minValue(1)->placeholder('Unlimited'),
                TextInput::make('per_user_limit')->label('Uses per customer')->numeric()->integer()->minValue(1)->default(1)->placeholder('Unlimited'),
            ]),
            Section::make('Visibility')->icon('heroicon-o-eye')->columns(2)->columnSpanFull()->schema([
                Toggle::make('is_active')->label('Active')->default(true)
                    ->helperText('Turn off to stop new bookings using it. Existing bookings keep their discount.'),
                Toggle::make('is_public')->label('Show as an offer on the booking page')
                    ->helperText('Off = private code you share yourself (SMS, social, partners).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->badge()->color('primary')->copyable()->searchable()->fontFamily('mono')
                    ->description(fn (Coupon $record) => $record->name),
                TextColumn::make('offer')->label('Offer')->state(fn (Coupon $record) => $record->headline())->wrap(),
                TextColumn::make('company.trade_name')->label('Funded by')->placeholder('Trans EV (platform)')
                    ->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (Coupon $record) => self::state($record))
                    ->color(fn (string $state) => match ($state) { 'Live' => 'success', 'Scheduled' => 'info', 'Used up' => 'warning', default => 'gray' }),
                TextColumn::make('validity')->label('Valid')
                    ->state(fn (Coupon $record) => ($record->starts_at?->setTimezone(Station::TIMEZONE)->format('d M') ?? 'Now').' – '.($record->ends_at?->setTimezone(Station::TIMEZONE)->format('d M Y') ?? 'No end')),
                TextColumn::make('used_count')->label('Used')->alignCenter()->sortable()
                    ->formatStateUsing(fn ($state, Coupon $record) => $state.($record->usage_limit ? ' / '.$record->usage_limit : '')),
                TextColumn::make('discount_given')->label('Discount given')->money('INR')->placeholder('₹0.00')->sortable(),
                TextColumn::make('is_public')->label('')->badge()->color('gray')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Public offer' : 'Private code'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_public')->label('Public offer'),
                SelectFilter::make('funding')->label('Funded by')->visible(fn () => Tenancy::isPlatformAdmin())
                    ->options(['platform' => 'Trans EV (platform)', 'operator' => 'Operators'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'platform' => $query->whereNull('company_id'), 'operator' => $query->whereNotNull('company_id'), default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggle')->label(fn (Coupon $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (Coupon $record) => $record->is_active ? 'heroicon-m-pause' : 'heroicon-m-play')
                    ->color(fn (Coupon $record) => $record->is_active ? 'gray' : 'success')
                    ->visible(fn (Coupon $record) => auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (Coupon $record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->emptyStateHeading('No coupons yet')
            ->emptyStateDescription('Create a code customers can enter at checkout, or a public offer shown on the booking page.');
    }

    /** Server-side clean-up: flat coupons have no cap, and stations must belong to the funding company (and to the editor). */
    public static function sanitise(array $data, ?Coupon $record = null): array
    {
        if (($data['type'] ?? null) === 'flat') {
            $data['max_discount'] = null;
        }
        $companyId = array_key_exists('company_id', $data) ? $data['company_id'] : $record?->company_id;
        if (filled($data['station_ids'] ?? null)) {
            $data['station_ids'] = Tenancy::scope(Station::query())
                ->when($companyId, fn (Builder $q) => $q->where('company_id', $companyId))
                ->whereIn('id', $data['station_ids'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?: null;
        }

        return $data;
    }

    public static function state(Coupon $c): string
    {
        return match (true) {
            ! $c->is_active => 'Inactive',
            $c->ends_at !== null && $c->ends_at->isPast() => 'Expired',
            $c->starts_at !== null && $c->starts_at->isFuture() => 'Scheduled',
            $c->usage_limit !== null && ($c->used_count ?? 0) >= $c->usage_limit => 'Used up',
            default => 'Live',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
