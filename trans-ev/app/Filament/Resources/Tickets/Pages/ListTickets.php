<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    public function getSubheading(): ?string
    {
        $base = TicketResource::getEloquentQuery();
        $active = (clone $base)->whereIn('status', \App\Models\Ticket::ACTIVE);
        $overdue = (clone $active)->whereNull('sla_paused_at')->where(fn ($q) => $q->where(fn ($a) => $a->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))
            ->orWhere('resolution_due_at', '<', now()))->count();
        $recent = (clone $base)->where('created_at', '>=', now()->subDays(30));
        $responded = (clone $recent)->whereNotNull('first_responded_at')->count();
        $firstMet = $responded ? (int) round((clone $recent)->whereNotNull('first_responded_at')->whereNull('first_response_breached_at')->count() / $responded * 100) : null;
        $resolved = (clone $recent)->whereNotNull('resolved_at')->count();
        $resMet = $resolved ? (int) round((clone $recent)->whereNotNull('resolved_at')->whereNull('resolution_breached_at')->count() / $resolved * 100) : null;

        return $active->count().' active · '.$overdue.' overdue · last 30 days: first reply on time '.($firstMet === null ? '—' : $firstMet.'%').', resolved on time '.($resMet === null ? '—' : $resMet.'%');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New ticket')];
    }
}
