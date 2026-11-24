<?php

namespace App\Filament\Resources\BlogCategories;

use App\Filament\Resources\BlogCategories\Pages\ManageBlogCategories;
use App\Models\BlogCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class BlogCategoryResource extends Resource
{
    protected static ?string $model = BlogCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (auth()->user()?->isPlatformStaff() ?? false) && auth()->user()->can('blog.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(80)->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')->required()->maxLength(100)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true),
            Select::make('color')->options(BlogCategory::COLORS)->default('emerald')->required(),
            TextInput::make('sort')->numeric()->default(0),
            Textarea::make('description')->maxLength(300)->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->withCount('posts'))
            ->columns([
                TextColumn::make('name')->weight('medium')->searchable()->description(fn (BlogCategory $r) => $r->description),
                TextColumn::make('slug')->color('gray'),
                TextColumn::make('color')->badge()->formatStateUsing(fn ($state) => BlogCategory::COLORS[$state] ?? $state),
                TextColumn::make('posts_count')->label('Posts')->alignCenter(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->disabled(fn (BlogCategory $r) => $r->posts_count > 0)->tooltip('Move posts to another category first'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBlogCategories::route('/')];
    }
}
