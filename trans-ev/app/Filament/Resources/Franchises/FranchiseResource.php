<?php

namespace App\Filament\Resources\Franchises;

use App\Enums\Role;
use App\Models\Franchise;
use App\Models\Station;
use App\Models\User;
use App\Rules\ValidGstin;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * G3 Franchises (Trans EV): regional partners over a group of stations, their regional managers and commission.
 * Regional managers see their own franchise here (read-only) and its stations across the admin.
 */
class FranchiseResource extends Resource
{
    protected static ?string $model = Franchise::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Franchises';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canManage(): bool
    {
        return Tenancy::platformCan('franchises.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->withCount('stations');

        return static::canManage() ? $q : $q->whereHas('managers', fn ($m) => $m->whereKey(auth()->id()));
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canManage()) {
            return null;
        }
        $n = Franchise::query()->where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        $readOnly = fn () => ! static::canManage();

        return $schema->columns(3)->components([
            Section::make('Franchise')->columnSpan(2)->columns(2)->disabled($readOnly)->schema([
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('legal_name')->maxLength(160),
                TextInput::make('contact_name')->label('Contact person')->required()->maxLength(120),
                TextInput::make('email')->email()->required()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(15),
                TextInput::make('region')->label('Territory')->maxLength(120)->placeholder('e.g. Pune & PCMC'),
                TextInput::make('city')->maxLength(80),
                TextInput::make('state')->maxLength(60),
                TextInput::make('gstin')->label('GSTIN')->maxLength(15)->rule(new ValidGstin)->dehydrateStateUsing(fn ($state) => $state ? strtoupper($state) : null),
                TextInput::make('pan')->label('PAN')->maxLength(10)->regex('/^[A-Z]{5}[0-9]{4}[A-Z]$/i')->dehydrateStateUsing(fn ($state) => $state ? strtoupper($state) : null),
                TextInput::make('commission_percent')->label('Commission on net sales')->suffix('%')->numeric()->minValue(0)->maxValue(50)->default(0)->required()
                    ->helperText('Paid monthly on the net sales (excl. GST) of the franchise’s stations.'),
                KeyValue::make('bank_details')->label('Bank for payouts')->keyLabel('Field (bank, account_name, account_no, ifsc)')->valueLabel('Value')->columnSpanFull(),
                Textarea::make('application_note')->label('Application message')->rows(2)->disabled()->dehydrated(false)->columnSpanFull()->visibleOn('edit'),
            ]),
            Section::make('Stations & people')->columnSpan(1)->schema([
                Select::make('station_ids')->label('Stations')->multiple()->searchable()->preload()->dehydrated(false)->disabled($readOnly)
                    ->options(fn () => Station::query()->with('company:id,trade_name')->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} · {$s->company?->trade_name}"]))
                    ->helperText('A station can belong to one franchise.'),
                Select::make('manager_ids')->label('Regional managers')->multiple()->searchable()->dehydrated(false)->disabled($readOnly)
                    ->options(fn () => User::query()->whereNull('blocked_at')->where('company_id', \App\Models\Company::query()->where('is_platform', true)->value('id'))
                        ->whereDoesntHave('roles', fn ($r) => $r->where('name', Role::SuperAdmin->value))->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} · {$u->email}"]))
                    ->helperText('Trans EV staff accounts. They get the Regional manager role and see only these stations.'),
                TextInput::make('status_label')->label('Status')->dehydrated(false)->disabled()->visibleOn('edit')
                    ->formatStateUsing(fn (?Franchise $record) => $record ? Franchise::STATUSES[$record->status] ?? $record->status : null),
                Textarea::make('review_note')->label('Review note')->rows(2)->disabled()->dehydrated(false)->visibleOn('edit'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(['name', 'code', 'contact_name', 'email'])->weight('semibold')->description(fn (Franchise $record) => $record->code.' · '.($record->region ?? $record->city)),
                TextColumn::make('contact_name')->label('Contact')->description(fn (Franchise $record) => $record->email),
                TextColumn::make('stations_count')->label('Stations')->alignEnd(),
                TextColumn::make('commission_percent')->label('Commission')->suffix('%')->alignEnd(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Franchise::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['active' => 'success', 'pending' => 'warning', 'suspended' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Since')->date('j M Y')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(Franchise::STATUSES)])
            ->recordActions([EditAction::make()->label(fn () => static::canManage() ? 'Open' : 'View')])
            ->emptyStateHeading('No franchises yet')
            ->emptyStateDescription('Applications from the website’s “Become a franchise partner” page appear here for approval.');
    }

    public static function getRelations(): array
    {
        return [RelationManagers\PayoutsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFranchises::route('/'),
            'create' => Pages\CreateFranchise::route('/create'),
            'edit' => Pages\EditFranchise::route('/{record}/edit'),
        ];
    }
}
