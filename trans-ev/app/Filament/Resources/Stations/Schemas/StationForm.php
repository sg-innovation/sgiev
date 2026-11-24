<?php

namespace App\Filament\Resources\Stations\Schemas;

use App\Models\OperatingHour;
use App\Support\IndianStates;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StationForm
{
    public static function configure(Schema $schema): Schema
    {
        // Staff with only stations.hours (station managers) may edit opening hours; everything else is read-only.
        // Disabled fields are never saved by Filament, so this is enforced server-side too.
        $locked = fn () => ! (auth()->user()?->can('stations.manage') ?? false);

        return $schema->components([
            Tabs::make('station')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Details')->icon('heroicon-m-information-circle')->schema([
                    Grid::make(3)->disabled($locked)->schema([
                        TextInput::make('name')->required()->maxLength(255)->columnSpan(2)->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation) {
                                if ($operation === 'create' && blank($get('slug'))) {
                                    $set('slug', Str::slug($state.'-'.$get('city')));
                                }
                            }),
                        TextInput::make('code')->label('Station code')->required()->maxLength(20)
                            ->unique(ignoreRecord: true)->placeholder('DEL-CP')
                            ->dehydrateStateUsing(fn (?string $state) => Str::upper($state)),
                        TextInput::make('slug')->label('URL slug')->required()->maxLength(255)->unique(ignoreRecord: true)
                            ->helperText('transevrecharge.com/stations/{slug}')->columnSpan(2),
                        Select::make('company_id')->label('Operating company')->relationship('company', 'trade_name')->required()
                            ->searchable()->preload()
                            ->visible(fn () => \App\Support\Tenancy::isPlatformAdmin())
                            ->disabledOn('edit')
                            ->helperText('Invoices for this station are issued in this company’s name and GSTIN.'),
                        Textarea::make('description')->rows(3)->columnSpanFull(),
                        TagsInput::make('amenities')->suggestions(fn () => array_values(\App\Models\Lookup::options('amenity')))->columnSpanFull(),
                        Toggle::make('is_published')->label('Visible on website')->default(true),
                    ]),
                ]),
                Tab::make('Location')->icon('heroicon-m-map-pin')->schema([
                    Grid::make(3)->disabled($locked)->schema([
                        TextInput::make('address_line1')->label('Address')->required()->maxLength(255)->columnSpan(2),
                        TextInput::make('landmark')->maxLength(255),
                        TextInput::make('address_line2')->label('Address line 2')->maxLength(255)->columnSpan(2),
                        TextInput::make('city')->required()->maxLength(100),
                        Select::make('state_code')->label('State / UT')->options(IndianStates::options())->searchable()->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('state', IndianStates::name($state))),
                        TextInput::make('state')->required()->readOnly()->dehydrated(),
                        TextInput::make('pincode')->required()->regex('/^[1-9][0-9]{5}$/')->length(6),
                        TextInput::make('latitude')->required()->numeric()->minValue(6)->maxValue(37.5)->step('0.0000001')
                            ->helperText('India: 6 to 37.5'),
                        TextInput::make('longitude')->required()->numeric()->minValue(68)->maxValue(97.5)->step('0.0000001')
                            ->helperText('India: 68 to 97.5'),
                        TextInput::make('map_link')->label('Check on map')->dehydrated(false)->readOnly()
                            ->formatStateUsing(fn (Get $get) => filled($get('latitude')) ? sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=17/%s/%s', $get('latitude'), $get('longitude'), $get('latitude'), $get('longitude')) : null)
                            ->suffixIcon('heroicon-m-arrow-top-right-on-square'),
                    ]),
                ]),
                Tab::make('Opening hours')->icon('heroicon-m-clock')->schema([
                    Grid::make(3)->schema([
                        Toggle::make('is_24x7')->label('Open 24×7')->live(),
                        TextInput::make('slot_minutes')->label('Slot length (minutes)')->disabled($locked)->numeric()->required()->default(60)->minValue(15)->maxValue(240)->step(15),
                        TextInput::make('buffer_minutes')->label('Buffer between slots (minutes)')->disabled($locked)->numeric()->required()->default(0)->minValue(0)->maxValue(60),
                    ]),
                    Repeater::make('operatingHours')
                        ->relationship()
                        ->label('Weekly hours (station local time, IST)')
                        ->hidden(fn (Get $get) => (bool) $get('is_24x7'))
                        ->default(fn () => collect(range(0, 6))->map(fn ($d) => ['weekday' => $d, 'opens_at' => '06:00', 'closes_at' => '23:00', 'is_closed' => false])->all())
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->columns(4)
                        ->schema([
                            Select::make('weekday')->options(OperatingHour::DAYS)->disabled()->dehydrated()->required(),
                            TimePicker::make('opens_at')->seconds(false)->required(fn (Get $get) => ! $get('is_closed')),
                            TimePicker::make('closes_at')->seconds(false)->required(fn (Get $get) => ! $get('is_closed'))
                                ->helperText('Earlier than opening = closes after midnight'),
                            Toggle::make('is_closed')->label('Closed all day')->inline(false)->live(),
                        ]),
                ]),
                Tab::make('Photos & parking')->icon('heroicon-m-photo')->schema([
                    Grid::make(1)->disabled($locked)->schema([
                        FileUpload::make('photos')->label('Station photos')->image()->multiple()->reorderable()->maxFiles(8)
                            ->disk('public')->directory('stations')->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(3072)
                            ->imageEditor()->imageResizeMode('cover')->imageResizeTargetWidth('1600')
                            ->panelLayout('grid')
                            ->helperText('Up to 8 photos (JPG, PNG or WebP, 3 MB each). The first photo is the cover image.'),
                    ]),
                    Grid::make(3)->disabled($locked)->schema([
                        Toggle::make('parking_available')->label('Parking available')->default(true)->live()->inline(false),
                        Select::make('parking_type')->options(fn () => \App\Models\Lookup::options('parking_type'))
                            ->visible(fn (Get $get) => (bool) $get('parking_available')),
                        TextInput::make('parking_note')->maxLength(200)->placeholder('e.g. Basement B2, bays 14–18')
                            ->visible(fn (Get $get) => (bool) $get('parking_available')),
                    ]),
                    CheckboxList::make('allowed_durations')->label('Booking durations customers can choose')->disabled($locked)
                        ->options(fn (Get $get) => collect(range(1, \App\Support\BusinessRules::int('booking.max_slots')))
                            ->mapWithKeys(fn ($n) => [($m = $n * max(15, (int) ($get('slot_minutes') ?: 60))) => $m < 60 ? "{$m} min" : rtrim(rtrim(number_format($m / 60, 2), '0'), '.').' h'])->all())
                        ->columns(4)->bulkToggleable()
                        ->helperText('Leave all unticked to offer every multiple of the slot length.')
                        ->dehydrateStateUsing(fn ($state) => collect($state ?? [])->map(fn ($v) => (int) $v)->sort()->values()->all() ?: null),
                ]),
                Tab::make('Power supply')->icon('heroicon-m-bolt')->schema([
                    Grid::make(3)->disabled($locked)->schema([
                        TextInput::make('electricity_provider')->label('Electricity provider (DISCOM)')->maxLength(120)->placeholder('e.g. SBPDCL, MSEDCL, BSES'),
                        TextInput::make('electricity_consumer_no')->label('Consumer / connection no.')->maxLength(40),
                        Select::make('supply_type')->label('Supply type')->options(['LT' => 'LT (low tension)', 'HT' => 'HT (high tension)']),
                        TextInput::make('supply_voltage')->label('Supply voltage')->maxLength(30)->datalist(['230 V single-phase', '415 V 3-phase', '11 kV', '33 kV']),
                        TextInput::make('sanctioned_load_kw')->label('Sanctioned load')->numeric()->minValue(1)->maxValue(100000)->suffix('kW')
                            ->helperText('Total charger power should stay within this.'),
                    ]),
                ]),
                Tab::make('Legal & contact')->icon('heroicon-m-building-office')->schema([
                    Grid::make(3)->disabled($locked)->schema([
                        TextInput::make('gstin')->label('GSTIN for this state')->maxLength(15)
                            ->regex('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/')
                            ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (filled($value) && substr(strtoupper($value), 0, 2) !== $get('state_code')) {
                                    $fail('The GSTIN must be registered in the station’s state (it must start with '.$get('state_code').').');
                                }
                            })
                            ->helperText('Required unless the company GSTIN is registered in this state. Invoices use this GSTIN.')
                            ->dehydrateStateUsing(fn (?string $state) => $state ? Str::upper($state) : null),
                        TextInput::make('contact_phone')->tel()->maxLength(20),
                        TextInput::make('contact_email')->email()->maxLength(255),
                    ]),
                ]),
            ]),
        ]);
    }
}
