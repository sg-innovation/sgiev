<?php

namespace App\Filament\Resources\Stations;

use App\Enums\Role;
use App\Filament\Resources\Stations\Pages\CreateStation;
use App\Filament\Resources\Stations\Pages\EditStation;
use App\Filament\Resources\Stations\Pages\ListStations;
use App\Filament\Resources\Stations\RelationManagers\ChargersRelationManager;
use App\Filament\Resources\Stations\RelationManagers\HolidayOverridesRelationManager;
use App\Filament\Resources\Stations\RelationManagers\StatusLogsRelationManager;
use App\Filament\Resources\Stations\RelationManagers\TariffsRelationManager;
use App\Filament\Resources\Stations\Schemas\StationForm;
use App\Filament\Resources\Stations\Tables\StationsTable;
use App\Models\Station;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class StationResource extends Resource
{
    protected static ?string $model = Station::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return StationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChargersRelationManager::class,
            TariffsRelationManager::class,
            HolidayOverridesRelationManager::class,
            StatusLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStations::route('/'),
            'create' => CreateStation::route('/create'),
            'edit' => EditStation::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        if (\App\Support\Tenancy::isPlatformAdmin()) {
            $review = static::getEloquentQuery()->where(fn ($q) => $q->where('approval_status', 'submitted')->orWhere('needs_review', true))->count();
            if ($review > 0) {
                return (string) $review;
            }
        }
        $down = static::getEloquentQuery()->where('status', '!=', 'online')->count();

        return $down > 0 ? (string) $down : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return \App\Support\Tenancy::isPlatformAdmin() ? 'Stations awaiting approval (or offline)' : 'Stations offline or in maintenance';
    }

    /** Company staff see their own company's stations; station managers only the stations assigned to them. */
    public static function getEloquentQuery(): Builder
    {
        $query = \App\Support\Tenancy::scope(parent::getEloquentQuery());
        $user = auth()->user();

        if ($user && $user->hasRole(Role::StationManager->value) && ! $user->hasAnyRole(Role::companyWide())) {
            $query->whereHas('managers', fn (Builder $q) => $q->whereKey($user->getKey()));
        }

        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'city'];
    }
}
