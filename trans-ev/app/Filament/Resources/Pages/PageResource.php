<?php

namespace App\Filament\Resources\Pages;

use App\Models\Page;
use App\Support\PageContent;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Admin → Content → Website pages: Terms, Privacy, Refund policy and any custom page. Every save is a new version. */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $slug = 'website-pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Website pages';

    protected static ?string $modelLabel = 'page';

    protected static ?int $navigationSort = 0;

    /** Pages with a fixed address on the website. Others are served at /p/{slug}. */
    public const BUILT_IN = ['terms', 'privacy', 'refund-policy'];

    private static function allowed(): Response
    {
        return Tenancy::platformCan('content.manage') ? Response::allow() : Response::deny();
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
        return Response::deny(); // unpublish instead; history is kept
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function url(Page $page): string
    {
        return in_array($page->slug, self::BUILT_IN, true) ? url('/'.$page->slug) : url('/p/'.$page->slug);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->schema([
                TextInput::make('title')->required()->maxLength(160),
                TextInput::make('slug')->label('Address')->required()->maxLength(80)->regex('/^[a-z0-9-]+$/')->unique(ignoreRecord: true)->disabledOn('edit')
                    ->prefix(url('/p').'/')->helperText('Terms, Privacy and Refund policy keep their usual addresses.'),
                Textarea::make('intro')->rows(2)->maxLength(300)->helperText('Short line under the page title.'),
                RichEditor::make('body')->required()->columnSpanFull()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['table'], ['undo', 'redo']])
                    ->helperText('Each “Heading 2” becomes a section in the page’s table of contents.'),
                TextInput::make('change_note')->label('What changed? (kept in the history)')->maxLength(255)->dehydrated(false),
            ]),
            Section::make('Placeholders')->columnSpan(1)->description('Type these anywhere in the text; they are filled in from live data when the page is shown.')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('placeholders')->hiddenLabel()
                        ->state(new HtmlString(collect(PageContent::PLACEHOLDERS)->map(fn ($d, $k) => '<div style="margin-bottom:6px"><code>'.e($k).'</code><br><span style="opacity:.7">'.e($d).'</span></div>')->implode(''))),
                    Toggle::make('is_published')->label('Published')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->weight('semibold')->description(fn (Page $r) => static::url($r)),
                TextColumn::make('version')->badge()->color('gray')->prefix('v'),
                TextColumn::make('published_at')->label('Last published')->since()->sortable(),
                IconColumn::make('is_published')->label('Published')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('open')->label('View')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (Page $r) => static::url($r), shouldOpenInNewTab: true),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
