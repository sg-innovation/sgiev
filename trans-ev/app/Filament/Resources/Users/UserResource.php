<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Station;
use App\Models\User;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Staff accounts (customers are not managed here). */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['company', 'roles'])->role(Role::staff());

        return Tenancy::scope($query);
    }

    /** Roles the current admin may assign. */
    public static function roleOptions(): array
    {
        $roles = Role::assignableByCompany();

        return (auth()->user()?->hasRole(Role::SuperAdmin->value) ?? false) ? [Role::SuperAdmin->value => 'Platform super admin', Role::RegionalManager->value => 'Regional manager (franchise)'] + $roles : $roles;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->maxLength(20),
                Select::make('company_id')->label('Company')->relationship('company', 'trade_name')->searchable()->preload()
                    ->visible(fn () => Tenancy::isPlatformAdmin())
                    ->required(fn (Get $get) => $get('role') !== Role::SuperAdmin->value),
                Select::make('role')->options(fn () => static::roleOptions())->required()->live()
                    ->afterStateHydrated(fn (Select $component, ?User $record) => $component->state($record?->roles->first()?->name))
                    ->dehydrated(false),
                Select::make('stations')->label('Assigned stations')->multiple()->preload()->searchable()
                    ->relationship('stations', 'name', fn (Builder $query) => Tenancy::scope($query))
                    ->visible(fn (Get $get) => $get('role') === Role::StationManager->value)
                    ->helperText('Station managers only see and manage these stations.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium')->description(fn (User $record) => $record->email),
                TextColumn::make('company.trade_name')->label('Company')->placeholder('Platform')->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('roles.name')->label('Role')->badge()
                    ->formatStateUsing(fn (string $state) => static::roleOptions()[$state] ?? str($state)->headline()),
                TextColumn::make('status')->badge()->state(fn (User $record) => $record->blocked_at ? 'Blocked' : 'Active')
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'danger'),
                TextColumn::make('last_login_at')->label('Last login')->since()->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('company_id')->label('Company')->relationship('company', 'trade_name')->visible(fn () => Tenancy::isPlatformAdmin()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('block')->icon('heroicon-m-no-symbol')->color('danger')->requiresConfirmation()
                    ->visible(fn (User $record) => $record->blocked_at === null && auth()->user()->can('block', $record))
                    ->action(function (User $record) {
                        $record->forceFill(['blocked_at' => now()])->save();
                        activity()->performedOn($record)->log('blocked');
                        Notification::make()->success()->title('Access blocked')->send();
                    }),
                Action::make('unblock')->icon('heroicon-m-check-circle')->color('success')->requiresConfirmation()
                    ->visible(fn (User $record) => $record->blocked_at !== null && auth()->user()->can('block', $record))
                    ->action(function (User $record) {
                        $record->forceFill(['blocked_at' => null])->save();
                        activity()->performedOn($record)->log('unblocked');
                        Notification::make()->success()->title('Access restored')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
