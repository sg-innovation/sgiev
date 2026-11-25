<?php

namespace App\Services\Reports;

use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/** Builds the downloadable report: every section the user can see, as a PDF or an Excel workbook (one sheet each). */
class ReportExporter
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * @param  list<string>  $sections  tab keys the user may see (overview, stations, chargers, operators, pending, gst, fees)
     * @return array{path: string, name: string, mime: string}
     */
    public function make(ReportFilters $f, string $format, array $sections): array
    {
        $data = $this->collect($f, $sections);
        $base = 'TransEV-report-'.$f->from->format('Ymd').'-'.$f->to->format('Ymd').($f->companyId ? '-'.Str::slug((string) \App\Models\Company::query()->whereKey($f->companyId)->value('trade_name')) : '');
        $path = tempnam(sys_get_temp_dir(), 'rpt');

        if ($format === 'xlsx') {
            $this->xlsx($f, $data)->save($path);

            return ['path' => $path, 'name' => $base.'.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }
        file_put_contents($path, Pdf::loadView('reports.pdf', ['f' => $f, 'd' => $data, 'generatedAt' => now(ReportFilters::TZ)])
            ->setPaper('a4', 'landscape')->output());

        return ['path' => $path, 'name' => $base.'.pdf', 'mime' => 'application/pdf'];
    }

    private function collect(ReportFilters $f, array $sections): array
    {
        $has = fn (string $s) => in_array($s, $sections, true);

        return array_filter([
            'summary' => $this->reports->summary($f),
            'period' => $this->reports->breakdown($f, 'period'),
            'stations' => $has('stations') ? $this->reports->breakdown($f, 'station') : null,
            'chargers' => $has('chargers') ? $this->reports->breakdown($f, 'charger') : null,
            'operators' => $has('operators') ? $this->reports->breakdown($f, 'company') : null,
            'pending' => $has('pending') ? $this->reports->pending($f) : null,
            'gst' => $has('gst') ? $this->reports->gst($f) : null,
            'fees' => $has('fees') ? $this->reports->platformFees($f) : null,
        ], fn ($v) => $v !== null);
    }

    private function xlsx(ReportFilters $f, array $d): XlsxWriter
    {
        $x = new XlsxWriter;
        $s = $d['summary'];
        $x->sheet('Summary', array_merge(
            [['Trans EV business report', ''], ['Period', $f->label()], ['Scope', $f->scopeLabel()], ['Generated', now(ReportFilters::TZ)->format('j M Y, g:i A').' IST'], ['', '']],
            [['Measure', 'Value']],
            array_map(fn ($k, $label) => [$label.(in_array($k, ReportService::COUNTS, true) || $k === 'kwh' ? '' : ' (₹)'), $s[$k]], array_keys(ReportService::METRICS), ReportService::METRICS),
        ), [34, 22]);
        // The first row of a sheet is its header; the summary's title row is bold too, which reads fine.

        $metricHeader = array_map(fn ($k, $l) => $l.(in_array($k, ReportService::COUNTS, true) || $k === 'kwh' ? '' : ' (₹)'), array_keys(ReportService::METRICS), ReportService::METRICS);
        $table = function (string $first, array $b) use ($metricHeader) {
            $rows = [array_merge([$first], $metricHeader)];
            foreach ($b['rows'] as $r) {
                $rows[] = array_merge([$r['label']], array_map(fn ($k) => $r[$k], array_keys(ReportService::METRICS)));
            }
            $rows[] = array_merge(['Total'], array_map(fn ($k) => $b['totals'][$k] ?? 0, array_keys(ReportService::METRICS)));

            return $rows;
        };
        $widths = array_merge([30], array_fill(0, count(ReportService::METRICS), 16));
        $x->sheet('By '.strtolower(ReportFilters::GROUPS[$f->group]), $table('Period', $d['period']), $widths);
        foreach (['stations' => 'Station', 'chargers' => 'Charger', 'operators' => 'Operator'] as $k => $first) {
            if (isset($d[$k])) {
                $x->sheet(ucfirst($k), $table($first, $d[$k]), $widths);
            }
        }
        if (isset($d['pending'])) {
            $rows = [['Type', 'Booking', 'Station', 'Since', 'Amount (₹)']];
            foreach ($d['pending'] as $p) {
                foreach ($p['items'] as $it) {
                    $rows[] = [$p['label'], $it['code'], $it['station'], $it['since'], $it['amount']];
                }
            }
            $x->sheet('Pending money', $rows, [30, 16, 34, 22, 14]);
        }
        if (isset($d['gst'])) {
            $rows = [['Supplier GSTIN', 'Rate %', 'Type', 'Invoices', 'Taxable', 'CGST', 'SGST', 'IGST', 'Credit notes', 'Credit taxable', 'Credit tax', 'Debit notes', 'Debit taxable', 'Debit tax', 'Net taxable', 'Net tax']];
            foreach ($d['gst']['rows'] as $r) {
                $rows[] = [$r['gstin'], $r['rate'], $r['type'], $r['invoices'], $r['taxable'], $r['cgst'], $r['sgst'], $r['igst'], $r['credit_notes'], $r['credit_taxable'], $r['credit_tax'], $r['debit_notes'], $r['debit_taxable'], $r['debit_tax'], $r['net_taxable'], $r['net_tax']];
            }
            $t = $d['gst']['totals'];
            $rows[] = ['Total', '', '', $t['invoices'], $t['taxable'], $t['cgst'], $t['sgst'], $t['igst'], $t['credit_notes'], $t['credit_taxable'], $t['credit_tax'], $t['debit_notes'], $t['debit_taxable'], $t['debit_tax'], $t['net_taxable'], $t['net_tax']];
            $x->sheet('GST', $rows, array_merge([20, 8, 8], array_fill(0, 13, 14)));
        }
        if (isset($d['fees'])) {
            $rows = [['Operator', 'Number', 'Month', 'Status', 'Fees', 'GST', 'Total', 'Coupon credit', 'Net payable', 'Paid', 'Outstanding']];
            foreach ($d['fees']['statements'] as $r) {
                $rows[] = [$r['company'], $r['number'], $r['period'], $r['status'], $r['taxable'], $r['gst'], $r['total'], $r['reimbursement'], $r['net_payable'], $r['paid'], $r['outstanding']];
            }
            $x->sheet('Platform fees', $rows, [28, 20, 12, 10, 12, 12, 12, 14, 14, 12, 14]);
            $rows = [['Operator', 'Fees charged', 'Funds added', 'Coupon credits', 'Balance now']];
            foreach ($d['fees']['wallets'] as $w) {
                $rows[] = [$w['company'], $w['fees'], $w['added'], $w['coupon_credits'], $w['balance']];
            }
            $x->sheet('Wallets', $rows, [28, 14, 14, 14, 14]);
        }

        return $x;
    }
}
