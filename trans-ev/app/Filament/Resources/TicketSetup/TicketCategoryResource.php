<?php

namespace App\Filament\Resources\TicketSetup;

use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/** Ticket topics: who can pick them and who resolves them (Trans EV staff with tickets.manage). */
class TicketCategoryResource extends Resource
{
    protected static ?string $model = TicketCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Ticket topics';

    protected static ?int $navigationSort = 5;

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
        return Response::deny(); // deactivate instead (tickets reference topics)
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(80)->notRegex('/[<>]/')
                ->afterStateUpdated(fn ($set, $get, ?string $state, string $operation) => $operation === 'create' && blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null)->live(onBlur: true),
            TextInput::make('slug')->required()->maxLength(80)->alphaDash()->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
            Select::make('audience')->options(TicketCategory::AUDIENCES)->required()->default('all'),
            Select::make('handled_by')->label('Resolved by')->options(TicketCategory::HANDLERS)->required()->default('platform')
                ->helperText('“Operator” sends it to the company running the booked station (needs a booking).'),
            Select::make('default_priority_id')->label('Default priority')->options(fn () => TicketPriority::orderBy('sort')->pluck('name', 'id')->all()),
            TextInput::make('sort')->numeric()->integer()->default(10),
            Toggle::make('requires_booking')->label('Customer must choose a booking'),
            \Filament\Forms\Components\Select::make('contact_topic')->label('Contact-form topic')->options(fn () => \App\Models\ContactMessage::topics())->placeholder('None')
                ->helperText('Contact-form messages on this topic become tickets in this category.'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('name')->weight('semibold')->description(fn (TicketCategory $r) => $r->description)->wrap(),
                TextColumn::make('audience')->badge()->formatStateUsing(fn (string $state) => TicketCategory::AUDIENCES[$state] ?? $state)->color('gray'),
                TextColumn::make('handled_by')->label('Resolved by')->formatStateUsing(fn (string $state) => TicketCategory::HANDLERS[$state] ?? $state),
                TextColumn::make('defaultPriority.name')->label('Default priority')->badge()->color(fn (TicketCategory $r) => $r->defaultPriority?->color ?? 'gray'),
                IconColumn::make('requires_booking')->label('Booking')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTicketCategories::route('/')];
    }
}
