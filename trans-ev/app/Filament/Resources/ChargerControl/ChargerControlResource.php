<?php

namespace App\Filament\Resources\ChargerControl;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Services\ChargingSessionService;
use App\Services\Ocpp\OcppCommandService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Phase F: Network → Charger control. Every charger's OCPP link (identity, protocol, password), whether it is online,
 * what it reported at boot, open faults, and remote commands: start / stop charging, restart, unlock, availability,
 * status request, firmware update and diagnostics upload.
 */
class ChargerControlResource extends Resource
{
    protected static ?string $model = Charger::class;

    protected static ?string $slug = 'charger-control';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Charger control';

    protected static ?string $modelLabel = 'charger';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'code';

    // Access is by OCPP permissions (not the station Charger policy): ocpp.view to see, worksAt for each charger.
    public static function getViewAnyAuthorizationResponse(): \Illuminate\Auth\Access\Response
    {
        return auth()->user()?->can('ocpp.view') && \App\Support\Features::enabled('ocpp') ? \Illuminate\Auth\Access\Response::allow() : \Illuminate\Auth\Access\Response::deny();
    }

    public static function getViewAuthorizationResponse(\Illuminate\Database\Eloquent\Model $record): \Illuminate\Auth\Access\Response
    {
        return static::getViewAnyAuthorizationResponse()->allowed() && ChargingSessionService::worksAt(auth()->user(), $record->station_id)
            ? \Illuminate\Auth\Access\Response::allow() : \Illuminate\Auth\Access\Response::denyAsNotFound();
    }

    public static function getCreateAuthorizationResponse(): \Illuminate\Auth\Access\Response
    {
        return \Illuminate\Auth\Access\Response::deny();
    }

    public static function getEditAuthorizationResponse(\Illuminate\Database\Eloquent\Model $record): \Illuminate\Auth\Access\Response
    {
        return \Illuminate\Auth\Access\Response::deny();
    }

