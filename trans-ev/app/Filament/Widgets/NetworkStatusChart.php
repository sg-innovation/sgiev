<?php

namespace App\Filament\Widgets;

use App\Enums\StationStatus;
use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Charger;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Charger network health (status colours with labels in the legend, never colour alone). */
class NetworkStatusChart extends ChartWidget
{
    use DashboardData;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Charger status right now';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $chargers = Charger::query()->with('station:id,status')->whereIn('station_id', static::scopedStations()->pluck('id'))->get(['id', 'station_id', 'status']);

        // A charger at an offline/maintenance station is effectively in that state.
        $effective = $chargers->map(fn (Charger $c) => $c->station->status !== StationStatus::Online ? $c->station->status : $c->status);
        $count = fn (StationStatus $s) => $effective->filter(fn ($st) => $st === $s)->count();

        $online = $count(StationStatus::Online);
        $maint = $count(StationStatus::Maintenance);
        $offline = $count(StationStatus::Offline);

        return [
            'datasets' => [[
                'data' => [$online, $maint, $offline],
                'backgroundColor' => [self::STATUS_GOOD, self::STATUS_WARNING, self::STATUS_CRITICAL],
                'borderWidth' => 2,
                'borderColor' => 'rgba(255,255,255,0.9)',
                'hoverOffset' => 6,
            ]],
            'labels' => ["Online ({$online})", "Maintenance ({$maint})", "Offline ({$offline})"],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            cutout: '68%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle' } } },
            scales: { x: { display: false }, y: { display: false } },
        }
        JS);
    }
}
