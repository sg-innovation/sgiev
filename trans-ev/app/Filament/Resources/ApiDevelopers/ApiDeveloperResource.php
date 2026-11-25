<?php

namespace App\Filament\Resources\ApiDevelopers;

use App\Models\ApiDeveloper;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

/**
 * Settings → API developers: who may open the developer portal (/developers) and its live API console.
 * Each developer gets a 6-digit PIN (stored hashed; set by you or generated, shown once).
 */
class ApiDeveloperResource extends Resource
{
    protected static ?string $model = ApiDeveloper::class;

    protected static ?string $slug = 'api-developers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'API developers';

    protected static ?string $modelLabel = 'API developer';

    protected static ?int $navigationSort = 12;

    protected static function allowed(): Response
    {
        return Tenancy::platformCan('api.developers') ? Response::allow() : Response::deny();
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
        return static::allowed();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('company')->label('Company / agency')->maxLength(120),
            TextInput::make('email')->email()->maxLength(255)->helperText('For your records; the PIN is not emailed automatically.'),
            DateTimePicker::make('expires_at')->label('Access until')->seconds(false)->timezone('Asia/Kolkata')->helperText('Leave empty for no end date.'),
            TextInput::make('pin')->label(fn (string $operation) => $operation === 'create' ? '6-digit PIN' : 'New 6-digit PIN (leave empty to keep)')
                ->password()->revealable()->regex('/^\d{6}$/')->validationMessages(['regex' => 'The PIN must be exactly 6 digits.'])
                ->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))
                ->helperText('Give it to the developer privately. They enter it at '.url('/developers').'.'),
            Toggle::make('is_active')->label('Access allowed')->default(true),
            Textarea::make('note')->rows(2)->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold')->description(fn (ApiDeveloper $record) => $record->company),
                TextColumn::make('email')->placeholder('—')->toggleable(),
                IconColumn::make('is_active')->label('Allowed')->boolean(),
                TextColumn::make('expires_at')->label('Until')->dateTime('j M Y, g:i A', 'Asia/Kolkata')->placeholder('No end date'),
                TextColumn::make('last_used_at')->label('Last opened')->since()->placeholder('Never'),
            ])
            ->headerActions([
                Action::make('portal')->label('Open developer portal')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')->url(url('/developers'), shouldOpenInNewTab: true),
            ])
            ->recordActions([
                Action::make('newPin')->label('Generate new PIN')->icon('heroicon-m-key')->color('gray')->requiresConfirmation()
                    ->modalDescription('A new random PIN replaces the old one at once.')
                    ->action(function (ApiDeveloper $record) {
                        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $record->forceFill(['pin_hash' => Hash::make($pin), 'failed_attempts' => 0, 'locked_until' => null])->save();
                        activity()->performedOn($record)->causedBy(auth()->user())->log('developer PIN changed');
                        Notification::make()->success()->persistent()->title('New PIN for '.$record->name)
                            ->body(new \Illuminate\Support\HtmlString('PIN: <code>'.e($pin).'</code><br>Share it privately. It is not shown again.'))->send();
                    }),
                EditAction::make()->mutateDataUsing(fn (array $data) => static::hashPin($data)),
            ])
            ->emptyStateHeading('No developers yet')
            ->emptyStateDescription('Add the app developer with a 6-digit PIN; they open '.url('/developers').' to read the API documentation and try every endpoint.');
    }

    /** The PIN is never stored: only its hash. */
    public static function hashPin(array $data): array
    {
        if (filled($data['pin'] ?? null)) {
            $data['pin_hash'] = Hash::make((string) $data['pin']);
            $data['failed_attempts'] = 0;
            $data['locked_until'] = null;
        }
        unset($data['pin']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageApiDevelopers::route('/'),
        ];
    }
}
