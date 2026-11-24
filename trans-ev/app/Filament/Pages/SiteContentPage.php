<?php

namespace App\Filament\Pages;

use App\Models\SiteSection;
use App\Support\SiteContent;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

/** Admin → Content → Site content: homepage, About, Pricing, Safety and Contact copy. */
class SiteContentPage extends Page
{
    protected string $view = 'filament.pages.site-content';

    protected static ?string $slug = 'site-content';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Site content';

    protected static ?string $title = 'Site content';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Tenancy::platformCan('content.manage');
    }

    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $this->form->fill(collect(SiteContent::SECTIONS)->mapWithKeys(fn ($def, $key) => [self::field($key) => SiteContent::get($key)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $tabs = collect(SiteContent::PAGES)->map(fn ($label, $page) => Tab::make($label)->schema(
            collect(SiteContent::SECTIONS)->filter(fn ($d) => $d['page'] === $page)->map(fn ($def, $key) => Section::make($def['label'])->collapsible()
                ->statePath(self::field($key))->columns(2)
                ->schema(collect($def['fields'])->map(fn ($type, $name) => self::input($name, $type))->values()->all()))->values()->all()
        ))->values()->all();

        return $schema->statePath('data')->components([Tabs::make('pages')->tabs($tabs)->persistTabInQueryString()]);
    }

    private static function input(string $name, string|array $type): mixed
    {
        $label = Str::headline($name);
        if (is_array($type)) {
            return Repeater::make($name)->label($label === 'Items' ? 'Items' : $label)->columnSpanFull()->collapsible()->reorderable()->cloneable()
                ->itemLabel(fn (array $state) => $state['title'] ?? $state['name'] ?? $state['q'] ?? $state['label'] ?? $state['text'] ?? null)
                ->columns(2)
                ->schema(collect($type)->map(fn ($t, $n) => self::input($n, $t))->values()->all());
        }

        return match ($type) {
            'textarea' => Textarea::make($name)->label($label)->rows(3)->maxLength(1000)->columnSpanFull(),
            'list' => TagsInput::make($name)->label($label)->columnSpanFull()->reorderable(),
            'icon' => Select::make($name)->label('Icon')->options(SiteContent::ICONS)->searchable(),
            'tone' => Select::make($name)->label('Colour')->options(SiteContent::TONES),
            'kind' => Select::make($name)->label('Type')->options(['ac' => 'AC', 'dc' => 'DC']),
            'rate' => Select::make($name)->label('Price shown')->options(['acMin' => 'Lowest AC rate', 'acMax' => 'Highest AC rate', 'dcMin' => 'Lowest DC rate', 'dcMax' => 'Highest DC rate'])
                ->helperText('Taken from live station tariffs.'),
            default => TextInput::make($name)->label($label)->maxLength(255),
        };
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $changed = [];
        foreach (SiteContent::SECTIONS as $key => $def) {
            $value = self::clean($state[self::field($key)] ?? []);
            $row = SiteSection::query()->firstOrNew(['key' => $key]);
            if (! $row->exists || $row->data != $value) {
                $row->fill(['data' => $value, 'updated_by' => auth()->id()])->save();
                $changed[] = $key;
            }
        }
        SiteContent::flush();
        cache()->forget('home.data.v2');
        Notification::make()->success()->title($changed ? 'Saved '.count($changed).' '.Str::plural('section', count($changed)) : 'No changes')->send();
    }

    /** Plain text only: strip tags and re-index repeaters. */
    private static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = array_map([self::class, 'clean'], $value);
            // Repeater rows are keyed by generated UUIDs: store them as a plain list.
            $isRows = $value !== [] && collect(array_keys($value))->every(fn ($k) => is_int($k) || Str::isUuid((string) $k) || str_starts_with((string) $k, 'record-'));

            return $isRows ? array_values($out) : $out;
        }

        return is_string($value) ? trim(strip_tags($value)) : $value;
    }
}
