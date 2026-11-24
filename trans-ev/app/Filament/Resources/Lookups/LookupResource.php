<?php

namespace App\Filament\Resources\Lookups;

use App\Models\Lookup;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/** Admin → Settings → Lists & options. Values are kept stable; deactivate an option instead of deleting it. */
class LookupResource extends Resource
{
    protected static ?string $model = Lookup::class;

    protected static ?string $slug = 'lists';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Lists & options';

    protected static ?string $modelLabel = 'option';

    protected static ?int $navigationSort = 8;

    private static function allowed(): Response
    {
        return Tenancy::platformCan('lookups.manage') ? Response::allow() : Response::deny();
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
            Select::make('type')->label('List')->options(collect(Lookup::TYPES)->map(fn ($t) => $t['label'])->all())->required()->live()->disabledOn('edit'),
            TextInput::make('label')->required()->maxLength(120)->notRegex('/[<>]/')->live(onBlur: true)
                ->afterStateUpdated(fn ($set, $get, ?string $state, string $operation) => $operation === 'create' && blank($get('value')) ? $set('value', Str::slug((string) $state, '_')) : null),
            TextInput::make('value')->label('Key')->required()->maxLength(80)->regex('/^[a-z0-9_-]+$/')->disabledOn('edit')
                ->helperText('Saved on records; cannot be changed later.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('type', $get('type'))),
            TextInput::make('sort')->numeric()->integer()->default(10),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
            Section::make('Options for this list')->columns(2)->columnSpanFull()
                ->visible(fn (Get $get) => filled(Lookup::TYPES[$get('type')]['meta'] ?? []))
                ->schema(fn (Get $get) => collect(Lookup::TYPES[$get('type')]['meta'] ?? [])->map(function ($label, $key) use ($get) {
                    if (in_array($key, ['required', 'on_registration'], true)) {
                        return Toggle::make("meta.{$key}")->label($label);
                    }
                    if ($key === 'connector') {
                        return Select::make("meta.{$key}")->label($label)->options(\App\Enums\ConnectorType::options())->required();
                    }
                    if ($key === 'battery_kwh') {
                        return TextInput::make("meta.{$key}")->label($label)->numeric()->minValue(1)->maxValue(200)->required();
                    }

                    return TextInput::make("meta.{$key}")->label($label)->maxLength(60)->required();
                })->values()->all()),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')->defaultGroup('type')
            ->groups([\Filament\Tables\Grouping\Group::make('type')->getTitleFromRecordUsing(fn (Lookup $r) => Lookup::TYPES[$r->type]['label'] ?? $r->type)])
            ->columns([
                TextColumn::make('label')->weight('semibold')->searchable()->description(fn (Lookup $r) => $r->description),
                TextColumn::make('value')->label('Key')->fontFamily('mono')->color('gray'),
                TextColumn::make('meta')->label('Details')->color('gray')->wrap()
                    ->state(fn (Lookup $r) => collect($r->meta ?? [])->map(fn ($v, $k) => (Lookup::TYPES[$r->type]['meta'][$k] ?? $k).': '.(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->implode(' · ') ?: null)
                    ->placeholder('—'),
                TextColumn::make('sort')->sortable()->alignCenter(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->label('List')->options(collect(Lookup::TYPES)->map(fn ($t) => $t['label'])->all())])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLookups::route('/')];
    }
}
