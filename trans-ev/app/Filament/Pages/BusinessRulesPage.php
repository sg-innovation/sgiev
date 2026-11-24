<?php

namespace App\Filament\Pages;

use App\Support\BusinessRules;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

/** Admin → Settings → Business rules: booking limits, estimate factors, support and partner rules. (Refunds: Cancellation policies.) */
class BusinessRulesPage extends Page
{
    protected string $view = 'filament.pages.business-rules';

    protected static ?string $slug = 'business-rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Business rules';

    protected static ?string $title = 'Business rules';

    protected static ?int $navigationSort = 9;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Tenancy::platformCan('settings.manage');
    }

    public function mount(): void
    {
        $values = collect(BusinessRules::DEFINITIONS)->mapWithKeys(fn ($def, $key) => [self::field($key) => BusinessRules::get($key)])->all();
        $this->form->fill($values);
    }

    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function form(Schema $schema): Schema
    {
        $sections = collect(BusinessRules::DEFINITIONS)->groupBy('group', preserveKeys: true)->map(function ($defs, $group) {
            return Section::make($group)->columns(2)->collapsible()->schema(collect($defs)->map(function ($def, $key) {
                $name = self::field($key);
                if ($def['type'] === 'json') {
                    return Repeater::make($name)->label($def['label'])->helperText($def['help'])->columnSpanFull()
                        ->columns(3)->reorderable(false)->minItems(1)->maxItems(6)
                        ->schema([
                            TextInput::make('min_minutes_before')->label('If cancelled at least (minutes before start)')->numeric()->integer()->minValue(0)->maxValue(10080)->required(),
                            TextInput::make('refund_percent')->label('Refund %')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                            TextInput::make('label')->label('Shown to customers')->required()->maxLength(80),
                        ]);
                }
                $input = TextInput::make($name)->label($def['label'])->helperText($def['help'])->required();

                return match ($def['type']) {
                    'int' => $input->numeric()->integer()->minValue($def['min'])->maxValue($def['max']),
                    'decimal' => $input->numeric()->minValue($def['min'])->maxValue($def['max'])->step('0.01'),
                    default => $input->maxLength(60),
                };
            })->values()->all());
        })->values()->all();

        return $schema->statePath('data')->components($sections);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save rules')->icon('heroicon-m-check')->action(fn () => $this->save()),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $values = collect(BusinessRules::DEFINITIONS)->keys()->mapWithKeys(fn ($key) => [$key => $state[self::field($key)] ?? null])->all();

        $values['partner.terms_version'] = Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $values['partner.terms_version']), 60, '');

        $changed = BusinessRules::save($values, auth()->id());
        if ($changed) {
            activity()->causedBy(auth()->user())->withProperties(['changed' => $changed])->log('business rules changed');
        }
        Notification::make()->success()->title($changed ? 'Saved: '.count($changed).' '.Str::plural('rule', count($changed)).' changed' : 'No changes')->send();
    }
}
