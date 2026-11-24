<?php

namespace App\Filament\Resources\RuleSets\Schemas;

use App\Models\RuleSet;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RuleSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(255)->default('Trans EV Charging Station Rules'),
                Select::make('company_id')->label('Company')->relationship('company', 'trade_name')
                    ->placeholder('All companies (platform default)')
                    ->visible(fn () => \App\Support\Tenancy::isPlatformAdmin())
                    ->disabledOn('edit'),
                Select::make('station_id')->label('Applies to')
                    ->relationship('station', 'name', fn ($query) => \App\Support\Tenancy::scope($query))
                    ->searchable()->preload()
                    ->placeholder('All of the company’s stations')
                    ->helperText('Station rules override company rules, which override the platform default.')
                    ->disabledOn('edit'),
                RichEditor::make('content')->label('Rules shown to customers before booking')->required()->columnSpanFull()
                    ->toolbarButtons(['bold', 'italic', 'underline', 'h2', 'h3', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
            ]),
        ]);
    }

    /** Version numbers are assigned automatically per scope. */
    public static function withVersion(array $data): array
    {
        // Company staff always write rules for their own company; platform admins write platform-wide or per company.
        $tenant = \App\Support\Tenancy::companyId();
        if (filled($data['station_id'] ?? null)) {
            $data['company_id'] = \App\Models\Station::query()->whereKey($data['station_id'])->value('company_id');
        } elseif ($tenant !== null) {
            $data['company_id'] = $tenant;
        }
        $data['version'] = RuleSet::nextVersion($data['company_id'] ?? null, $data['station_id'] ?? null);
        $data['created_by'] = auth()->id();

        return $data;
    }
}
