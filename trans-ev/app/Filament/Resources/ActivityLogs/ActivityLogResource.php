<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Models;
use App\Models\Station;
use App\Models\User;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Read-only audit trail (who changed what, when). The table is append-only in MySQL.
 * Platform staff with audit.view see everything; a partner company sees only entries about
 * its own records or made by its own staff.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'audit-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static ?int $navigationSort = 9;

    /** Subject types with a direct company_id column. */
    private const COMPANY_OWNED = [Models\Station::class, Models\Booking::class, Models\RuleSet::class, Models\CompanyDocument::class, Models\Payment::class, Models\Refund::class, User::class];

    /** Subject types that belong to a company through their station. */
    private const STATION_OWNED = [Models\Charger::class, Models\Tariff::class, Models\HolidayOverride::class, Models\OperatingHour::class];

    public static function subjectLabels(): array
    {
        return [
            Models\Station::class => 'Station', Models\Charger::class => 'Charger', Models\Connector::class => 'Connector',
            Models\Tariff::class => 'Tariff', Models\HolidayOverride::class => 'Holiday', Models\OperatingHour::class => 'Opening hours',
            Models\RuleSet::class => 'Rules', Models\Booking::class => 'Booking', Models\Payment::class => 'Payment', Models\Refund::class => 'Refund',
            Models\Company::class => 'Company', Models\CompanyDocument::class => 'KYC document', Models\Plan::class => 'Plan',
            User::class => 'User', Models\BlogPost::class => 'Blog post', Models\ContactMessage::class => 'Contact message',
            Models\SystemSetting::class => 'Setting',
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['causer']);
        if (Tenancy::isPlatformAdmin()) {
            return $query;
        }

        $companyId = Tenancy::companyId() ?? -1;

        return $query->where(function (Builder $query) use ($companyId) {
            $query->whereHasMorph('causer', [User::class], fn (Builder $q) => $q->where('company_id', $companyId))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', Models\Company::class)->where('subject_id', $companyId))
                ->orWhereHasMorph('subject', self::COMPANY_OWNED, fn (Builder $q) => $q->where($q->getModel()->getTable().'.company_id', $companyId))
                ->orWhereHasMorph('subject', self::STATION_OWNED, fn (Builder $q) => $q->whereHas('station', fn (Builder $s) => $s->where('company_id', $companyId)))
                ->orWhereHasMorph('subject', [Models\Connector::class], fn (Builder $q) => $q->whereHas('charger.station', fn (Builder $s) => $s->where('company_id', $companyId)));
        });
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return (auth()->user()?->can('audit.view') ?? false) ? Response::allow() : Response::deny();
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
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, h:i:s A', Station::TIMEZONE)->sortable(),
                TextColumn::make('causer.name')->label('Who')->placeholder('System')
                    ->description(fn (Activity $record) => $record->causer?->email)->searchable(),
                TextColumn::make('description')->label('What')->weight('medium')->searchable()
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('subject_type')->label('Record')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state, Activity $record) => (static::subjectLabels()[$state] ?? class_basename((string) $state)).($record->subject_id ? " #{$record->subject_id}" : '')),
                TextColumn::make('changed')->label('Fields changed')->color('gray')->wrap()
                    ->state(fn (Activity $record) => collect($record->attribute_changes?->get('attributes') ?? [])->keys()->take(5)->implode(', ') ?: null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Record type')->options(static::subjectLabels()),
                SelectFilter::make('event')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'restored' => 'Restored']),
                SelectFilter::make('causer_id')->label('Who')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Tenancy::scope(User::query())->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))->limit(20)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => User::find($value)?->name)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('causer_type', User::class)->where('causer_id', $data['value']) : $query),
                Filter::make('date')->schema([
                    DatePicker::make('from')->native(false),
                    DatePicker::make('until')->native(false),
                ])->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($d, Station::TIMEZONE)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($d, Station::TIMEZONE)->endOfDay()->utc()))),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No audit entries');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Grid::make(4)->schema([
                    TextEntry::make('created_at')->label('When')->dateTime('d M Y, h:i:s A', Station::TIMEZONE),
                    TextEntry::make('causer.name')->label('Who')->placeholder('System')->helperText(fn (Activity $record) => $record->causer?->email),
                    TextEntry::make('description')->label('What')->formatStateUsing(fn (string $state) => ucfirst($state)),
                    TextEntry::make('subject_type')->label('Record')
                        ->formatStateUsing(fn (?string $state, Activity $record) => (static::subjectLabels()[$state] ?? class_basename((string) $state)).($record->subject_id ? " #{$record->subject_id}" : '')),
                ]),
            ]),
            Section::make('Changes')->columns(2)->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->attribute_changes?->get('attributes')))
                ->schema([
                    KeyValueEntry::make('old')->label('Before')->state(fn (Activity $record) => static::flat($record->attribute_changes?->get('old') ?? [])),
                    KeyValueEntry::make('new')->label('After')->state(fn (Activity $record) => static::flat($record->attribute_changes?->get('attributes') ?? [])),
                ]),
            Section::make('Details')->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->properties?->all()))
                ->schema([
                    KeyValueEntry::make('properties')->hiddenLabel()->state(fn (Activity $record) => static::flat($record->properties?->all() ?? [])),
                ]),
        ]);
    }

    /** Render nested values as text for display. */
    public static function flat(array $values): array
    {
        return collect($values)->map(fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }
}
