<?php

namespace App\Filament\Resources\ChargerControl\RelationManagers;

use App\Models\OcppCommand;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Remote commands sent to this charger and its answers. */
class CommandsRelationManager extends RelationManager
{
    protected static string $relationship = 'ocppCommands';

    protected static ?string $title = 'Commands';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('5s')
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip('j M Y, g:i:s A', 'Asia/Kolkata'),
                TextColumn::make('command')->formatStateUsing(fn ($state) => OcppCommand::COMMANDS[$state] ?? $state)->description(fn (OcppCommand $record) => $record->action),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => OcppCommand::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['accepted' => 'success', 'rejected' => 'danger', 'failed' => 'danger', 'timeout' => 'warning', 'sent' => 'info'][$state] ?? 'gray')
                    ->description(fn (OcppCommand $record) => $record->error),
                TextColumn::make('requester.name')->label('By')->placeholder('System'),
                TextColumn::make('details')->label('Details')->wrap()->toggleable()
                    ->state(fn (OcppCommand $record) => collect($record->params ?? [])->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->implode(' · ')),
            ]);
    }
}
