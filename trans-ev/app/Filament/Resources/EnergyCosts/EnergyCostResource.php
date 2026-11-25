<?php

namespace App\Filament\Resources\EnergyCosts;

use App\Models\StationEnergyCost;
use App\Services\ChargingSessionService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** Reports → Electricity costs (G1): each station's electricity rate per month, for the gross-profit estimate. */
class EnergyCostResource extends Resource
{
    protected static ?string $model = StationEnergyCost::class;

    protected static ?string $slug = 'electricity-costs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Electricity costs';

    protected static ?string $modelLabel = 'electricity cost';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('station:id,name,city')
            ->whereIn('station_id', ChargingSessionService::stations(auth()->user())->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('station_id')->label('Station')->required()->searchable()->preload()
                ->options(fn () => ChargingSessionService::stations(auth()->user())->orderBy('name')->get(['id', 'name', 'city'])->mapWithKeys(fn ($s) => [$s->id => "{$s->name}, {$s->city}"]))
                ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => ChargingSessionService::worksAt(auth()->user(), (int) $value) ? null : $fail('Pick one of your stations.')),
            DatePicker::make('month')->label('Month')->required()->native(false)->displayFormat('F Y')->closeOnDateSelection()
                ->default(fn () => CarbonImmutable::now('Asia/Kolkata')->subMonthNoOverflow()->startOfMonth())
                ->dehydrateStateUsing(fn ($state) => $state ? CarbonImmutable::parse($state)->startOfMonth()->toDateString() : null)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('station_id', $get('station_id')))
                ->validationMessages(['unique' => 'This station already has a cost for that month; edit it instead.'])
                ->helperText('Any day in the month; it is saved as the whole month.'),
            TextInput::make('cost_per_kwh')->label('Cost per kWh')->prefix('₹')->numeric()->minValue(0)->maxValue(100)->step('0.0001')->required()
                ->helperText('Energy charge per unit from the DISCOM bill, including electricity duty and surcharges.'),
            TextInput::make('fixed_charges')->label('Fixed / demand charges for the month')->prefix('₹')->numeric()->minValue(0)->maxValue(10000000)->default(0)
                ->helperText('Spread evenly over the days of the month in reports.'),
            TextInput::make('units_billed')->label('Units on the bill (kWh)')->numeric()->minValue(0)->helperText('Optional, for comparing with energy delivered to vehicles.'),
            TextInput::make('note')->maxLength(255)->placeholder('Bill number, DISCOM, anything useful'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('month')->date('M Y')->sortable(),
                TextColumn::make('station.name')->label('Station')->description(fn (StationEnergyCost $record) => $record->station?->city)->searchable(),
                TextColumn::make('cost_per_kwh')->label('₹ / kWh')->numeric(4)->alignEnd(),
                TextColumn::make('fixed_charges')->label('Fixed charges')->money('INR')->alignEnd(),
                TextColumn::make('units_billed')->label('Units billed')->numeric(1)->alignEnd()->placeholder('—'),
                TextColumn::make('note')->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('station_id')->label('Station')
                    ->options(fn () => ChargingSessionService::stations(auth()->user())->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No electricity costs yet')
            ->emptyStateDescription('Add each station’s rate per month from the electricity bill so reports can estimate gross profit.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEnergyCosts::route('/')];
    }
}
