<?php

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Resources\ContactMessages\Pages\EditContactMessage;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Models\ContactMessage;
use App\Models\Station;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Support inbox for queries sent through the website contact form. */
class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Customer queries';

    protected static ?string $modelLabel = 'query';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canViewAny(): bool
    {
        return (auth()->user()?->isPlatformStaff() ?? false) && auth()->user()->can('support.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (auth()->user()?->isPlatformStaff() ?? false) && auth()->user()->can('support.manage');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Handling')->columns(2)->columnSpanFull()->schema([
                Select::make('status')->options(ContactMessage::STATUSES)->required()->native(false),
                Select::make('assigned_to')->label('Assigned to')
                    ->options(fn () => User::query()->role(['super_admin', 'operations_admin', 'finance', 'station_manager'])->pluck('name', 'id'))
                    ->searchable(),
                Textarea::make('internal_notes')->label('Internal notes (not visible to customer)')->rows(5)->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = Station::TIMEZONE;

        return $schema->columns(3)->components([
            Section::make('Message')->columnSpan(2)->schema([
                TextEntry::make('subject')->weight('bold')->size('lg'),
                TextEntry::make('message')->prose()->columnSpanFull()->formatStateUsing(fn (string $state) => nl2br(e($state)))->html(),
            ]),
            Section::make('Details')->columnSpan(1)->schema([
                TextEntry::make('reference')->copyable(),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => ContactMessage::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'new' => 'warning', 'in_progress' => 'info', 'resolved' => 'success', default => 'gray' }),
                TextEntry::make('topic')->formatStateUsing(fn ($state) => \App\Models\Lookup::label('contact_topic', $state)),
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('phone')->prefix('+91 ')->placeholder('—'),
                TextEntry::make('booking_code')->placeholder('—'),
                TextEntry::make('created_at')->label('Received')->dateTime('d M Y, h:i A', $tz),
                TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextEntry::make('internal_notes')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->weight('medium')
                    ->description(fn (ContactMessage $r) => $r->created_at->setTimezone(Station::TIMEZONE)->format('d M, h:i A')),
                TextColumn::make('subject')->searchable()->limit(45)->description(fn (ContactMessage $r) => \App\Models\Lookup::label('contact_topic', $r->topic)),
                TextColumn::make('name')->searchable()->description(fn (ContactMessage $r) => $r->email),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ContactMessage::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'new' => 'warning', 'in_progress' => 'info', 'resolved' => 'success', default => 'gray' }),
                TextColumn::make('assignee.name')->label('Assigned')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ContactMessage::STATUSES)->default('new'),
                SelectFilter::make('topic')->options(fn () => \App\Models\Lookup::options('contact_topic', true)),
            ])
            ->recordActions([
                ViewAction::make(), EditAction::make()->label('Handle'),
                \Filament\Actions\Action::make('toTicket')->label('Convert to ticket')->icon('heroicon-m-lifebuoy')->color('gray')
                    ->visible(fn ($record) => ! $record->ticket_id && auth()->user()->can('tickets.reply'))
                    ->requiresConfirmation()
                    ->modalDescription(fn ($record) => \App\Models\User::query()->where('email', strtolower($record->email))->exists()
                        ? 'Opens a support ticket for this customer’s account, with this message as the first post.'
                        : 'No account uses '.$record->email.'. A customer account will be created and a set-password link emailed, so they can follow the ticket.')
                    ->action(function ($record) {
                        $t = app(\App\Services\TicketService::class)->fromContactMessage($record, auth()->user());
                        \Filament\Notifications\Notification::make()->success()->title("Ticket {$t->number} opened")
                            ->actions([\Filament\Actions\Action::make('open')->label('Open ticket')->url(\App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $t]))])->send();
                    }),
                \Filament\Actions\Action::make('openTicket')->label('Open ticket')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->visible(fn ($record) => (bool) $record->ticket_id)
                    ->url(fn ($record) => \App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $record->ticket_id])),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = ContactMessage::query()->where('status', 'new')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
            'view' => ViewContactMessage::route('/{record}'),
            'edit' => EditContactMessage::route('/{record}/edit'),
        ];
    }
}
