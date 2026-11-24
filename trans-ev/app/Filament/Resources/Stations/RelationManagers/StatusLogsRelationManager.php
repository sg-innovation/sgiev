<?php

namespace App\Filament\Resources\Stations\RelationManagers;

use App\Models\Station;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only status history (append-only log). */
class StatusLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusLogs';

    protected static ?string $title = 'Status history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, h:i A', Station::TIMEZONE),
                TextColumn::make('from_status')->label('From')->badge()->placeholder('—'),
                TextColumn::make('to_status')->label('To')->badge(),
                TextColumn::make('reason')->wrap()->placeholder('—'),
                TextColumn::make('until')->label('Until')->dateTime('d M, h:i A', Station::TIMEZONE)->placeholder('—'),
                TextColumn::make('changedBy.name')->label('By')->placeholder('System'),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
