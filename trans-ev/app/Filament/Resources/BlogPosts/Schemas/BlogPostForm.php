<?php

namespace App\Filament\Resources\BlogPosts\Schemas;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/** WordPress-style editor: content on the left, publish box and settings on the right. */
class BlogPostForm
{
    public static function configure(Schema $schema): Schema
    {
        $canPublish = fn () => auth()->user()?->can('publish', BlogPost::class) ?? false;

        return $schema->columns(3)->components([
            Group::make()->columnSpan(2)->schema([
                Section::make()->schema([
                    TextInput::make('title')->required()->maxLength(200)->placeholder('Add title')->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.'])
                        ->extraInputAttributes(['class' => 'text-xl font-semibold'])
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation) {
                            if ($operation === 'create' || blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')->label('Permalink')->required()->maxLength(220)->unique(ignoreRecord: true)
                        ->prefix('transevrecharge.com/blog/')->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->validationMessages(['regex' => 'Use lowercase letters, numbers and hyphens only.'])
                        ->dehydrateStateUsing(fn (?string $state) => Str::slug((string) $state)),
                    RichEditor::make('content')->required()->columnSpanFull()
                        ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('blog/attachments')->fileAttachmentsVisibility('public')
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike', 'link'],
                            ['h2', 'h3'],
                            ['bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule'],
                            ['attachFiles', 'table'],
                            ['undo', 'redo'],
                        ])
                        ->extraInputAttributes(['style' => 'min-height: 28rem']),
                    Textarea::make('excerpt')->rows(3)->maxLength(400)->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.'])
                        ->helperText('Short summary shown on blog cards and in search results. Leave empty to use the first lines of the post.'),
                ]),
                Section::make('SEO')->icon('heroicon-o-magnifying-glass')->collapsible()->columns(1)->schema([
                    TextInput::make('meta_title')->maxLength(70)->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.'])->live(debounce: 500)
                        ->helperText(fn (?string $state) => strlen((string) $state).'/70 characters. Defaults to the post title.'),
                    Textarea::make('meta_description')->rows(2)->maxLength(170)->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.'])->live(debounce: 500)
                        ->helperText(fn (?string $state) => strlen((string) $state).'/170 characters. Defaults to the excerpt.'),
                    Toggle::make('allow_indexing')->label('Allow search engines to index this post')->default(true),
                ]),
            ]),

            Group::make()->columnSpan(1)->schema([
                Section::make('Publish')->icon('heroicon-o-paper-airplane')->schema([
                    Select::make('status')->options(fn () => $canPublish()
                        ? BlogPost::STATUSES
                        : collect(BlogPost::STATUSES)->only(['draft', 'pending'])->all())
                        ->default('draft')->required()->native(false)->live()
                        ->helperText(fn () => $canPublish() ? 'Set a future date to schedule.' : 'Submit for review; a publisher will publish it.'),
                    DateTimePicker::make('published_at')->label('Publish date')->seconds(false)->timezone('Asia/Kolkata')
                        ->visible(fn (Get $get) => in_array($get('status'), ['published', 'scheduled'], true))
                        ->helperText('Leave empty to publish now.'),
                    Select::make('author_id')->label('Author')->relationship('author', 'name')->default(fn () => auth()->id())
                        ->searchable()->preload()->required(),
                    Toggle::make('is_featured')->label('Feature on blog home'),
                ]),
                Section::make('Category & tags')->icon('heroicon-o-tag')->schema([
                    Select::make('blog_category_id')->label('Category')->relationship('category', 'name')->preload()->searchable()
                        ->createOptionForm([
                            TextInput::make('name')->required()->maxLength(80),
                            Select::make('color')->options(BlogCategory::COLORS)->default('emerald'),
                        ]),
                    Select::make('tags')->relationship('tags', 'name')->multiple()->preload()->searchable()
                        ->createOptionForm([TextInput::make('name')->required()->maxLength(60)]),
                ]),
                Section::make('Featured image')->icon('heroicon-o-photo')->schema([
                    FileUpload::make('cover_image')->hiddenLabel()->image()->imageEditor()->disk('public')->directory('blog/covers')
                        ->visibility('public')->maxSize(3072)->imageResizeMode('cover')->imageCropAspectRatio('16:9')
                        ->helperText('16:9, up to 3 MB. Without an image, a branded cover is shown.'),
                    TextInput::make('cover_alt')->label('Alt text')->maxLength(200)->helperText('Describe the image for screen readers.'),
                ]),
            ]),
        ]);
    }
}
