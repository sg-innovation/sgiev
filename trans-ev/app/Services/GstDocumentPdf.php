<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/** Renders invoices and credit notes to PDF (A4). */
class GstDocumentPdf
{
    public function renderStatement(\App\Models\PlatformFeeStatement $s): string
    {
        return Pdf::loadView('documents.platform-fee', ['doc' => $s->loadMissing('company')])
            ->setPaper('a4')->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])->output();
    }

    public function render(Invoice|CreditNote $doc): string
    {
        $doc->loadMissing($doc instanceof CreditNote ? ['invoice', 'company'] : ['company', 'creditNotes']);
        $view = $doc instanceof Invoice ? 'documents.invoice' : 'documents.credit-note';
        $logo = public_path('brand/logo-print.png');

        return Pdf::loadView($view, [
            'doc' => $doc,
            'invoice' => $doc instanceof Invoice ? $doc : $doc->invoice,
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
            'verifyUrl' => $doc instanceof Invoice ? route('invoices.verify', ['invoice' => $doc->uuid, 'h' => substr($doc->content_hash, 0, 16)]) : null,
        ])->setPaper('a4')->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])->output();
    }
}
