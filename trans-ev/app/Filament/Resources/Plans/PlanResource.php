<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/** Operator subscription plans and their feature switches (platform super admin only). */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Plans & features';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan')->columns(3)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(60)->notRegex('/[<>]/')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation) {
                        if ($operation === 'create' && blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')->required()->maxLength(60)->alphaDash()->unique(ignoreRecord: true)->disabledOn('edit'),
                TextInput::make('price_monthly')->label('Price per month (excl. GST)')->numeric()->minValue(0)->maxValue(1000000)->required()->default(0)->prefix('₹'),
                TextInput::make('commission_percent')->label('Commission on net sales')->numeric()->minValue(0)->maxValue(50)->default(0)->suffix('%')
                    ->helperText('Billed monthly to operators on this plan.'),
                TextInput::make('per_booking_fee')->label('Fee per paid booking')->numeric()->minValue(0)->maxValue(1000)->default(0)->prefix('₹'),
                TextInput::make('max_stations')->label('Station limit')->numeric()->integer()->minValue(1)->maxValue(100000)
                    ->helperText('Leave empty for unlimited.'),
                TextInput::make('sort')->numeric()->integer()->default(10),
                Toggle::make('is_active')->label('Available')->default(true)->inline(false),
                Toggle::make('is_default')->label('Default for new partners')->inline(false)
                    ->helperText('Only one plan can be the default. Turning this on clears the others.'),
                Textarea::make('description')->rows(2)->maxLength(300)->columnSpanFull(),
            ]),
            Section::make('Features')->description('Switch modules on or off for every company on this plan. Changes apply immediately. The platform company always has every feature.')->columnSpanFull()->schema([
                CheckboxList::make('features')->hiddenLabel()->options(Plan::FEATURES)->columns(2)->bulkToggleable()
                    ->required()->in(array_keys(Plan::FEATURES)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('companies'))
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->weight('semibold')->description(fn (Plan $record) => $record->description),
                TextColumn::make('price_monthly')->label('Per month')->money('INR')
                    ->description(fn (Plan $record) => rtrim(rtrim((string) $record->commission_percent, '0'), '.').'% commission'.((float) $record->per_booking_fee > 0 ? ' + ₹'.$record->per_booking_fee.'/booking' : '')),
                TextColumn::make('max_stations')->label('Stations')->placeholder('Unlimited')->alignCenter(),
                TextColumn::make('feature_count')->label('Features')->badge()->color('gray')
                    ->state(fn (Plan $record) => count($record->features ?? []).' of '.count(Plan::FEATURES)),
                TextColumn::make('companies_count')->label('Companies')->alignCenter(),
                IconColumn::make('is_default')->label('Default')->boolean()->alignCenter(),
                IconColumn::make('is_active')->label('Available')->boolean()->alignCenter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /** Keep a single default plan. */
    public static function enforceSingleDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::query()->whereKeyNot($plan->id)->where('is_default', true)->get()->each(fn (Plan $p) => $p->update(['is_default' => false]));
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
