<?php

namespace App\Filament\Resources\Stations\RelationManagers;

use App\Enums\ChargerType;
use App\Models\Station;
use App\Models\Tariff;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tariffs are versioned, never edited: adding a tariff closes the previous one for the same scope.
 * The full price history stays available for invoices and audits.
 */
class TariffsRelationManager extends RelationManager
{
    protected static ?string $title = 'Pricing & GST';

    protected static string $relationship = 'tariffs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('tariffs.view') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('name')->required()->maxLength(100)->placeholder('DC Fast'),
            Select::make('charger_type')->label('Applies to')->options(ChargerType::class)->placeholder('All chargers'),
            Select::make('charger_id')->label('Or one charger')
                ->options(fn () => $this->getOwnerRecord()->chargers()->pluck('code', 'id'))->placeholder('—'),
            Section::make('Price (excluding GST)')->columns(4)->columnSpanFull()->schema([
                TextInput::make('rate_per_kwh')->label('Energy ₹/kWh')->numeric()->required()->minValue(0)->maxValue(200)->prefix('₹'),
                TextInput::make('session_fee')->label('Booking fee')->numeric()->required()->default(20)->minValue(0)->prefix('₹'),
                TextInput::make('idle_fee_per_min')->label('Idle fee / min')->numeric()->required()->default(2)->minValue(0)->prefix('₹'),
                TextInput::make('idle_grace_minutes')->label('Idle grace (min)')->numeric()->required()->default(10)->minValue(0),
            ]),
            Section::make('Peak / off-peak energy rates')->columnSpanFull()->collapsible()
                ->description('Optional. Minutes of a booking that fall inside a band are charged at the band rate; the rest at the standard rate above.')
                ->schema([
                    \Filament\Forms\Components\Repeater::make('time_bands')->hiddenLabel()->columns(5)->maxItems(6)->defaultItems(0)->reorderable(false)
                        ->addActionLabel('Add time band')
                        ->schema([
                            TextInput::make('name')->required()->maxLength(30)->placeholder('Peak'),
                            \Filament\Forms\Components\CheckboxList::make('days')->options(\App\Models\Tariff::DAYS)->columns(4)->columnSpan(2)
                                ->helperText('None ticked = every day'),
                            TextInput::make('from')->label('From')->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->placeholder('18:00'),
                            TextInput::make('to')->label('Until')->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->placeholder('22:00')
                                ->helperText('Earlier than “From” = crosses midnight'),
                            TextInput::make('rate_per_kwh')->label('₹/kWh')->numeric()->required()->minValue(0)->maxValue(200)->prefix('₹'),
                        ]),
                ]),
            Section::make('Parking')->columns(3)->columnSpanFull()->collapsible()->collapsed()
                ->description('Optional parking charge added to each booking (GST at the same rate).')
                ->schema([
                    TextInput::make('parking_fee')->label('Per booking')->numeric()->default(0)->minValue(0)->maxValue(5000)->prefix('₹'),
                    TextInput::make('parking_fee_per_hour')->label('Per hour of the slot')->numeric()->default(0)->minValue(0)->maxValue(1000)->prefix('₹'),
                    TextInput::make('parking_sac')->label('Parking SAC')->default('996743')->regex('/^\d{4,8}$/'),
                ]),
            Section::make('Tax')->columns(3)->columnSpanFull()->schema([
                TextInput::make('gst_rate')->label('GST rate')->numeric()->required()->default(18)->minValue(0)->maxValue(28)->suffix('%')
                    ->helperText('Charging is generally billed as a service at 18%. Confirm with your CA.'),
                TextInput::make('sac_code')->label('SAC code')->required()->default('998714')->regex('/^\d{4,8}$/'),
                DateTimePicker::make('effective_from')->required()->default(now())->seconds(false)->timezone(Station::TIMEZONE)
                    ->minDate(now()->subMinutes(5))
                    ->helperText('Cannot be in the past: bookings already priced under the old tariff stay unchanged.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('name')->weight('medium')
                    ->description(fn (Tariff $r) => $r->charger?->code ?? ($r->charger_type?->getLabel() ?? 'All chargers')),
                TextColumn::make('rate_per_kwh')->label('₹/kWh')->money('INR')
                    ->description(fn (Tariff $r) => collect($r->bandsSummary())->map(fn ($b) => "{$b['name']} {$b['from']}–{$b['to']} ₹{$b['rate']}")->implode(' · ') ?: null),
                TextColumn::make('incl')->label('Incl. GST')->state(fn (Tariff $r) => '₹'.$r->ratePerKwhInclGst())->color('gray'),
                TextColumn::make('session_fee')->label('Booking fee')->money('INR')
                    ->description(fn (Tariff $r) => (float) $r->parking_fee + (float) $r->parking_fee_per_hour > 0 ? 'Parking ₹'.$r->parking_fee.' + ₹'.$r->parking_fee_per_hour.'/h' : null),
                TextColumn::make('idle_fee_per_min')->label('Idle / min')->money('INR'),
                TextColumn::make('gst_rate')->label('GST')->suffix('%'),
                TextColumn::make('sac_code')->label('SAC'),
                TextColumn::make('effective_from')->dateTime('d M Y, h:i A', Station::TIMEZONE),
                TextColumn::make('effective_to')->dateTime('d M Y, h:i A', Station::TIMEZONE)->placeholder('Current')
                    ->badge(fn (Tariff $r) => $r->effective_to === null)->color(fn (Tariff $r) => $r->effective_to === null ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('current')->label('Current only')->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now())),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->headerActions([
                CreateAction::make()->label('New tariff')
                    ->visible(fn () => auth()->user()?->can('create', Tariff::class))
                    ->using(function (array $data) {
                        return DB::transaction(function () use ($data) {
                            $station = $this->getOwnerRecord();
                            $from = Carbon::parse($data['effective_from']);

                            if ($from->lt(now()->subMinutes(5))) {
                                throw \Illuminate\Validation\ValidationException::withMessages(['mountedActions.0.data.effective_from' => 'A new tariff cannot start in the past.']);
                            }

                            // Close the tariff currently covering the same scope (model by model, so each change is audit-logged).
                            $station->tariffs()
                                ->where('charger_id', $data['charger_id'] ?? null)
                                ->where('charger_type', $data['charger_type'] ?? null)
                                ->where('effective_from', '<', $from)
                                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                                ->lockForUpdate()->get()
                                ->each(fn (Tariff $old) => $old->update(['effective_to' => $from]));

                            return $station->tariffs()->create($data + ['created_by' => auth()->id()]);
                        });
                    }),
            ])
            ->recordActions([
                Action::make('end')->label('End now')->icon('heroicon-m-stop-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (Tariff $r) => $r->effective_to === null && auth()->user()?->can('end', $r))
                    ->action(function (Tariff $r) {
                        $r->update(['effective_to' => now()]);
                        Notification::make()->success()->title('Tariff ended')->send();
                    }),
            ]);
    }
}
