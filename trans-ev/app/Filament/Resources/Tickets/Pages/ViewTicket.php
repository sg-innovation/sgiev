<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketPriority;
use App\Models\User;
use App\Services\TicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;

/** A ticket's conversation (with internal notes for agents) and the agent actions. */
class ViewTicket extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TicketResource::class;

    protected string $view = 'filament.tickets.view';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(app(TicketService::class)->canView(auth()->user(), $this->record), 404);
    }

    public function getTitle(): string
    {
        return $this->record->number.' · '.$this->record->subject;
    }

    protected function service(): TicketService
    {
        return app(TicketService::class);
    }

    public function isAgent(): bool
    {
        return $this->service()->canWork(auth()->user(), $this->record);
    }

    public function getThread(): array
    {
        $t = $this->record->fresh(['messages.user', 'requester', 'requesterCompany', 'category', 'priority', 'assignee', 'booking.station', 'company']);
        $this->record = $t;
        $agent = $this->isAgent();
        $tz = Station::TIMEZONE;

        return [
            'ticket' => $t,
            'agent' => $agent,
            'messages' => $t->messages->filter(fn (TicketMessage $m) => $agent || ! $m->is_internal)->map(fn (TicketMessage $m) => [
                'id' => $m->id,
                'author' => $m->user?->name ?? 'System',
                'fromRequester' => $m->user_id === $t->requester_id,
                'internal' => $m->is_internal,
                'event' => $m->event === 'system',
                'body' => $m->body,
                'at' => $m->created_at->setTimezone($tz)->format('d M Y, h:i A'),
                'files' => collect($m->attachments ?? [])->map(fn ($f, $i) => ['name' => $f['name'], 'url' => route('support.file', [$t, $m->id, $i])])->values(),
            ])->values(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')->label('Reply')->icon('heroicon-m-chat-bubble-left-right')
                ->visible(fn () => $this->isAgent() || auth()->id() === $this->record->requester_id)
                ->modalWidth('2xl')
                ->schema(fn () => [
                    Textarea::make('body')->label('Message')->required()->minLength(2)->maxLength(5000)->rows(6),
                    Toggle::make('internal')->label('Internal note (not visible to the requester)')->visible(fn () => $this->isAgent()),
                    Select::make('status')->label('Then set status to')->visible(fn () => $this->isAgent())
                        ->options(collect(Ticket::STATUSES)->except('closed')->all())->placeholder('Waiting on requester (default for replies)'),
                    FileUpload::make('files')->label('Attachments')->multiple()->maxFiles(TicketService::maxFiles())->maxSize(TicketService::maxKb())
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $this->service()->reply($this->record, auth()->user(), $data['body'], (bool) ($data['internal'] ?? false), array_values($data['files'] ?? []), $data['status'] ?? null);
                    Notification::make()->success()->title(($data['internal'] ?? false) ? 'Note added' : 'Reply sent')->send();
                }),
            Action::make('assign')->label(fn () => $this->record->assigned_to ? 'Reassign' : 'Assign')->icon('heroicon-m-user-plus')->color('gray')
                ->visible(fn () => auth()->user()->can('tickets.assign') && \App\Support\Tenancy::owns($this->record->company_id))
                ->schema(fn () => [
                    Select::make('agent')->label('Agent')->searchable()->placeholder('Unassigned')
                        ->options(fn () => $this->service()->eligibleAgents($this->record)->mapWithKeys(fn (User $u) => [$u->id => $u->name.' · '.($u->company?->trade_name ?? 'Platform')])->all())
                        ->default($this->record->assigned_to),
                ])
                ->action(function (array $data) {
                    $this->service()->assign($this->record, filled($data['agent'] ?? null) ? User::find($data['agent']) : null, auth()->user());
                    Notification::make()->success()->title('Assignment updated')->send();
                }),
            Action::make('take')->label('Assign to me')->icon('heroicon-m-hand-raised')->color('gray')
                ->visible(fn () => $this->isAgent() && $this->record->assigned_to !== auth()->id() && auth()->user()->can('tickets.assign'))
                ->action(fn () => $this->service()->assign($this->record, auth()->user(), auth()->user())),
            Action::make('status')->label('Change status')->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn () => $this->isAgent())
                ->schema(fn () => [
                    Select::make('status')->options(Ticket::STATUSES)->required()->default($this->record->status),
                ])
                ->action(function (array $data) {
                    $this->service()->setStatus($this->record, $data['status'], auth()->user());
                    Notification::make()->success()->title('Status updated')->send();
                }),
            Action::make('priority')->label('Priority')->icon('heroicon-m-flag')->color('gray')
                ->visible(fn () => $this->isAgent())
                ->schema(fn () => [Select::make('priority_id')->options(TicketPriority::orderBy('sort')->pluck('name', 'id')->all())->required()->default($this->record->priority_id)])
                ->action(function (array $data) {
                    $p = TicketPriority::findOrFail($data['priority_id']);
                    $start = $this->record->created_at;
                    $this->record->forceFill([
                        'priority_id' => $p->id,
                        'first_response_due_at' => $start->copy()->addMinutes($p->first_response_minutes),
                        'resolution_due_at' => $start->copy()->addMinutes($p->resolution_minutes),
                    ])->save();
                    TicketMessage::create(['ticket_id' => $this->record->id, 'user_id' => auth()->id(), 'body' => 'Priority: '.$p->name, 'is_internal' => true, 'event' => 'system']);
                }),
            Action::make('resolve')->label('Mark resolved')->icon('heroicon-m-check-circle')->color('success')
                ->visible(fn () => $this->isAgent() && $this->record->isActive())
                ->requiresConfirmation()
                ->action(fn () => $this->service()->setStatus($this->record, 'resolved', auth()->user())),
        ];
    }
}
