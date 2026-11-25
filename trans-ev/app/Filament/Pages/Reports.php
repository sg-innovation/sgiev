<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\ChargingSessionService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportService;
use App\Support\Tenancy;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Reports (G1/G2): revenue, sessions, energy, cancellations, no-shows, pending money, GST and platform fees for any
 * date range, grouped by day/week/month/year, per station, charger or (Trans EV) operator. PDF and Excel downloads.
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Business reports';

    protected static ?string $title = 'Business reports';

    protected static ?int $navigationSort = 0;

    public const TABS = ['overview' => 'Overview', 'stations' => 'Stations', 'chargers' => 'Chargers', 'operators' => 'Operators', 'pending' => 'Pending money', 'gst' => 'GST', 'fees' => 'Platform fees'];

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public string $group = 'day';

    #[Url]
    public ?string $company = null;

    #[Url]
    public ?string $station = null;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public ?string $franchise = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('reports.view') && \App\Support\Features::enabled('basic_reports');
    }

    public function mount(): void
    {
        $f = $this->filters();
        $this->from = $f->from->toDateString();
        $this->to = $f->to->toDateString();
        $this->group = $f->group;
        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'overview';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'group', 'company', 'franchise'], true)) {
            $f = $this->filters();
            $this->group = $f->group;
            if (in_array($property, ['company', 'franchise'], true)) {
                $this->station = null;
            }
        }
    }

    public function filters(): ReportFilters
    {
        return ReportFilters::forUser(auth()->user(), [
            'from' => $this->from, 'to' => $this->to, 'group' => $this->group, 'company' => $this->company,
            'stations' => filled($this->station) ? [(int) $this->station] : [],
            'franchise' => $this->franchise,
        ]);
    }

    /** Quick ranges: today, 7 days, this/last month, this/last financial year (April–March). */
    public function preset(string $key): void
    {
        $today = CarbonImmutable::now(ReportFilters::TZ)->startOfDay();
        $fyStart = $today->month >= 4 ? $today->setDate($today->year, 4, 1) : $today->setDate($today->year - 1, 4, 1);
        [$from, $to, $group] = match ($key) {
            'today' => [$today, $today, 'day'],
            '7d' => [$today->subDays(6), $today, 'day'],
            '30d' => [$today->subDays(29), $today, 'day'],
            'month' => [$today->startOfMonth(), $today, 'day'],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), 'day'],
            'fy' => [$fyStart, $today, 'month'],
            'last_fy' => [$fyStart->subYear(), $fyStart->subDay(), 'month'],
            default => [$today->startOfMonth(), $today, 'day'],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->group = $group;
    }

    public function tabs(): array
    {
        $u = auth()->user();
        $tabs = self::TABS;
        if (! \App\Support\Features::enabled('advanced_reports', $u)) {
            unset($tabs['chargers'], $tabs['gst']);
        }
        if (! $this->isPlatform()) {
            unset($tabs['operators']);
        }
        if (! $u->can('payments.view')) {
            unset($tabs['pending']);
        }
        if (! $u->can('invoices.export') && ! $u->can('reports.export')) {
            unset($tabs['gst']);
        }
        if (! ($u->can('platform_fees.manage') || $u->can('wallet.view'))) {
            unset($tabs['fees']);
        }

        return $tabs;
    }

    public function isPlatform(): bool
    {
        return Tenancy::companyId(auth()->user()) === null && ! \App\Services\FranchiseService::isRegionalOnly(auth()->user());
    }

    /** Franchises the user can report on: all for Trans EV staff who manage them, their own for regional managers. */
    public function franchises(): array
    {
        $u = auth()->user();
        $q = \App\Models\Franchise::query()->where('status', 'active')->orderBy('name');
        if (! Tenancy::platformCan('franchises.manage', $u)) {
            if (! \App\Services\FranchiseService::isRegionalOnly($u)) {
                return [];
            }
            $q->whereHas('managers', fn ($m) => $m->whereKey($u->id));
        }

        return $q->pluck('name', 'id')->all();
    }

    public function companies(): array
    {
        return $this->isPlatform() ? Company::query()->where('is_platform', false)->orderBy('trade_name')->pluck('trade_name', 'id')->all() : [];
    }

    public function stations(): array
    {
        return ChargingSessionService::stations(auth()->user())
            ->when($this->isPlatform() && filled($this->company), fn ($q) => $q->where('company_id', (int) $this->company))
            ->orderBy('name')->get(['id', 'name', 'city'])->mapWithKeys(fn ($s) => [$s->id => "{$s->name}, {$s->city}"])->all();
    }

    /** Data for the current tab (computed on every render; queries are aggregate and indexed by time). */
    public function report(): array
    {
        $f = $this->filters();
        $svc = app(ReportService::class);

        return match ($this->tab) {
            'stations' => $svc->breakdown($f, 'station'),
            'chargers' => $svc->breakdown($f, 'charger'),
            'operators' => $this->isPlatform() ? $svc->breakdown($f, 'company') : ['rows' => [], 'totals' => []],
            'pending' => $svc->pending($f),
            'gst' => $svc->gst($f),
            'fees' => $svc->platformFees($f),
            default => ['summary' => $svc->summary($f)] + $svc->breakdown($f, 'period'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label('Download PDF')->icon('heroicon-m-document-arrow-down')->color('gray')
                ->visible(fn () => auth()->user()->can('reports.export') && \App\Support\Features::enabled('advanced_reports'))
                ->action(fn () => $this->download('pdf')),
            Action::make('excel')->label('Download Excel')->icon('heroicon-m-table-cells')
                ->visible(fn () => auth()->user()->can('reports.export') && \App\Support\Features::enabled('advanced_reports'))
                ->action(fn () => $this->download('xlsx')),
        ];
    }

    private function download(string $format): StreamedResponse
    {
        abort_unless(auth()->user()->can('reports.export') && \App\Support\Features::enabled('advanced_reports'), 403);
        $f = $this->filters();
        $file = app(ReportExporter::class)->make($f, $format, array_keys($this->tabs()));
        activity()->causedBy(auth()->user())->withProperties(['format' => $format] + $f->toArray())->log('report downloaded');

        return response()->streamDownload(function () use ($file) {
            readfile($file['path']);
            @unlink($file['path']);
        }, $file['name'], ['Content-Type' => $file['mime']]);
    }

    public function getSubheading(): ?string
    {
        return 'Revenue, sessions, energy, cancellations, GST and fees for any period. Profit is an estimate: net sales minus electricity cost and platform fees.';
    }
}
