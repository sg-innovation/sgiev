<?php

namespace App\Filament\Resources\WorkOrders;

use App\Models\Charger;
use App\Models\ChargerWorkOrder;
use App\Models\User;
use App\Services\ChargingSessionService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Network → Maintenance: charger work orders (faults, repairs, preventive service, inspections, calibration). */
class WorkOrderResource extends Resource
{
    protected static ?string $model = ChargerWorkOrder::class;

    protected static ?string $slug = 'maintenance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Maintenance';

    protected static ?string $modelLabel = 'work order';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['charger:id,code,station_id', 'station:id,name,city', 'assignee:id,name'])
            ->whereIn('station_id', ChargingSessionService::stations(auth()->user())->select('id'));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereIn('status', ChargerWorkOrder::ACTIVE)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getEloquentQuery()->whereIn('status', ChargerWorkOrder::ACTIVE)->where('priority', 'urgent')->exists() ? 'danger' : 'warning';
    }

    /** Staff of the charger's company who can be given the job. */
    public static function assignees(?int $stationId): array
    {
        $companyId = $stationId ? \App\Models\Station::query()->whereKey($stationId)->value('company_id') : null;

        return User::query()->whereNull('blocked_at')->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->permission(['maintenance.manage', 'sessions.manage'])->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('station_id')->label('Station')->required()->live()->searchable()->preload()->dehydrated(false)
                ->options(fn () => ChargingSessionService::stations(auth()->user())->orderBy('name')->get(['id', 'name', 'city'])->mapWithKeys(fn ($s) => [$s->id => "{$s->name}, {$s->city}"]))
                ->afterStateUpdated(fn ($set) => $set('charger_id', null)),
            Select::make('charger_id')->label('Charger')->required()->live()
                ->options(fn (Get $get) => $get('station_id') ? Charger::query()->where('station_id', $get('station_id'))->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} · {$c->type->value} {$c->power_kw} kW"]) : [])
                ->afterStateUpdated(fn ($set) => $set('connector_id', null)),
            Select::make('connector_id')->label('Connector (optional)')->placeholder('Whole charger')
                ->options(fn (Get $get) => $get('charger_id') ? \App\Models\Connector::query()->where('charger_id', $get('charger_id'))->orderBy('connector_no')->get()->mapWithKeys(fn ($c) => [$c->id => 'Connector '.$c->connector_no.' · '.$c->type->value]) : []),
            Select::make('type')->options(ChargerWorkOrder::TYPES)->required()->default('fault'),
            TextInput::make('title')->required()->maxLength(160)->columnSpanFull()->placeholder('e.g. Connector 2 not locking, error E21'),
            Textarea::make('description')->rows(3)->maxLength(3000)->columnSpanFull(),
            Select::make('priority')->options(ChargerWorkOrder::PRIORITIES)->required()->default('normal'),
            DateTimePicker::make('due_at')->label('Fix by')->seconds(false)->timezone('Asia/Kolkata'),
            Select::make('assigned_to')->label('Assign to')->searchable()->options(fn (Get $get) => static::assignees($get('station_id') ? (int) $get('station_id') : null)),
            TextInput::make('vendor')->label('Vendor / service partner')->maxLength(120),
            Toggle::make('take_offline')->label('Take the charger out of service until this is done')->columnSpanFull()
                ->helperText('Sets the charger to Maintenance so it cannot be booked. It goes back online when the last such work order is done or cancelled.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Work order')->columnSpan(2)->columns(2)->schema([
                TextEntry::make('title')->columnSpanFull()->weight('semibold'),
                TextEntry::make('description')->columnSpanFull()->placeholder('—')->prose(),
                TextEntry::make('type')->formatStateUsing(fn ($state) => ChargerWorkOrder::TYPES[$state] ?? $state),
                TextEntry::make('priority')->badge()->formatStateUsing(fn ($state) => ChargerWorkOrder::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => ['urgent' => 'danger', 'high' => 'warning', 'low' => 'gray'][$state] ?? 'info'),
                TextEntry::make('charger.code')->label('Charger')->formatStateUsing(fn ($state, ChargerWorkOrder $record) => $state.' · '.$record->station?->name),
                TextEntry::make('connector.connector_no')->label('Connector')->placeholder('Whole charger'),
                TextEntry::make('vendor')->placeholder('—'),
                TextEntry::make('took_offline')->label('Out of service')->formatStateUsing(fn ($state) => $state ? 'Yes, while open' : 'No'),
                TextEntry::make('resolution')->columnSpanFull()->placeholder('—')->prose(),
                TextEntry::make('parts_used')->label('Parts used')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Progress')->columnSpan(1)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => ChargerWorkOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['done' => 'success', 'cancelled' => 'gray', 'on_hold' => 'warning', 'in_progress' => 'primary'][$state] ?? 'info'),
                TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Nobody yet'),
                TextEntry::make('reporter.name')->label('Reported by')->placeholder('—'),
                TextEntry::make('opened_at')->label('Opened')->dateTime('j M Y, g:i A', 'Asia/Kolkata'),
                TextEntry::make('due_at')->label('Fix by')->dateTime('j M Y, g:i A', 'Asia/Kolkata')->placeholder('—'),
                TextEntry::make('started_at')->label('Started')->dateTime('j M Y, g:i A', 'Asia/Kolkata')->placeholder('—'),
                TextEntry::make('completed_at')->label('Closed')->dateTime('j M Y, g:i A', 'Asia/Kolkata')->placeholder('—'),
                TextEntry::make('downtime_minutes')->label('Downtime')->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? null : intdiv((int) $state, 60).' h '.((int) $state % 60).' min'),
                TextEntry::make('cost')->label('Cost')->state(fn (ChargerWorkOrder $record) => $record->labour_cost || $record->parts_cost ? '₹'.number_format((float) $record->totalCost(), 2).' (labour ₹'.number_format((float) $record->labour_cost, 2).', parts ₹'.number_format((float) $record->parts_cost, 2).')' : null)->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->weight('semibold')->description(fn (ChargerWorkOrder $record) => \Illuminate\Support\Str::limit($record->title, 60)),
                TextColumn::make('charger.code')->label('Charger')->description(fn (ChargerWorkOrder $record) => $record->station?->name),
                TextColumn::make('type')->formatStateUsing(fn ($state) => ChargerWorkOrder::TYPES[$state] ?? $state)->toggleable(),
                TextColumn::make('priority')->badge()->formatStateUsing(fn ($state) => ChargerWorkOrder::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => ['urgent' => 'danger', 'high' => 'warning', 'low' => 'gray'][$state] ?? 'info'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ChargerWorkOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['done' => 'success', 'cancelled' => 'gray', 'on_hold' => 'warning', 'in_progress' => 'primary'][$state] ?? 'info')
                    ->description(fn (ChargerWorkOrder $record) => $record->took_offline && $record->isActive() ? 'Charger out of service' : null),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('—'),
                TextColumn::make('opened_at')->label('Opened')->since()->sortable()->dateTimeTooltip('j M Y, g:i A', 'Asia/Kolkata'),
                TextColumn::make('downtime_minutes')->label('Downtime')->placeholder('—')->alignEnd()->toggleable()
                    ->formatStateUsing(fn ($state) => $state === null ? null : intdiv((int) $state, 60).'h '.((int) $state % 60).'m'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ChargerWorkOrder::STATUSES)->multiple()->default(ChargerWorkOrder::ACTIVE),
                SelectFilter::make('station_id')->label('Station')->options(fn () => ChargingSessionService::stations(auth()->user())->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('type')->options(ChargerWorkOrder::TYPES),
                SelectFilter::make('priority')->options(ChargerWorkOrder::PRIORITIES),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No work orders')
            ->emptyStateDescription('Log faults, repairs and preventive service per charger. Taking a charger out of service stops new bookings on it.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkOrders::route('/'),
            'create' => Pages\CreateWorkOrder::route('/create'),
            'view' => Pages\ViewWorkOrder::route('/{record}'),
        ];
    }
}
