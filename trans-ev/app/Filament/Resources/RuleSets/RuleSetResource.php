<?php

namespace App\Filament\Resources\RuleSets;

use App\Filament\Resources\RuleSets\Pages\CreateRuleSet;
use App\Filament\Resources\RuleSets\Pages\EditRuleSet;
use App\Filament\Resources\RuleSets\Pages\ListRuleSets;
use App\Filament\Resources\RuleSets\Schemas\RuleSetForm;
use App\Filament\Resources\RuleSets\Tables\RuleSetsTable;
use App\Models\RuleSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RuleSetResource extends Resource
{
    protected static ?string $model = RuleSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Station rules';

    protected static ?string $modelLabel = 'rule set';

    public static function form(Schema $schema): Schema
    {
        return RuleSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RuleSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /** Company staff see their company's rules plus the platform default they inherit. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with(['company', 'station']);
        $tenant = \App\Support\Tenancy::companyId();

        return $tenant === null ? $query : $query->where(fn ($q) => $q->where('company_id', $tenant)
            ->orWhere(fn ($q) => $q->whereNull('company_id')->whereNull('station_id')));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuleSets::route('/'),
            'create' => CreateRuleSet::route('/create'),
            'edit' => EditRuleSet::route('/{record}/edit'),
        ];
    }
}
