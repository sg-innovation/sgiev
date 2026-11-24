<?php

namespace App\Filament\Resources\TicketSetup;

use App\Models\TicketPriority;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Ticket priorities and their response/resolution targets (SLA). */
class TicketPriorityResource extends Resource
{
    protected static ?string $model = TicketPriority::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Priorities & SLA';

    protected static ?int $navigationSort = 6;

    protected static function allowed(): Response
    {
        return Tenancy::platformCan('tickets.manage') ? Response::allow() : Response::deny();
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::allowed();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::allowed();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::allowed();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(40),
            TextInput::make('slug')->required()->maxLength(40)->alphaDash()->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('first_response_minutes')->label('First reply within (minutes)')->numeric()->integer()->minValue(5)->maxValue(43200)->required(),
            TextInput::make('resolution_minutes')->label('Resolve within (minutes)')->numeric()->integer()->minValue(5)->maxValue(129600)->required()->gte('first_response_minutes'),
            Select::make('color')->options(['gray' => 'Gray', 'info' => 'Blue', 'primary' => 'Green', 'warning' => 'Amber', 'danger' => 'Red'])->required()->default('gray'),
            TextInput::make('sort')->numeric()->integer()->default(10),
            Toggle::make('customer_selectable')->label('Customers can choose it')->default(true),
            Toggle::make('is_default')->label('Default priority')
                ->helperText('Used when a topic has no default. Only one priority should be the default.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->badge()->color(fn (TicketPriority $r) => $r->color),
                TextColumn::make('first_response_minutes')->label('First reply within')->formatStateUsing(fn (int $state) => TicketPriority::humanMinutes($state)),
                TextColumn::make('resolution_minutes')->label('Resolve within')->formatStateUsing(fn (int $state) => TicketPriority::humanMinutes($state)),
                IconColumn::make('customer_selectable')->label('Customers')->boolean(),
                IconColumn::make('is_default')->label('Default')->boolean(),
            ])
            ->recordActions([EditAction::make()->after(function (TicketPriority $record) {
                if ($record->is_default) {
                    TicketPriority::whereKeyNot($record->id)->update(['is_default' => false]);
                }
            })]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTicketPriorities::route('/')];
    }
}
