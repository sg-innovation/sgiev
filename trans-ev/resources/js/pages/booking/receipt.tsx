import { inr } from '@/components/site/station-bits';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

interface Receipt {
    number: string;
    date: string;
    operator: { legalName: string; tradeName: string; address: string; gstin: string | null; support: string | null };
    customer: { name: string; email: string; phone: string; gstin: string | null; state_code: string };
    booking: {
        code: string; date: string; time: string; station: { name: string }; stationAddress: string; connector: string;
        charger: { code: string; type: string; powerKw: number };
        vehicleSnapshot: { make: string; model: string; registration_no: string };
        amounts: { kwh: number; ratePerKwh: number; energy: number; sessionFee: number; discount: number; couponCode: string | null; parking: number; lines: { type: string; label: string; qty: number; unit: string; rate: number; amount: number }[]; taxable: number; gstRate: number; cgst: number; sgst: number; igst: number; total: number; sac: string; placeOfSupply: string };
    };
    payment: { amount: number; mode: string | null; gatewayTxnId: string | null; bankTxnId: string | null; merchantTxnId: string; environment: string; rrn: string | null; paidAt: string; amountInWords: string };
    chargePoint: { station: string; stationCode: string; address: string; chargerCode: string; chargerType: string; powerKw: number; serialNo: string | null; ocppId: string | null; connectorNo: number | null; connectorType: string; electrical: string | null; standard: string | null; meterSerial: string | null; meterClass: string | null; oem: string | null };
    refunds: { amount: number; status: string; rule: string | null; date: string }[];
    viewerIsStaff: boolean;
}