    public static function getDeleteAuthorizationResponse(\Illuminate\Database\Eloquent\Model $record): \Illuminate\Auth\Access\Response
    {
        return \Illuminate\Auth\Access\Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['station:id,name,city', 'connectorPoints'])
            ->withCount(['ocppFaults as open_faults_count' => fn ($q) => $q->whereNull('cleared_at')])
            ->whereIn('station_id', ChargingSessionService::stations(auth()->user())->select('id'));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereNotNull('ocpp_identity')->where('ocpp_connected', false)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'OCPP chargers offline';
    }

    public static function allowed(string $permission, Charger $charger): bool
    {
        return (bool) auth()->user()?->can($permission) && ChargingSessionService::worksAt(auth()->user(), $charger->station_id);
    }

    public static function endpoint(Charger $c): string
    {
        return rtrim((string) config('services.ocpp.public_url'), '/').'/'.($c->ocpp_identity ?: '{identity}');
    }

    /** All OCPP actions, used on the list (grouped) and on the charger page. */
    public static function actions(): array
    {
        $svc = fn () => app(OcppCommandService::class);
        $sent = fn (string $what) => Notification::make()->success()->title($what.' sent to the charger')->body('The answer appears under Commands within a few seconds.')->send();
        $online = fn (Charger $record) => $record->ocpp_identity && $record->ocpp_connected;
        $canCommand = fn (?Charger $record = null) => $record !== null && $online($record) && static::allowed('ocpp.command', $record);
        $connectors = fn (Charger $record) => $record->connectorPoints->mapWithKeys(fn (Connector $c) => [$c->id => 'Connector '.$c->connector_no.' · '.$c->type->value.' · '.($c->live_status?->getLabel() ?? '—')])->all();

        return [
            Action::make('setup')->label('OCPP setup')->icon('heroicon-m-cog-6-tooth')->color('gray')
                ->visible(fn (Charger $record) => static::allowed('ocpp.configure', $record))
                ->fillForm(fn (Charger $record) => ['ocpp_identity' => $record->ocpp_identity ?: Str::upper(Str::slug($record->code)), 'ocpp_protocol' => $record->ocpp_protocol ?: '1.6J', 'new_password' => ! $record->ocpp_password])
                ->schema([
                    TextInput::make('ocpp_identity')->label('Charge point identity')->required()->maxLength(48)->regex('/^[A-Za-z0-9._\-]+$/')
                        ->unique('chargers', 'ocpp_identity', ignoreRecord: true)->helperText('Set the same ID in the charger (chargeBoxId / station ID).'),
                    Select::make('ocpp_protocol')->label('Protocol')->options(['1.6J' => 'OCPP 1.6J', '2.0.1' => 'OCPP 2.0.1'])->required(),
                    Toggle::make('new_password')->label('Make a new password')->helperText('The charger connects with Basic auth (identity + password). The old password stops working.'),
                ])
                ->action(function (Charger $record, array $data) {
                    $record->forceFill(['ocpp_identity' => $data['ocpp_identity'], 'ocpp_protocol' => $data['ocpp_protocol']]);
                    $password = null;
                    if ($data['new_password'] ?? false) {
                        $password = Str::password(24, symbols: false);
                        $record->ocpp_password = Hash::make($password);
                    }
                    $record->save();
                    activity()->performedOn($record)->causedBy(auth()->user())->withProperties(['identity' => $record->ocpp_identity, 'protocol' => $record->ocpp_protocol, 'new_password' => (bool) $password])->log('OCPP set up');
                    Notification::make()->success()->persistent()->title('OCPP settings saved')
                        ->body(new \Illuminate\Support\HtmlString('Server URL: '.e(static::endpoint($record)).'<br>Identity: '.e($record->ocpp_identity).($password ? '<br>Password: <code>'.e($password).'</code><br><b>Copy it now; it is not shown again.</b>' : '')))
                        ->send();
                }),
            Action::make('start')->label('Start charging')->icon('heroicon-m-play')->color('success')
                ->visible(fn (Charger $record) => $online($record) && static::allowed('ocpp.command', $record))
                ->schema(fn (Charger $record) => [Select::make('booking')->label('Booking')->required()->options(
                    Booking::query()->where('charger_id', $record->id)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
                        ->where('start_at', '<=', now()->addHours(2))->where('end_at', '>', now())->orderBy('start_at')->get()
                        ->mapWithKeys(fn (Booking $b) => [$b->id => $b->code.' · '.$b->start_at->timezone('Asia/Kolkata')->format('g:i A').' · '.($b->vehicle_snapshot['registration_no'] ?? '')])
                )->helperText('Bookings on this charger from now to the next two hours.')])
                ->action(function (Charger $record, array $data) use ($svc, $sent) {
                    $svc()->remoteStart(Booking::query()->where('charger_id', $record->id)->findOrFail($data['booking']), auth()->user());
                    $sent('Start');
                }),
            Action::make('stop')->label('Stop charging')->icon('heroicon-m-stop')->color('danger')
                ->visible(fn (Charger $record) => $online($record) && static::allowed('ocpp.command', $record) && ChargingSession::query()->where('charger_id', $record->id)->where('status', 'active')->whereNotNull('ocpp_transaction_id')->exists())
                ->schema(fn (Charger $record) => [Select::make('session')->label('Session')->required()->options(
                    ChargingSession::query()->with('booking')->where('charger_id', $record->id)->where('status', 'active')->whereNotNull('ocpp_transaction_id')->get()
                        ->mapWithKeys(fn (ChargingSession $s) => [$s->id => ($s->booking?->code ?? 'Session '.$s->id).' · since '.$s->started_at->timezone('Asia/Kolkata')->format('g:i A')])
                )])
                ->requiresConfirmation()
                ->action(function (Charger $record, array $data) use ($svc, $sent) {
                    $svc()->remoteStop(ChargingSession::query()->where('charger_id', $record->id)->findOrFail($data['session']), auth()->user());
                    $sent('Stop');
                }),
            ActionGroup::make([
                Action::make('reset')->label('Restart charger')->icon('heroicon-m-arrow-path')->visible($canCommand)
                    ->schema([Select::make('type')->options(['soft' => 'Soft (finish sessions first)', 'hard' => 'Hard (immediately)'])->default('soft')->required()])
                    ->requiresConfirmation()
                    ->action(function (Charger $record, array $data) use ($svc, $sent) {
                        $svc()->queue($record, 'reset', ['type' => $data['type']], auth()->user());
                        $sent('Restart');
                    }),
                Action::make('unlock')->label('Unlock connector')->icon('heroicon-m-lock-open')->visible($canCommand)
                    ->schema(fn (Charger $record) => [Select::make('connector')->options($connectors($record))->required()])
                    ->action(function (Charger $record, array $data) use ($svc, $sent) {
                        $c = Connector::query()->where('charger_id', $record->id)->findOrFail($data['connector']);
                        $svc()->queue($record, 'unlock', ['connectorNo' => $c->connector_no], auth()->user(), $c);
                        $sent('Unlock');
                    }),
                Action::make('availability')->label('Change availability')->icon('heroicon-m-power')->visible($canCommand)
                    ->schema(fn (Charger $record) => [
                        Select::make('connector')->label('Connector')->options(['' => 'Whole charger'] + $connectors($record)),
                        Toggle::make('available')->label('Available for charging')->default(true),
                    ])
                    ->action(function (Charger $record, array $data) use ($svc, $sent) {
                        $c = filled($data['connector'] ?? null) ? Connector::query()->where('charger_id', $record->id)->find($data['connector']) : null;
                        $svc()->queue($record, 'availability', ['connectorNo' => $c?->connector_no ?? 0, 'available' => (bool) $data['available']], auth()->user(), $c);
                        $sent('Availability change');
                    }),
                Action::make('trigger')->label('Ask for status')->icon('heroicon-m-signal')->visible($canCommand)
                    ->action(function (Charger $record) use ($svc, $sent) {
                        $svc()->queue($record, 'trigger', ['message' => 'StatusNotification'], auth()->user());
                        $sent('Status request');
                    }),
                Action::make('firmware')->label('Update firmware')->icon('heroicon-m-arrow-up-tray')->visible($canCommand)
                    ->schema([TextInput::make('location')->label('Firmware file URL')->url()->required()->helperText('HTTPS or FTP address the charger downloads the firmware from.')])
                    ->requiresConfirmation()
                    ->action(function (Charger $record, array $data) use ($svc, $sent) {
                        $svc()->queue($record, 'update_firmware', ['location' => $data['location']], auth()->user());
                        $sent('Firmware update');
                    }),
                Action::make('diagnostics')->label('Upload diagnostics')->icon('heroicon-m-document-arrow-up')->visible($canCommand)
                    ->schema([TextInput::make('location')->label('Upload to (FTP/HTTPS URL)')->url()->required()])
                    ->action(function (Charger $record, array $data) use ($svc, $sent) {
                        $svc()->queue($record, 'get_diagnostics', ['location' => $data['location']], auth()->user());
                        $sent('Diagnostics request');
                    }),
            ])->label('More')->icon('heroicon-m-ellipsis-vertical')->color('gray')->button(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->poll('20s')
            ->columns([
                TextColumn::make('code')->label('Charger')->searchable(['code', 'ocpp_identity'])->weight('semibold')
                    ->description(fn (Charger $record) => $record->station?->name),
                TextColumn::make('connection')->label('OCPP')->badge()
                    ->state(fn (Charger $record) => ['connected' => 'Online', 'disconnected' => 'Offline', 'manual' => 'Not set up'][$record->ocppState()])
                    ->color(fn (Charger $record) => ['connected' => 'success', 'disconnected' => 'danger', 'manual' => 'gray'][$record->ocppState()])
                    ->description(fn (Charger $record) => $record->ocpp_identity ? $record->ocpp_identity.' · '.($record->ocpp_protocol ?? '1.6J') : null),
                TextColumn::make('last_heartbeat_at')->label('Last seen')->since()->placeholder('—')->dateTimeTooltip('j M Y, g:i:s A', 'Asia/Kolkata'),
                TextColumn::make('connectors_state')->label('Connectors')->html()
                    ->state(fn (Charger $record) => $record->connectorPoints->map(fn (Connector $c) => '<span class="whitespace-nowrap">#'.$c->connector_no.' '.e($c->live_status?->getLabel() ?? '—').'</span>')->implode('<br>')),
                TextColumn::make('open_faults_count')->label('Faults')->badge()->color(fn ($state) => $state ? 'danger' : 'gray')->alignCenter(),
                TextColumn::make('device')->label('Reported device')->toggleable()
                    ->state(fn (Charger $record) => trim(($record->ocpp_vendor ?? '').' '.($record->ocpp_model ?? '')) ?: null)
                    ->description(fn (Charger $record) => $record->firmware_version ? 'Firmware '.$record->firmware_version : null)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('state')->label('Connection')->options(['online' => 'Online', 'offline' => 'Offline', 'manual' => 'Not set up'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'online' => $query->whereNotNull('ocpp_identity')->where('ocpp_connected', true),
                        'offline' => $query->whereNotNull('ocpp_identity')->where('ocpp_connected', false),
                        'manual' => $query->whereNull('ocpp_identity'),
                        default => $query,
                    }),
                SelectFilter::make('station_id')->label('Station')->options(fn () => ChargingSessionService::stations(auth()->user())->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()->label('Open'), ...array_filter(static::actions(), fn ($a) => $a instanceof Action && in_array($a->getName(), ['setup', 'start', 'stop'], true))])
            ->emptyStateHeading('No chargers');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Connection')->columnSpan(1)->schema([
                TextEntry::make('state')->label('OCPP')->badge()
                    ->state(fn (Charger $record) => ['connected' => 'Online', 'disconnected' => 'Offline', 'manual' => 'Not set up'][$record->ocppState()])
                    ->color(fn (Charger $record) => ['connected' => 'success', 'disconnected' => 'danger', 'manual' => 'gray'][$record->ocppState()]),
                TextEntry::make('endpoint')->label('Server URL for this charger')->state(fn (Charger $record) => static::endpoint($record))->copyable(),
                TextEntry::make('ocpp_protocol')->label('Protocol')->placeholder('—'),
                TextEntry::make('password')->label('Password')->state(fn (Charger $record) => $record->ocpp_password ? 'Set (hidden)' : 'Not set'),
                TextEntry::make('last_heartbeat_at')->label('Last seen')->since()->placeholder('—'),
                TextEntry::make('ocpp_connected_at')->label('Connected since')->dateTime('j M Y, g:i A', 'Asia/Kolkata')->placeholder('—'),
                TextEntry::make('ocpp_boot_at')->label('Last boot')->dateTime('j M Y, g:i A', 'Asia/Kolkata')->placeholder('—'),
            ]),
            Section::make('Charger')->columnSpan(2)->columns(2)->schema([
                TextEntry::make('code'),
                TextEntry::make('station.name')->label('Station'),
                TextEntry::make('reported')->label('Reported by the charger')->state(fn (Charger $record) => trim(($record->ocpp_vendor ?? '').' '.($record->ocpp_model ?? '')) ?: null)->placeholder('Not booted yet'),
                TextEntry::make('firmware_version')->label('Firmware')->placeholder('—')
                    ->helperText(fn (Charger $record) => $record->ocpp_firmware_status ? 'Last update/diagnostics status: '.$record->ocpp_firmware_status : null),
                TextEntry::make('serial_no')->label('Serial number')->placeholder('—'),
                TextEntry::make('meter_serial')->label('Meter serial')->placeholder('—'),
                TextEntry::make('connectors')->label('Connectors')->columnSpanFull()->html()
                    ->state(fn (Charger $record) => $record->connectorPoints->map(fn (Connector $c) => '#'.$c->connector_no.' '.e($c->type->value).' — <b>'.e($c->live_status?->getLabel() ?? '—').'</b>'.($c->live_note ? ' · '.e($c->live_note) : ''))->implode('<br>')),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\CommandsRelationManager::class, RelationManagers\FaultsRelationManager::class, RelationManagers\MessagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChargerControl::route('/'),
            'view' => Pages\ViewChargerControl::route('/{record}'),
        ];
    }
}
