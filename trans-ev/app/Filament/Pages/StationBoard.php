<?php

namespace App\Filament\Pages;

use App\Models\Station;
use App\Services\ChargingSessionService;
use App\Services\StationBoardService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Network → Live station board (C8): every charger and connector at a station, now and next. Refreshes every 15 s. */
class StationBoard extends Page
{
    protected string $view = 'filament.pages.station-board';

    protected static ?string $slug = 'live-board';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Live station board';

    protected static ?string $title = 'Live station board';

    protected static ?int $navigationSort = 0;

    #[Url]
    public ?int $station = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->can('stations.view') && $u->can('bookings.view')) && \App\Support\Features::enabled('live_dashboard', $u);
    }

    public function mount(): void
    {
        $ids = $this->stationIds();
        if (! $this->station || ! in_array($this->station, $ids, true)) {
            $this->station = $ids[0] ?? null;
        }
    }

    /** @return list<int> */
    private function stationIds(): array
    {
        return ChargingSessionService::stations(auth()->user())->orderBy('name')->pluck('id')->all();
    }

    public function stations(): array
    {
        return ChargingSessionService::stations(auth()->user())->orderBy('name')->get(['id', 'name', 'city'])
            ->map(fn (Station $s) => ['id' => $s->id, 'label' => "{$s->name} · {$s->city}"])->all();
    }

    public function board(): ?array
    {
        if (! $this->station || ! in_array($this->station, $this->stationIds(), true)) {
            return null;
        }

        return app(StationBoardService::class)->build(Station::query()->findOrFail($this->station));
    }

    public function getSubheading(): ?string
    {
        return 'What every charger is doing now and when it is next free. Finish and free times are estimates.';
    }
}
