<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Services\TicketService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Support tickets in admin. Agents (tickets.view/reply/assign) work the queue of the company that handles a ticket;
 * every staff member can raise tickets to Trans EV and follow the ones they (or their company) raised.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Support tickets';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['requester', 'requesterCompany', 'category', 'priority', 'assignee', 'company']);

        return $query->where(function (Builder $q) use ($user) {
            $q->where('requester_id', $user->id);
            if ($user->can('tickets.view')) {
                $companyId = Tenancy::companyId($user);
                $companyId === null
                    ? $q->orWhereRaw('1 = 1')
                    : $q->orWhere('company_id', $companyId)->orWhere('requester_company_id', $companyId);
            }
        });
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return auth()->user()?->hasAnyRole(\App\Enums\Role::staff()) ? Response::allow() : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return app(TicketService::class)->canView(auth()->user(), $record) ? Response::allow() : Response::denyAsNotFound();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::getViewAnyAuthorizationResponse();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user?->can('tickets.view')) {
            return null;
        }
        $n = static::getEloquentQuery()->whereIn('status', ['open', 'in_progress'])
            ->where(fn (Builder $q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $user->id))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Open tickets assigned to you or unassigned';
    }

    public static function table(Table $table): Table
    {
        $tz = Station::TIMEZONE;

        return $table
            ->defaultSort('last_activity_at', 'desc')
            ->poll('60s')
            ->columns([
                TextColumn::make('subject')->searchable(['subject', 'number'])->weight('semibold')->wrap()->limit(70)
                    ->description(fn (Ticket $record) => $record->number.' · '.$record->category?->name),
                TextColumn::make('requester.name')->label('From')
                    ->description(fn (Ticket $record) => $record->requesterCompany?->trade_name ?? 'Customer'),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (Ticket $record) => $record->priority?->color ?? 'gray'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Ticket::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'open' => 'info', 'in_progress' => 'primary', 'waiting_on_customer' => 'warning', 'resolved' => 'success', default => 'gray' }),
                TextColumn::make('sla')->label('SLA')->badge()
                    ->state(fn (Ticket $record) => match ($record->slaState()) { 'overdue' => 'Overdue', 'due_soon' => 'Due soon', 'on_track' => 'On track', default => null })
                    ->color(fn (?string $state) => match ($state) { 'Overdue' => 'danger', 'Due soon' => 'warning', default => 'success' })
                    ->tooltip(fn (Ticket $record) => ($record->first_responded_at ? 'Resolve by ' : 'First reply by ').($record->first_responded_at ? $record->resolution_due_at : $record->first_response_due_at)?->setTimezone($tz)->format('d M, h:i A'))
                    ->placeholder('—'),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('company.trade_name')->label('Handled by')->visible(fn () => Tenancy::isPlatformAdmin())->toggleable(),
                TextColumn::make('last_activity_at')->label('Last activity')->since($tz)->sortable()
                    ->description(fn (Ticket $record) => $record->last_reply_by === 'requester' ? 'Waiting for us' : null),
            ])
            ->filters([
                SelectFilter::make('status')->multiple()->options(Ticket::STATUSES)->default(Ticket::ACTIVE),
                SelectFilter::make('priority_id')->label('Priority')->options(fn () => TicketPriority::orderBy('sort')->pluck('name', 'id')->all()),
                SelectFilter::make('category_id')->label('Category')->options(fn () => TicketCategory::orderBy('sort')->pluck('name', 'id')->all()),
                Filter::make('mine')->label('Assigned to me')->query(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
                Filter::make('unassigned')->label('Unassigned')->query(fn (Builder $query) => $query->whereNull('assigned_to')),
                Filter::make('overdue')->label('SLA overdue')->query(fn (Builder $query) => $query->whereIn('status', Ticket::ACTIVE)
                    ->where(fn (Builder $q) => $q->where(fn (Builder $a) => $a->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))
                        ->orWhere('resolution_due_at', '<', now()))),
                Filter::make('raised_by_me')->label('Raised by me')->query(fn (Builder $query) => $query->where('requester_id', auth()->id())),
            ])
            ->recordUrl(fn (Ticket $record) => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No tickets')
            ->emptyStateDescription('Tickets raised by customers, partners and staff appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
