<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_no')->label('Registration')->weight('medium'),
                TextColumn::make('make')->description(fn ($record) => $record->model),
                TextColumn::make('battery_kwh')->label('Battery')->suffix(' kWh')->placeholder('—'),
                TextColumn::make('connector')->placeholder('—'),
                IconColumn::make('is_default')->label('Default')->boolean(),
            ]);
    }
}
