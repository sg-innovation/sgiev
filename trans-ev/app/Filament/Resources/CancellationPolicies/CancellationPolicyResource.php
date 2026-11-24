<?php

namespace App\Filament\Resources\CancellationPolicies;

use App\Models\CancellationPolicy;
use App\Models\Station;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Cancellation & no-show policies. Operators set their own (company-wide or per station); Trans EV staff
 * with platform access also manage the Trans EV standard policy, used when an operator has none.
 */
class CancellationPolicyResource extends Resource
{
    protected static ?string $model = CancellationPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings & Payments';

    protected static ?string $navigationLabel = 'Cancellation policies';

    protected static ?string $modelLabel = 'cancellation policy';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['company', 'station']);

        return Tenancy::isPlatformAdmin() ? $query : $query->where('company_id', Tenancy::companyId() ?? -1);
    }

    private static function allowed(): Response
    {
        return (auth()->user()?->can('cancellation.manage') ?? false) ? Response::allow() : Response::deny();
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
        $own = $record->company_id === null ? Tenancy::isPlatformAdmin() : Tenancy::owns($record->company_id);

        return static::allowed()->allowed() && $own ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny(); // bookings keep their own copy; deactivate instead
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Policy')->columnSpan(2)->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120)->notRegex('/[<>]/'),
                Select::make('company_id')->label('Operator')->visible(fn () => Tenancy::isPlatformAdmin())->disabledOn('edit')
                    ->options(fn () => \App\Models\Company::query()->where('is_platform', false)->orderBy('trade_name')->pluck('trade_name', 'id')->all())
                    ->placeholder('Trans EV standard policy (used when an operator has none)')->live(),
                Select::make('station_id')->label('Applies to')->disabledOn('edit')->searchable()
                    ->options(fn ($get) => Tenancy::scope(Station::query())
                        ->when(Tenancy::isPlatformAdmin(), fn (Builder $q) => $q->where('company_id', $get('company_id') ?? -1))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('All stations of the operator')
                    ->helperText('Choose a station only if it needs a different policy.'),
                Repeater::make('tiers')->label('Refund when the customer cancels')->columnSpanFull()->columns(4)->minItems(1)->maxItems(6)
                    ->default([['min_minutes_before' => 120, 'refund_percent' => 100, 'fee' => 0, 'label' => 'Full refund (more than 2 hours before)'], ['min_minutes_before' => 0, 'refund_percent' => 0, 'fee' => 0, 'label' => 'No refund (less than 2 hours before)']])
                    ->schema([
                        TextInput::make('min_minutes_before')->label('At least (minutes before start)')->numeric()->integer()->minValue(0)->maxValue(10080)->required(),
                        TextInput::make('refund_percent')->label('Refund %')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                        TextInput::make('fee')->label('Cancellation fee (₹, optional)')->numeric()->minValue(0)->maxValue(5000)->default(0),
                        TextInput::make('label')->label('Shown to customers')->required()->maxLength(80),
                    ])
                    ->helperText('Must include a tier starting at 0 minutes. The fee is taken off the refund, never charged on top.'),
                TextInput::make('no_show_refund_percent')->label('Refund on a no-show (%)')->numeric()->integer()->minValue(0)->maxValue(100)->default(0)->required(),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false)
                    ->helperText('Turning on a policy turns off the previous one for the same operator and station.'),
                Textarea::make('customer_note')->label('Note shown to customers')->rows(2)->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('How it works')->columnSpan(1)->schema([
                \Filament\Infolists\Components\TextEntry::make('help')->hiddenLabel()->state(new \Illuminate\Support\HtmlString(
                    '<p>Customers see this policy before they pay. It is saved on each booking, so changing it later only affects new bookings.</p>'
                    .'<p style="margin-top:8px">Station policy → operator policy → Trans EV standard policy.</p>'
                    .'<p style="margin-top:8px">If the operator cancels, or the station is offline, the customer always gets a full refund.</p>'
                )),
            ]),
        ]);
    }

    /** Server-side checks and normalisation (shared by create and edit). */
    public static function validated(array $data, ?CancellationPolicy $record = null): array
    {
        $tiers = CancellationPolicy::normaliseTiers(array_values($data['tiers'] ?? []));
        if (! collect($tiers)->contains('min_minutes_before', 0)) {
            throw ValidationException::withMessages(['data.tiers' => 'Add a tier starting at 0 minutes, so every cancellation is covered.']);
        }
        if (collect($tiers)->pluck('min_minutes_before')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['data.tiers' => 'Two tiers start at the same time.']);
        }
        $data['tiers'] = $tiers;
        $data['customer_note'] = filled($data['customer_note'] ?? null) ? trim(strip_tags($data['customer_note'])) : null;

        if (! $record) {
            if (! Tenancy::isPlatformAdmin()) {
                $data['company_id'] = Tenancy::companyId();
            }
            if (filled($data['station_id'] ?? null)) {
                $station = Station::query()->findOrFail($data['station_id']);
                abort_unless(Tenancy::owns($station->company_id) && (int) $station->company_id === (int) ($data['company_id'] ?? 0), 403);
            }
        }

        return $data;
    }

    /** Only one active policy per operator/station. */
    public static function deactivateOthers(CancellationPolicy $policy): void
    {
        if ($policy->is_active) {
            CancellationPolicy::query()->whereKeyNot($policy->id)->where('is_active', true)
                ->where(fn (Builder $q) => $policy->company_id === null ? $q->whereNull('company_id') : $q->where('company_id', $policy->company_id))
                ->where(fn (Builder $q) => $policy->station_id === null ? $q->whereNull('station_id') : $q->where('station_id', $policy->station_id))
                ->get()->each(fn (CancellationPolicy $p) => $p->update(['is_active' => false]));
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('semibold')->description(fn (CancellationPolicy $r) => $r->summary())->wrap(),
                TextColumn::make('company.trade_name')->label('Operator')->placeholder('Trans EV standard')->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('station.name')->label('Applies to')->placeholder('All stations'),
                TextColumn::make('version')->badge()->color('gray')->prefix('v'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Changed')->since(),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Using the Trans EV standard policy')
            ->emptyStateDescription('Create a policy to set your own refund rules for cancellations and no-shows.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCancellationPolicies::route('/'),
            'create' => Pages\CreateCancellationPolicy::route('/create'),
            'edit' => Pages\EditCancellationPolicy::route('/{record}/edit'),
        ];
    }
}
