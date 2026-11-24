<?php

namespace App\Filament\Resources\Reconciliation\Pages;

use App\Filament\Resources\Reconciliation\ReconciliationResource;
use App\Filament\Resources\Reconciliation\Widgets\ReconciliationStats;
use App\Models\ReconciliationRun;
use App\Policies\ReconciliationExceptionPolicy;
use App\Services\ReconciliationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReconciliationExceptions extends ListRecords
{
    protected static string $resource = ReconciliationResource::class;

    protected static ?string $title = 'Reconciliation';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Daily cross-check of the payment gateway, payments, bookings, refunds, GST documents and operator wallets.';
    }

    protected function getHeaderWidgets(): array
    {
        return [ReconciliationStats::class];
    }

    public function getTabs(): array
    {
        $q = fn () => ReconciliationResource::getEloquentQuery();

        return [
            'open' => Tab::make('Open')->badge(fn () => $q()->where('status', 'open')->count())->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'open')),
            'mine' => Tab::make('Assigned to me')->badge(fn () => $q()->where('status', 'open')->where('assigned_to', auth()->id())->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'open')->where('assigned_to', auth()->id()))
                ->visible(fn () => ReconciliationExceptionPolicy::manages(auth()->user())),
            'resolved' => Tab::make('Resolved')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'resolved')),
            'ignored' => Tab::make('Ignored')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'ignored')),
            'all' => Tab::make('All'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')->label('Run history')->icon('heroicon-m-clock')->color('gray')
                ->modalHeading('Recent reconciliation runs')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.reconciliation.runs', ['runs' => ReconciliationRun::query()->with('triggeredBy')->latest('id')->limit(15)->get()])),
            Action::make('run')->label('Run now')->icon('heroicon-m-arrow-path')
                ->visible(fn () => ReconciliationExceptionPolicy::manages(auth()->user()))
                ->modalDescription('Cross-checks the payment gateway, payments, bookings, refunds, GST documents and operator wallets. Problems that have gone away are cleared automatically.')
                ->schema([Toggle::make('enquire')->label('Ask the payment gateway about recent payments')->default(true)
                    ->helperText('Turn off for a quick database-only check.')])
                ->action(function (array $data) {
                    $run = app(ReconciliationService::class)->run(auth()->user(), (bool) $data['enquire']);
                    $run->error
                        ? Notification::make()->danger()->title('Reconciliation failed')->body($run->error)->send()
                        : Notification::make()->success()->title('Reconciliation finished')->body("{$run->new_exceptions} new, {$run->cleared_exceptions} cleared, {$run->open_exceptions} open.")->send();
                }),
        ];
    }
}
