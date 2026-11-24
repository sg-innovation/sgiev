<?php

namespace App\Filament\Resources\Stations\RelationManagers;

use App\Enums\ChargerType;
use App\Enums\ConnectorType;
use App\Enums\StationStatus;
use App\Filament\Resources\Stations\Actions\ChangeStatusAction;
use App\Models\Charger;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class ChargersRelationManager extends RelationManager
{
    protected static string $relationship = 'chargers';

    protected static ?string $title = 'Chargers';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('code')->required()->maxLength(30)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('station_id', $this->getOwnerRecord()->id))
                ->dehydrateStateUsing(fn (?string $state) => Str::upper($state)),
            TextInput::make('name')->maxLength(255)->placeholder('DC 60 kW #1'),
            TextInput::make('oem')->label('Manufacturer')->maxLength(255),
            Select::make('type')->options(ChargerType::class)->required(),
            TextInput::make('power_kw')->label('Power (kW)')->numeric()->required()->minValue(1)->maxValue(500),
            TextInput::make('serial_no')->label('Serial number')->maxLength(60),
            TextInput::make('model')->label('Model')->maxLength(80),
            \Filament\Schemas\Components\Section::make('Electrical specification')->icon('heroicon-o-bolt')->columns(4)->columnSpanFull()
                ->description('Printed on receipts and invoices and shown to customers on the station page.')
                ->schema([
                    Select::make('input_voltage_v')->label('Supply voltage')->options([230 => '230 V', 400 => '400 V', 415 => '415 V', 440 => '440 V']),
                    Select::make('phases')->label('Phases')->options([1 => 'Single-phase', 3 => 'Three-phase']),
                    TextInput::make('max_current_a')->label('Max current')->numeric()->minValue(1)->maxValue(1000)->suffix('A'),
                    TextInput::make('standard')->label('Standard')->maxLength(60)
                        ->datalist(['IEC 61851-1 / IEC 62196-2', 'IEC 61851-23 / IEC 62196-3 (CCS2)', 'Bharat AC-001', 'Bharat DC-001 (GB/T)', 'CHAdeMO', 'IS 17017']),
                    TextInput::make('output_voltage_min_v')->label('DC output from')->numeric()->minValue(50)->maxValue(1500)->suffix('V')
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => in_array($get('type'), ['DC', ChargerType::DC], true)),
                    TextInput::make('output_voltage_max_v')->label('DC output up to')->numeric()->minValue(50)->maxValue(1500)->suffix('V')->gte('output_voltage_min_v')
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => in_array($get('type'), ['DC', ChargerType::DC], true)),
                    TextInput::make('firmware_version')->label('Firmware')->maxLength(40),
                    \Filament\Forms\Components\DatePicker::make('commissioned_on')->label('Commissioned on')->maxDate(now()),
                ]),
            \Filament\Schemas\Components\Section::make('Energy meter')->icon('heroicon-o-calculator')->columns(2)->columnSpanFull()->collapsed()
                ->description('The meter that measures billed energy (needed for legal-metrology audits).')
                ->schema([
                    TextInput::make('meter_serial')->label('Meter serial number')->maxLength(60),
                    Select::make('meter_accuracy_class')->label('Accuracy class')->options(['Class 0.2S' => 'Class 0.2S', 'Class 0.5S' => 'Class 0.5S', 'Class 1' => 'Class 1', 'Class 2' => 'Class 2']),
                ]),
            \Filament\Schemas\Components\Section::make('Hardware link (OCPP)')->columns(2)->columnSpanFull()->collapsed()
                ->description('Leave empty for chargers without OCPP: status and sessions are then updated manually by staff.')
                ->schema([
                    TextInput::make('ocpp_identity')->label('Charge point identity (chargeBoxId)')->maxLength(100)
                        ->unique(ignoreRecord: true)->regex('/^[A-Za-z0-9._:-]+$/'),
                    Select::make('ocpp_protocol')->label('Protocol')->options(['1.6J' => 'OCPP 1.6J', '2.0.1' => 'OCPP 2.0.1']),
                ]),
            \Filament\Forms\Components\Repeater::make('connectorPoints')->label('Connectors (guns / sockets)')
                ->relationship()
                ->orderColumn('connector_no')
                ->minItems(1)->maxItems(8)->defaultItems(1)
                ->columns(3)->columnSpanFull()
                ->itemLabel(fn (array $state) => 'Gun '.($state['connector_no'] ?? '').' '.(ConnectorType::tryFrom((string) ($state['type'] ?? ''))?->getLabel() ?? ''))
                ->schema([
                    Select::make('type')->label('Connector type')->options(ConnectorType::options())->required(),
                    TextInput::make('max_kw')->label('Max kW (if lower than charger)')->numeric()->minValue(1)->maxValue(500),
                ])
                ->helperText('Each connector is booked separately. Removing a connector keeps its booking history.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class]))
            ->columns([
                TextColumn::make('code')->weight('medium')->description(fn (Charger $r) => $r->name),
                TextColumn::make('type')->badge(),
                TextColumn::make('power_kw')->label('kW')->numeric(1)->sortable()
                    ->description(fn (Charger $r) => $r->electricalSummary()),
                TextColumn::make('connector_points_count')->label('Guns')->counts('connectorPoints')->alignCenter(),
                TextColumn::make('connectors')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => ConnectorType::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('ocpp')->label('Hardware')->badge()->state(fn (Charger $r) => $r->ocppState())
                    ->color(fn (string $state) => match ($state) { 'connected' => 'success', 'disconnected' => 'danger', default => 'gray' }),
                TextColumn::make('status')->badge()->description(fn (Charger $r) => $r->status_reason),
                TextColumn::make('oem')->label('Manufacturer')->toggleable(),
            ])
            ->filters([TrashedFilter::make()])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['connectors' => []]),
            ])
            ->recordActions([
                ChangeStatusAction::make(),
                EditAction::make(),
                DeleteAction::make()->label('Archive'),
                RestoreAction::make(),
            ]);
    }

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->chargers()->where('status', StationStatus::Online)->count().'/'.$ownerRecord->chargers()->count();
    }
}
