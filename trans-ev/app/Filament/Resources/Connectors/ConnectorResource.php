<?php

namespace App\Filament\Resources\Connectors;

use App\Enums\ConnectorLiveStatus;
use App\Enums\Role;
use App\Enums\StationStatus;
use App\Filament\Resources\Connectors\Pages\ListConnectors;
use App\Models\Connector;
use App\Models\Station;
use App\Services\ConnectorStatusService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Connector status board: every gun with its live (OCPP-style) and admin status.
 * Manual updates here; once a charger is linked by OCPP, live status comes from the hardware.
 */
class ConnectorResource extends Resource
{
    protected static ?string $model = Connector::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Connector status';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('stations.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['charger.station'])
            ->whereHas('charger.station', function (Builder $s) use ($user) {
                Tenancy::scope($s);
                if ($user && $user->hasRole(Role::StationManager->value) && ! $user->hasAnyRole(Role::companyWide())) {
                    $s->whereHas('managers', fn ($m) => $m->whereKey($user->getKey()));
                }
            });

        return $query;
    }

    private static function canChange(Connector $c): bool
    {
        return auth()->user()?->can('changeStatus', $c->charger->station) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('charger_id')
            ->poll('30s')
            ->columns([
                TextColumn::make('charger.station.name')->label('Station')->searchable()->limit(30)
                    ->description(fn (Connector $record) => $record->charger->station->city),
                TextColumn::make('charger.code')->label('Charger')->searchable()
                    ->description(fn (Connector $record) => "{$record->charger->type->value} {$record->charger->power_kw} kW"),
                TextColumn::make('connector_no')->label('Gun')->prefix('#')->alignCenter(),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('live_status')->label('Live')->badge()
                    ->description(fn (Connector $record) => $record->live_status_at?->setTimezone(Station::TIMEZONE)->format('d M, h:i A')),
                TextColumn::make('status')->label('Availability')->badge(),
                TextColumn::make('source')->label('Updates')->badge()->color('gray')
                    ->state(fn (Connector $record) => $record->charger->ocpp_identity ? 'OCPP' : 'Manual'),
            ])
            ->filters([
                SelectFilter::make('live_status')->label('Live status')->options(ConnectorLiveStatus::class)->multiple(),
                SelectFilter::make('status')->label('Availability')->options(StationStatus::class),
                SelectFilter::make('station')->label('Station')
                    ->options(fn () => Tenancy::scope(Station::query())->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $data['value'] ? $query->whereHas('charger', fn ($c) => $c->where('station_id', $data['value'])) : $query),
            ])
            ->recordActions([
                Action::make('live')->label('Live status')->icon('heroicon-m-bolt')->color('gray')
                    ->visible(fn (Connector $record) => static::canChange($record))
                    ->fillForm(fn (Connector $record) => ['status' => $record->live_status->value, 'note' => $record->live_note])
                    ->schema([
                        Select::make('status')->options(ConnectorLiveStatus::class)->required(),
                        TextInput::make('note')->maxLength(255)->placeholder('e.g. cable damaged, technician called'),
                    ])
                    ->action(function (Connector $record, array $data) {
                        app(ConnectorStatusService::class)->setLive($record, ConnectorLiveStatus::from($data['status']), 'admin', $data['note'] ?? null);
                        Notification::make()->success()->title('Live status updated')->send();
                    }),
                Action::make('availability')->label('Availability')->icon('heroicon-m-power')->color('gray')
                    ->visible(fn (Connector $record) => static::canChange($record))
                    ->fillForm(fn (Connector $record) => ['status' => $record->status->value])
                    ->schema([
                        Select::make('status')->options(StationStatus::class)->required(),
                        TextInput::make('note')->label('Reason')->maxLength(255),
                    ])
                    ->action(function (Connector $record, array $data) {
                        app(ConnectorStatusService::class)->setAdmin($record, StationStatus::from($data['status']), $data['note'] ?? null);
                        Notification::make()->success()->title('Availability updated')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListConnectors::route('/')];
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereIn('live_status', [ConnectorLiveStatus::Faulted->value, ConnectorLiveStatus::Unavailable->value])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Faulted or offline connectors';
    }
}
