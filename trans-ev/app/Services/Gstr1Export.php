<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Station;
use App\Support\IndianStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * GSTR-1 working files for one GSTIN and one month, in the column layout of the GST offline utility:
 * b2b, b2cs (net of B2C credit notes), cdnr, hsn (SAC summary) and docs (document series issued).
 * The operator's accountant reviews and uploads them; nothing is filed automatically.
 */
class Gstr1Export
{
    public function build(string $gstin, Carbon $month, string $dir): string
    {
        $from = $month->copy()->setTimezone(Station::TIMEZONE)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $inv = Invoice::query()->where('supplier_gstin', $gstin)->whereBetween('issued_at', [$from->copy()->utc(), $to->copy()->utc()])->orderBy('sequence')->get();
        $cns = CreditNote::query()->with('invoice')->where('supplier_gstin', $gstin)->whereBetween('issued_at', [$from->copy()->utc(), $to->copy()->utc()])->orderBy('sequence')->get();

        $pos = fn (string $code) => $code.'-'.IndianStates::name($code);
        $date = fn ($d) => $d->copy()->setTimezone(Station::TIMEZONE)->format('d-M-Y');
        $rate = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.');
        $m = fn ($v) => number_format((float) $v, 2, '.', '');

        $files = [];
        $files['b2b.csv'] = [['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate', 'Taxable Value', 'Cess Amount'],
            ...$inv->where('supply_type', 'B2B')->map(fn (Invoice $i) => [$i->recipient_gstin, $i->recipient['name'] ?? '', $i->number, $date($i->issued_at), $m($i->total), $pos($i->place_of_supply), 'N', '', 'Regular B2B', '', $rate($i->gst_rate), $m($i->taxable_amount), '0.00'])->values()->all()];

        // B2C (small): net taxable value per place of supply and rate, after B2C credit notes.
        $b2cs = collect();
        foreach ($inv->where('supply_type', 'B2C') as $i) {
            $key = $i->place_of_supply.'|'.$rate($i->gst_rate);
            $b2cs[$key] = bcadd($b2cs[$key] ?? '0', (string) $i->taxable_amount, 2);
        }
        foreach ($cns->filter(fn (CreditNote $c) => $c->recipient_gstin === null) as $c) {
            $key = $c->place_of_supply.'|'.$rate($c->gst_rate);
            $b2cs[$key] = bcsub($b2cs[$key] ?? '0', (string) $c->taxable_amount, 2);
        }
        $files['b2cs.csv'] = [['Type', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN'],
            ...$b2cs->map(function ($v, $k) use ($pos, $m) {
                [$p, $r] = explode('|', $k);

                return ['OE', $pos($p), '', $r, $m($v), '0.00', ''];
            })->values()->all()];

        $files['cdnr.csv'] = [['GSTIN/UIN of Recipient', 'Receiver Name', 'Note Number', 'Note Date', 'Note Type', 'Place Of Supply', 'Reverse Charge', 'Note Supply Type', 'Note Value', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount'],
            ...$cns->filter(fn (CreditNote $c) => $c->recipient_gstin !== null)->map(fn (CreditNote $c) => [$c->recipient_gstin, $c->invoice->recipient['name'] ?? '', $c->number, $date($c->issued_at), 'C', $pos($c->place_of_supply), 'N', 'Regular B2B', $m($c->total), '', $rate($c->gst_rate), $m($c->taxable_amount), '0.00'])->values()->all()];

        $sum = fn (Collection $c, string $f) => $c->reduce(fn ($a, $x) => bcadd($a, (string) $x->{$f}, 2), '0');
        $files['hsn.csv'] = [['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Rate', 'Taxable Value', 'Integrated Tax Amount', 'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount'],
            ...$inv->groupBy(fn (Invoice $i) => $i->sac_code.'|'.$rate($i->gst_rate))->map(function (Collection $g, string $k) use ($cns, $sum, $m) {
                [$sac, $r] = explode('|', $k);
                $notes = $cns->filter(fn (CreditNote $c) => $c->invoice?->sac_code === $sac);
                $qty = $g->reduce(fn ($a, Invoice $i) => bcadd($a, (string) ($i->lines[0]['qty'] ?? '0'), 3), '0');

                return [$sac, 'EV charging services', 'KWH-KILOWATT HOUR', $qty,
                    $m(bcsub($sum($g, 'total'), $sum($notes, 'total'), 2)), $r,
                    $m(bcsub($sum($g, 'taxable_amount'), $sum($notes, 'taxable_amount'), 2)),
                    $m(bcsub($sum($g, 'igst'), $sum($notes, 'igst'), 2)),
                    $m(bcsub($sum($g, 'cgst'), $sum($notes, 'cgst'), 2)),
                    $m(bcsub($sum($g, 'sgst'), $sum($notes, 'sgst'), 2)), '0.00'];
            })->values()->all()];

        $files['docs.csv'] = [['Nature of Document', 'Sr. No. From', 'Sr. No. To', 'Total Number', 'Cancelled'],
            ...array_values(array_filter([
                $inv->isNotEmpty() ? ['Invoices for outward supply', $inv->first()->number, $inv->last()->number, $inv->count(), 0] : null,
                $cns->isNotEmpty() ? ['Credit Note', $cns->first()->number, $cns->last()->number, $cns->count(), 0] : null,
            ]))];

        $path = $dir.DIRECTORY_SEPARATOR.'GSTR1-'.$gstin.'-'.$from->format('Y-m').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $rows) {
            $zip->addFromString($name, self::csv($rows));
        }
        $zip->addFromString('README.txt', "GSTR-1 working files for GSTIN {$gstin}, {$from->format('F Y')}.\nGenerated ".now(Station::TIMEZONE)->format('d M Y H:i')." IST by Trans EV.\nReview with your accountant before uploading to the GST portal (offline tool).\nInvoices: {$inv->count()}  Credit notes: {$cns->count()}\n");
        $zip->close();

        return $path;
    }

    /** CSV with spreadsheet formula-injection protection. */
    public static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($h, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '@', "\t", "\r"], true) ? "'".$v : $v, $row));
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }
}