/** Printable payment receipt (the GST tax invoice is issued separately by the operator). */
export default function ReceiptPage({ receipt: r }: { receipt: Receipt }) {
    const a = r.booking.amounts;
    const rows: [string, string][] = [
        ...(a.lines.length > 0
            ? a.lines.map((l) => [l.type === 'energy' ? `Charging ${l.label.toLowerCase()} (est.) ${l.qty.toFixed(3)} kWh × ${inr(l.rate)}` : l.label, inr(l.amount)] as [string, string])
            : ([
                  [`Charging energy (est.) ${a.kwh.toFixed(3)} kWh × ${inr(a.ratePerKwh)}`, inr(a.energy)],
                  ['Booking fee', inr(a.sessionFee)],
              ] as [string, string][])),
        ...(a.discount > 0 ? ([[`Discount (coupon ${a.couponCode})`, `− ${inr(a.discount)}`]] as [string, string][]) : []),
        ['Taxable value', inr(a.taxable)],
        ...(a.igst > 0 ? ([[`IGST @ ${a.gstRate}%`, inr(a.igst)]] as [string, string][]) : ([[`CGST @ ${a.gstRate / 2}%`, inr(a.cgst)], [`SGST @ ${a.gstRate / 2}%`, inr(a.sgst)]] as [string, string][])),
    ];

    return (
        <div className="min-h-screen bg-slate-100 px-4 py-8 text-slate-900 print:bg-white print:p-0">
            <Head title={`Receipt ${r.number}`} />
            <div className="mx-auto mb-4 flex max-w-3xl items-center justify-between print:hidden">
                <Link href={r.viewerIsStaff ? '/admin/bookings' : `/account/bookings/${r.booking.code}`} className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">
                    <ArrowLeft className="size-4" /> Back to booking
                </Link>
                <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-full bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white">
                    <Printer className="size-4" /> Print / save as PDF
                </button>
            </div>

            <article className="mx-auto max-w-3xl rounded-3xl bg-white p-8 shadow-xl print:rounded-none print:shadow-none sm:p-12">
                {r.payment.environment === 'simulator' && (
                    <p className="mb-6 rounded-xl bg-amber-100 px-4 py-2 text-center text-sm font-semibold text-amber-900">TEST PAYMENT (local simulator): not a real transaction</p>
                )}
                <header className="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
                    <div>
                        <img src="/brand/logo.svg" alt="Trans EV" className="h-10" />
                        <p className="mt-4 font-semibold">{r.operator.legalName}</p>
                        <p className="max-w-xs text-sm text-slate-500">{r.operator.address}</p>
                        {r.operator.gstin && <p className="text-sm text-slate-500">GSTIN: {r.operator.gstin}</p>}
                    </div>
                    <div className="text-right">
                        <h1 className="text-2xl font-extrabold tracking-tight">Payment receipt</h1>
                        <p className="mt-1 text-sm text-slate-500">{r.number}</p>
                        <p className="text-sm text-slate-500">{r.date}</p>
                        <p className="mt-3 inline-block rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">PAID</p>
                    </div>
                </header>

                <section className="grid gap-6 border-b border-slate-200 py-6 sm:grid-cols-2">
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">Received from</p>
                        <p className="mt-1 font-semibold">{r.customer.name}</p>
                        <p className="text-sm text-slate-500">{r.customer.email} · +91 {r.customer.phone}</p>
                        {r.customer.gstin && <p className="text-sm text-slate-500">GSTIN: {r.customer.gstin}</p>}
                    </div>
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">Booking</p>
                        <p className="mt-1 font-semibold">{r.booking.code}</p>
                        <p className="text-sm text-slate-500">{r.booking.station.name}</p>
                        <p className="text-sm text-slate-500">{r.booking.date}, {r.booking.time} IST</p>
                        <p className="text-sm text-slate-500">
                            {r.booking.charger.code} · {r.booking.connector} · {r.booking.vehicleSnapshot.registration_no}
                        </p>
                    </div>
                </section>

                <section className="border-b border-slate-200 py-6">
                    <p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">Charging point</p>
                    <dl className="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                        <div className="sm:col-span-3">
                            <dt className="text-slate-500">Location</dt>
                            <dd className="font-medium">{r.chargePoint.station} <span className="text-slate-400">({r.chargePoint.stationCode})</span></dd>
                            <dd className="text-slate-500">{r.chargePoint.address}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Charge point ID</dt>
                            <dd className="font-mono font-medium">{r.chargePoint.ocppId ?? r.chargePoint.chargerCode}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Charger</dt>
                            <dd className="font-medium">{r.chargePoint.chargerCode} · {r.chargePoint.chargerType} {r.chargePoint.powerKw} kW</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Connector</dt>
                            <dd className="font-medium">{r.chargePoint.connectorNo ? `#${r.chargePoint.connectorNo} · ` : ''}{r.chargePoint.connectorType}</dd>
                        </div>
                        {r.chargePoint.electrical && (
                            <div className="sm:col-span-2">
                                <dt className="text-slate-500">Electrical rating</dt>
                                <dd className="font-medium">{r.chargePoint.electrical}</dd>
                            </div>
                        )}
                        {r.chargePoint.standard && (
                            <div>
                                <dt className="text-slate-500">Standard</dt>
                                <dd className="font-medium">{r.chargePoint.standard}</dd>
                            </div>
                        )}
                        {r.chargePoint.oem && (
                            <div>
                                <dt className="text-slate-500">Make / model</dt>
                                <dd className="font-medium">{r.chargePoint.oem}</dd>
                            </div>
                        )}
                        {r.chargePoint.meterSerial && (
                            <div>
                                <dt className="text-slate-500">Energy meter</dt>
                                <dd className="font-mono font-medium">{r.chargePoint.meterSerial}{r.chargePoint.meterClass ? ` · ${r.chargePoint.meterClass}` : ''}</dd>
                            </div>
                        )}
                        {r.chargePoint.serialNo && (
                            <div>
                                <dt className="text-slate-500">Charger serial no.</dt>
                                <dd className="font-mono font-medium">{r.chargePoint.serialNo}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-slate-500">Booked slot</dt>
                            <dd className="font-medium">{r.booking.date}, {r.booking.time} IST</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Planned energy</dt>
                            <dd className="font-medium">{a.kwh.toFixed(3)} kWh @ {inr(a.ratePerKwh)}/kWh</dd>
                        </div>
                    </dl>
                </section>

                <table className="mt-6 w-full text-sm">
                    <tbody>
                        {rows.map(([k, v]) => (
                            <tr key={k} className="border-b border-slate-100">
                                <td className="py-2.5 text-slate-600">{k}</td>
                                <td className="py-2.5 text-right font-medium">{v}</td>
                            </tr>
                        ))}
                        <tr>
                            <td className="pt-4 text-base font-bold">Total paid</td>
                            <td className="pt-4 text-right text-2xl font-extrabold">{inr(r.payment.amount)}</td>
                        </tr>
                        <tr>
                            <td colSpan={2} className="pt-1 text-right text-xs text-slate-500 italic">{r.payment.amountInWords}</td>
                        </tr>
                    </tbody>
                </table>

                <section className="mt-8 grid gap-4 rounded-2xl bg-slate-50 p-5 text-sm sm:grid-cols-2">
                    <div><span className="text-slate-500">Payment mode:</span> <span className="font-medium">{r.payment.mode ?? 'Online'}</span></div>
                    <div><span className="text-slate-500">Gateway transaction:</span> <span className="font-medium break-all">{r.payment.gatewayTxnId}</span></div>
                    <div><span className="text-slate-500">Paid at:</span> <span className="font-medium">{r.payment.paidAt} IST</span></div>
                    <div><span className="text-slate-500">Bank reference:</span> <span className="font-medium break-all">{r.payment.bankTxnId ?? '—'}</span></div>
                    <div><span className="text-slate-500">RRN / UTR:</span> <span className="font-medium break-all">{r.payment.rrn ?? '—'}</span></div>
                    <div><span className="text-slate-500">Order ID:</span> <span className="font-medium break-all">{r.payment.merchantTxnId}</span></div>
                    <div><span className="text-slate-500">SAC:</span> <span className="font-medium">{a.sac}</span> · <span className="text-slate-500">Place of supply:</span> <span className="font-medium">{a.placeOfSupply}</span></div>
                </section>

                {r.refunds.length > 0 && (
                    <section className="mt-6">
                        <p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">Refunds</p>
                        <ul className="mt-2 space-y-1 text-sm">
                            {r.refunds.map((x, i) => (
                                <li key={i} className="flex justify-between"><span className="text-slate-600">{x.date} · {x.rule}</span><span className="font-medium">{inr(x.amount)} ({x.status})</span></li>
                            ))}
                        </ul>
                    </section>
                )}

                <footer className="mt-10 border-t border-slate-200 pt-6 text-xs text-slate-500">
                    <p>This is a computer-generated payment receipt. The GST tax invoice for this charging session is issued by {r.operator.tradeName} and can be downloaded from your booking page. Energy shown is the pre-paid estimate; any difference after charging is refunded or billed as per the terms.</p>
                    {r.operator.support && <p className="mt-2">Questions? {r.operator.support}</p>}
                </footer>
            </article>
        </div>
    );
}
