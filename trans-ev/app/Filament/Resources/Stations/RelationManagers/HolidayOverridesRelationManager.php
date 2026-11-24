<?php

namespace App\Filament\Resources\Stations\RelationManagers;

use App\Models\HolidayOverride;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidayOverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'holidayOverrides';

    protected static ?string $title = 'Holidays & special hours';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->required()->minDate(now(\App\Models\Station::TIMEZONE)->startOfDay())
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('station_id', $this->getOwnerRecord()->id)),
            TextInput::make('label')->maxLength(100)->placeholder('Diwali'),
            Toggle::make('is_closed')->label('Closed all day')->default(true)->live()->columnSpanFull(),
            TimePicker::make('opens_at')->seconds(false)->hidden(fn (Get $get) => $get('is_closed'))->required(fn (Get $get) => ! $get('is_closed')),
            TimePicker::make('closes_at')->seconds(false)->hidden(fn (Get $get) => $get('is_closed'))->required(fn (Get $get) => ! $get('is_closed')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('label'),
                IconColumn::make('is_closed')->label('Closed')->boolean(),
                TextColumn::make('hours')->state(fn (HolidayOverride $r) => $r->is_closed ? '—' : substr($r->opens_at, 0, 5).' – '.substr($r->closes_at, 0, 5)),
            ])
            ->headerActions([CreateAction::make()->label('Add date')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
