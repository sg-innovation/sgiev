<?php

namespace App\Filament\Resources\NotificationLog;

use App\Models\NotificationDelivery;
use App\Models\Station;
use App\Support\Messages;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Notification log (D1): every email, SMS, WhatsApp and push message, with its outcome. Read-only. */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static ?string $slug = 'notification-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Notification log';

    protected static ?string $modelLabel = 'notification';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return Tenancy::platformCan('notifications.log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M, h:i:s A', Station::TIMEZONE),
                TextColumn::make('template')->label('Message')->formatStateUsing(fn (string $state) => Messages::TEMPLATES[$state]['label'] ?? $state)
                    ->description(fn (NotificationDelivery $record) => $record->preview)->wrap()->searchable(),
                TextColumn::make('channel')->badge()->formatStateUsing(fn (string $state) => NotificationDelivery::CHANNELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'mail' => 'info', 'sms' => 'warning', 'whatsapp' => 'success', default => 'gray' }),
                TextColumn::make('user.name')->label('To')->placeholder('—')->description(fn (NotificationDelivery $record) => $record->recipient)->searchable(['recipient']),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => NotificationDelivery::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'sent' => 'success', 'failed' => 'danger', 'simulated' => 'info', default => 'gray' })
                    ->description(fn (NotificationDelivery $record) => $record->error),
                TextColumn::make('provider')->placeholder('—')->description(fn (NotificationDelivery $record) => $record->provider_message_id)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(NotificationDelivery::CHANNELS),
                SelectFilter::make('status')->options(NotificationDelivery::STATUSES),
                SelectFilter::make('template')->label('Message')->options(collect(Messages::TEMPLATES)->map(fn ($t) => $t['label'])->all())->searchable(),
            ])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Every email, SMS, WhatsApp and push notification appears here with its result.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListNotificationLog::route('/')];
    }
}
