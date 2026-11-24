<?php

namespace App\Services;

use App\Models\Station;

/**
 * GST computation. All money is handled as strings with bcmath (never floats).
 *
 * Place of supply for charging = the station's state (service performed at that location).
 * Supplier state = the state of the GSTIN used for the station (always the station's state; see invoiceGstin()).
 * Same state → CGST + SGST (half each); different state → IGST.
 * Rounding: CGST and SGST are each computed on the taxable value and rounded half-up to the paisa
 * (so on odd amounts CGST+SGST may differ from IGST by ₹0.01). Invoices must use this same function.
 */
class GstService
{
    /**
     * The GSTIN the invoice for this station is issued under: the station's own registration, or the operating
     * company's GSTIN when that is registered in the station's state. Null means the operator has not yet added
     * a GSTIN for this state (flagged in admin; invoices must not be issued until it is added).
     */
    public function invoiceGstin(Station $station): ?string
    {
        if ($station->gstin && substr($station->gstin, 0, 2) === $station->state_code) {
            return $station->gstin;
        }
        $company = $station->company;

        return $company?->gstin && substr($company->gstin, 0, 2) === $station->state_code ? $company->gstin : null;
    }

    /**
     * Charging is supplied from a fixed establishment in the station's state, so the supplier is registered
     * in that state and the supply is intra-state (CGST + SGST). IGST applies only if a registration in
     * another state is explicitly used, which this platform does not do.
     */
    public function supplierStateCode(Station $station): string
    {
        $gstin = $this->invoiceGstin($station);

        return $gstin ? substr($gstin, 0, 2) : $station->state_code;
    }

    public function placeOfSupply(Station $station): string
    {
        return $station->state_code;
    }

    /**
     * @return array{cgst: string, sgst: string, igst: string, total_tax: string, intra_state: bool}
     */
    public function split(string $taxable, string $rate, string $supplierState, string $placeOfSupply): array
    {
        $intra = $supplierState === $placeOfSupply;

        if ($intra) {
            $half = bcdiv($rate, '2', 4);
            $cgst = self::round(bcmul($taxable, bcdiv($half, '100', 6), 6));
            $sgst = $cgst;

            return ['cgst' => $cgst, 'sgst' => $sgst, 'igst' => '0.00', 'total_tax' => bcadd($cgst, $sgst, 2), 'intra_state' => true];
        }

        $igst = self::round(bcmul($taxable, bcdiv($rate, '100', 6), 6));

        return ['cgst' => '0.00', 'sgst' => '0.00', 'igst' => $igst, 'total_tax' => $igst, 'intra_state' => false];
    }

    /** Round half-up to 2 decimals. */
    public static function round(string $value, int $scale = 2): string
    {
        $factor = bcpow('10', (string) ($scale + 1));
        $shifted = bcmul($value, $factor, 0);
        $shifted = bcadd($shifted, bccomp($value, '0', 10) >= 0 ? '5' : '-5', 0);

        return bcdiv(bcdiv($shifted, '10', 0), bcpow('10', (string) $scale), $scale);
    }
}
