<?php

namespace App\Filament\Resources\Reconciliation\Widgets;

use App\Filament\Resources\Reconciliation\ReconciliationResource;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReconciliationStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $open = ReconciliationResource::getEloquentQuery()->where('status', ReconciliationException::OPEN);
        $by = (clone $open)->reorder()->selectRaw('severity, count(*) c')->groupBy('severity')->pluck('c', 'severity');
        $money = (clone $open)->whereIn('severity', ['critical', 'high'])->sum('expected_amount');
        $run = ReconciliationRun::query()->latest('id')->first();
        $stats = $run?->stats ?? [];

        return [
            Stat::make('Critical', (string) ($by['critical'] ?? 0))->description('Money may be with the wrong party')
                ->descriptionIcon('heroicon-m-exclamation-triangle')->color(($by['critical'] ?? 0) ? 'danger' : 'success'),
            Stat::make('High', (string) ($by['high'] ?? 0))->description('₹'.number_format((float) $money, 2).' involved (critical + high)')
                ->descriptionIcon('heroicon-m-banknotes')->color(($by['high'] ?? 0) ? 'warning' : 'success'),
            Stat::make('Medium / low', (string) (($by['medium'] ?? 0) + ($by['low'] ?? 0)))->description('GST documents and wallet ledger')
                ->descriptionIcon('heroicon-m-document-text')->color('gray'),
            Stat::make('Last run', $run ? $run->started_at->timezone('Asia/Kolkata')->format('d M, h:i A') : 'Never')
                ->description($run ? ($run->error ? 'Failed: '.str($run->error)->limit(40)
                    : "{$run->new_exceptions} new · {$run->cleared_exceptions} cleared · ".($stats['enquiries'] ?? 0).' gateway checks') : 'Runs daily; use Run now to start')
                ->descriptionIcon($run?->error ? 'heroicon-m-x-circle' : 'heroicon-m-clock')->color($run?->error ? 'danger' : 'primary'),
        ];
    }
}
