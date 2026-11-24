<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Station;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Spatie\Activitylog\Models\Activity;

/**
 * Booking detail for staff. Two independent columns (main + sidebar) so cards stack
 * without row gaps; a summary strip on top answers "what, when, where, how much" at a glance.
 */
class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $tz = Station::TIMEZONE;

        return $schema->columns(3)->components([
            // ── Summary strip ─────────────────────────────────────────────
            Section::make()->columnSpanFull()->schema([
                Grid::make(['default' => 2, 'lg' => 5])->schema([
                    TextEntry::make('status')->label('Status')->badge()->size(TextSize::Large),
                    TextEntry::make('slot_day')->label('Slot date')->icon('heroicon-m-calendar-days')->iconColor('primary')
                        ->weight(FontWeight::SemiBold)
                        ->state(fn (Booking $r) => $r->start_at->setTimezone($tz)->format('D, d M Y')),
                    TextEntry::make('slot_time')->label('Time (IST)')->icon('heroicon-m-clock')->iconColor('primary')
                        ->weight(FontWeight::SemiBold)
                        ->state(fn (Booking $r) => $r->start_at->setTimezone($tz)->format('h:i A').' – '.$r->end_at->setTimezone($tz)->format('h:i A'))
                        ->helperText(fn (Booking $r) => $r->start_at->diffInMinutes($r->end_at).' min'),
                    TextEntry::make('station.name')->label('Station')->icon('heroicon-m-map-pin')->iconColor('primary')
                        ->weight(FontWeight::SemiBold)
                        ->helperText(fn (Booking $r) => trim(($r->station?->city ?? '').' · '.($r->station?->code ?? ''), ' ·'))
                        ->url(fn (Booking $r) => $r->station ? route('stations.show', $r->station->slug) : null, shouldOpenInNewTab: true),
                    TextEntry::make('total_amount')->label('Total (incl. GST)')->money('INR')
                        ->size(TextSize::Large)->weight(FontWeight::Bold)->color('success')
                        ->helperText(fn (Booking $r) => self::paidLabel($r)),
                ]),
            ]),

            // ── Main column ──────────────────────────────────────────────
            Group::make()->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make('Charging session')->icon('heroicon-o-bolt')->iconColor('primary')->columns(3)->schema([
                    TextEntry::make('code')->label('Booking code')->copyable()->weight(FontWeight::Bold)->fontFamily('mono'),
                    TextEntry::make('charger.code')->label('Charger')
                        ->formatStateUsing(fn (Booking $r) => "{$r->charger->code} · {$r->charger->type->value} ".rtrim(rtrim((string) $r->charger->power_kw, '0'), '.').' kW')
                        ->helperText(fn (Booking $r) => 'Connector: '.($r->connectorPoint ? '#'.$r->connectorPoint->connector_no.' '.$r->connectorPoint->type->getLabel() : $r->connector)),
                    TextEntry::make('charge_point_id')->label('Charge point ID')->fontFamily('mono')->copyable()
                        ->state(fn (Booking $r) => $r->charger->ocpp_identity ?: $r->charger->code)
                        ->helperText(fn (Booking $r) => $r->charger->serial_no ? 'Serial '.$r->charger->serial_no : null),
                    TextEntry::make('electrical')->label('Electrical rating')->placeholder('Not set')
                        ->state(fn (Booking $r) => $r->charger->electricalSummary())
                        ->helperText(fn (Booking $r) => $r->charger->standard),
                    TextEntry::make('meter')->label('Energy meter')->placeholder('Not set')->fontFamily('mono')
                        ->state(fn (Booking $r) => $r->charger->meter_serial ? $r->charger->meter_serial.($r->charger->meter_accuracy_class ? ' · '.$r->charger->meter_accuracy_class : '') : null),
                    TextEntry::make('battery')->label('Planned charge')
                        ->state(fn (Booking $r) => "{$r->battery_from}% → {$r->battery_to}%")
                        ->helperText(fn (Booking $r) => "≈ {$r->est_kwh} kWh"),
                    TextEntry::make('created_at')->label('Booked on')->dateTime('d M Y, h:i A', $tz),
                    TextEntry::make('hold_expires_at')->label('Payment hold until')->dateTime('d M, h:i A', $tz)->placeholder('—')
                        ->visible(fn (Booking $r) => $r->status->value === 'held'),
                    TextEntry::make('cancel_reason')->label('Cancellation')->color('danger')->columnSpanFull()
                        ->visible(fn (Booking $r) => filled($r->cancel_reason))
                        ->formatStateUsing(fn (Booking $r) => $r->cancel_reason.' · '.$r->cancelled_at?->setTimezone($tz)->format('d M Y, h:i A')),
                ]),

                Section::make('Customer & vehicle')->icon('heroicon-o-user-circle')->iconColor('primary')->columns(3)->schema([
                    TextEntry::make('customer_snapshot.name')->label('Name')->weight(FontWeight::SemiBold),
                    TextEntry::make('customer_snapshot.email')->label('Email')->copyable()->icon('heroicon-m-envelope')
                        ->extraAttributes(['class' => 'break-all']),
                    TextEntry::make('customer_snapshot.phone')->label('Mobile')->prefix('+91 ')->copyable()->icon('heroicon-m-phone'),
                    TextEntry::make('vehicle_snapshot.registration_no')->label('Vehicle')->weight(FontWeight::SemiBold)->fontFamily('mono')->placeholder('—')
                        ->helperText(fn (Booking $r) => trim(($r->vehicle_snapshot['make'] ?? '').' '.($r->vehicle_snapshot['model'] ?? '').(isset($r->vehicle_snapshot['battery_kwh']) ? ' · '.$r->vehicle_snapshot['battery_kwh'].' kWh' : ''))),
                    TextEntry::make('customer_snapshot.gstin')->label('GSTIN (B2B)')->placeholder('Not provided (B2C)'),
                    TextEntry::make('customer_snapshot.state_code')->label('Billing state code')->placeholder('—'),
                ]),

                Section::make('Payments & refunds')->icon('heroicon-o-credit-card')->iconColor('primary')->schema([
                    RepeatableEntry::make('payments')->hiddenLabel()->contained(false)
                        ->placeholder('No payment attempts yet.')
                        ->table([
                            RepeatableEntry\TableColumn::make('When'),
                            RepeatableEntry\TableColumn::make('Amount'),
                            RepeatableEntry\TableColumn::make('Status'),
                            RepeatableEntry\TableColumn::make('Mode'),
                            RepeatableEntry\TableColumn::make('Gateway ref.'),
                        ])
                        ->schema([
                            TextEntry::make('created_at')->dateTime('d M, h:i A', $tz),
                            TextEntry::make('amount')->money('INR'),
                            TextEntry::make('status')->badge()->color(fn (string $state) => match ($state) { 'success' => 'success', 'failed' => 'danger', default => 'warning' }),
                            TextEntry::make('payment_mode')->placeholder('—'),
                            TextEntry::make('gateway_txn_id')->placeholder('—')->copyable()->fontFamily('mono'),
                        ]),
                    RepeatableEntry::make('refunds')->label('Refunds')->contained(false)
                        ->visible(fn (Booking $r) => $r->refunds->isNotEmpty())
                        ->table([
                            RepeatableEntry\TableColumn::make('When'),
                            RepeatableEntry\TableColumn::make('Refund'),
                            RepeatableEntry\TableColumn::make('Fee kept'),
                            RepeatableEntry\TableColumn::make('Status'),
                            RepeatableEntry\TableColumn::make('Rule'),
                        ])
                        ->schema([
                            TextEntry::make('created_at')->dateTime('d M, h:i A', $tz),
                            TextEntry::make('amount')->money('INR'),
                            TextEntry::make('fee_retained')->money('INR'),
                            TextEntry::make('status')->badge()->color(fn (string $state) => match ($state) { 'success' => 'success', 'failed' => 'danger', default => 'warning' }),
                            TextEntry::make('policy_rule')->placeholder('—'),
                        ]),
                ]),

                Section::make('Timeline')->icon('heroicon-o-queue-list')->iconColor('primary')->collapsible()->schema([
                    RepeatableEntry::make('timeline')->hiddenLabel()->contained(false)
                        ->state(fn (Booking $r) => self::timeline($r))
                        ->placeholder('No events recorded.')
                        ->schema([
                            Grid::make(['default' => 1, 'sm' => 4])->schema([
                                TextEntry::make('at')->hiddenLabel()->color('gray')->size(TextSize::Small),
                                TextEntry::make('what')->hiddenLabel()->weight(FontWeight::Medium)->columnSpan(['default' => 1, 'sm' => 2]),
                                TextEntry::make('who')->hiddenLabel()->color('gray')->size(TextSize::Small)->icon('heroicon-m-user'),
                            ]),
                        ]),
                ]),
            ]),

            // ── Sidebar ──────────────────────────────────────────────────
            Group::make()->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make('Bill')->icon('heroicon-o-receipt-percent')->iconColor('primary')
                    ->description(fn (Booking $r) => in_array($r->status->value, ['completed'], true) ? 'Final amounts' : 'Estimate, billed on the planned energy')
                    ->schema([
                        TextEntry::make('energy_amount')->label('Energy')->money('INR')->inlineLabel()->alignEnd()
                            ->helperText(fn (Booking $r) => "{$r->est_kwh} kWh × ₹{$r->rate_per_kwh}"),
                        TextEntry::make('session_fee')->label('Booking fee')->money('INR')->inlineLabel()->alignEnd(),
                        TextEntry::make('discount_amount')->label(fn (Booking $r) => 'Discount · '.$r->coupon_code)->inlineLabel()->alignEnd()->color('success')
                            ->formatStateUsing(fn ($state) => '− ₹'.number_format((float) $state, 2))
                            ->helperText(fn (Booking $r) => $r->discount_funded_by === 'platform' ? 'Funded by Trans EV' : 'Funded by the operator')
                            ->visible(fn (Booking $r) => (float) $r->discount_amount > 0),
                        TextEntry::make('taxable_amount')->label('Taxable value')->money('INR')->inlineLabel()->alignEnd()->weight(FontWeight::SemiBold),
                        TextEntry::make('cgst_amount')->label(fn (Booking $r) => 'CGST '.rtrim(rtrim(number_format($r->gst_rate / 2, 2), '0'), '.').'%')->money('INR')->inlineLabel()->alignEnd()
                            ->visible(fn (Booking $r) => (float) $r->igst_amount === 0.0),
                        TextEntry::make('sgst_amount')->label(fn (Booking $r) => 'SGST '.rtrim(rtrim(number_format($r->gst_rate / 2, 2), '0'), '.').'%')->money('INR')->inlineLabel()->alignEnd()
                            ->visible(fn (Booking $r) => (float) $r->igst_amount === 0.0),
                        TextEntry::make('igst_amount')->label(fn (Booking $r) => "IGST {$r->gst_rate}%")->money('INR')->inlineLabel()->alignEnd()
                            ->visible(fn (Booking $r) => (float) $r->igst_amount > 0),
                        TextEntry::make('total_amount')->label('Total')->money('INR')->inlineLabel()->alignEnd()
                            ->size(TextSize::Large)->weight(FontWeight::Bold)
                            ->extraEntryWrapperAttributes(['class' => 'border-t border-gray-200 pt-3 dark:border-white/10']),
                        TextEntry::make('place_of_supply')->label('Place of supply')->inlineLabel()->alignEnd()->color('gray')
                            ->formatStateUsing(fn (Booking $r) => "{$r->place_of_supply} · SAC {$r->sac_code}"),
                    ]),

                Section::make('GST documents')->icon('heroicon-o-document-text')->iconColor('primary')->schema([
                    TextEntry::make('invoice.number')->label('Tax invoice')->fontFamily('mono')->weight(FontWeight::SemiBold)
                        ->placeholder(fn (Booking $r) => app(\App\Services\GstService::class)->invoiceGstin($r->station) ? 'Not issued yet' : 'Cannot issue: no GSTIN for this station’s state')
                        ->helperText(fn (Booking $r) => $r->invoice ? $r->invoice->issued_at->setTimezone(Station::TIMEZONE)->format('d M Y').' · ₹'.number_format((float) $r->invoice->total, 2).' · '.$r->invoice->supply_type : null)
                        ->url(fn (Booking $r) => $r->invoice ? route('bookings.invoice', $r) : null, shouldOpenInNewTab: true)
                        ->color(fn (Booking $r) => $r->invoice ? 'primary' : 'danger'),
                    RepeatableEntry::make('creditNotes')->label('Credit notes')->contained(false)
                        ->visible(fn (Booking $r) => $r->creditNotes->isNotEmpty())
                        ->schema([
                            TextEntry::make('number')->hiddenLabel()->fontFamily('mono')
                                ->helperText(fn (\App\Models\CreditNote $record) => $record->issued_at->setTimezone(Station::TIMEZONE)->format('d M Y').' · − ₹'.number_format((float) $record->total, 2))
                                ->url(fn (\App\Models\CreditNote $record) => route('credit-notes.show', $record), shouldOpenInNewTab: true),
                        ]),
                ]),

                Section::make('Operator & site')->icon('heroicon-o-building-office-2')->iconColor('primary')->schema([
                    TextEntry::make('company.trade_name')->label('Company')->weight(FontWeight::SemiBold)
                        ->helperText(fn (Booking $r) => $r->company?->legal_name),
                    TextEntry::make('invoice_gstin')->label('Invoicing GSTIN')->fontFamily('mono')
                        ->state(fn (Booking $r) => $r->station?->gstin ?: $r->company?->gstin)->placeholder('—'),
                    TextEntry::make('grid')->label('Power supply')->placeholder('Not set')
                        ->state(fn (Booking $r) => collect([$r->station?->supply_type, $r->station?->supply_voltage, $r->station?->sanctioned_load_kw ? rtrim(rtrim((string) $r->station->sanctioned_load_kw, '0'), '.').' kW sanctioned' : null])->filter()->implode(' · ') ?: null)
                        ->helperText(fn (Booking $r) => $r->station?->electricity_provider),
                ]),

                Section::make('Cancellation policy agreed')->icon('heroicon-o-arrow-uturn-left')->iconColor('primary')->collapsible()->schema([
                    TextEntry::make('policy_name')->hiddenLabel()->weight(FontWeight::SemiBold)
                        ->state(fn (Booking $r) => ($p = \App\Services\Payments\RefundService::policyOf($r))['name'] ?? 'Current policy')
                        ->helperText(fn (Booking $r) => $r->cancellation_policy ? 'Version '.($r->cancellation_policy['version'] ?? 1).' · saved at checkout' : 'Booked before policies were saved per booking'),
                    TextEntry::make('policy_tiers')->hiddenLabel()->listWithLineBreaks()->bulleted()
                        ->state(fn (Booking $r) => collect(\App\Services\Payments\RefundService::policyOf($r)['tiers'] ?? [])
                            ->map(fn ($t) => ($t['label'] ?: $t['refund_percent'].'% refund').((float) ($t['fee'] ?? 0) > 0 ? ' (less ₹'.$t['fee'].')' : ''))
                            ->push('No-show: '.(int) (\App\Services\Payments\RefundService::policyOf($r)['no_show_refund_percent'] ?? 0).'% refund')->all()),
                ]),

                Section::make('Rules accepted')->icon('heroicon-o-shield-check')->iconColor('success')->collapsible()->schema([
                    TextEntry::make('ruleAcceptance.version')->label('Version')->prefix('v')->inlineLabel()->alignEnd(),
                    TextEntry::make('ruleAcceptance.accepted_at')->label('Accepted')->dateTime('d M Y, h:i A', $tz)->inlineLabel()->alignEnd(),
                    TextEntry::make('ruleAcceptance.ip')->label('IP address')->inlineLabel()->alignEnd()->fontFamily('mono'),
                    TextEntry::make('ruleAcceptance.content_hash')->label('Text fingerprint (SHA-256)')->limit(24)->copyable()->fontFamily('mono')->color('gray')
                        ->tooltip(fn (Booking $r) => $r->ruleAcceptance?->content_hash),
                ]),
            ]),
        ]);
    }

    private static function paidLabel(Booking $r): string
    {
        $paid = $r->payments->firstWhere('status', 'success');
        if (! $paid) {
            return match ($r->status->value) {
                'held' => 'Awaiting payment',
                'confirmed', 'checked_in', 'completed', 'no_show' => 'Confirmed · no online payment on record',
                default => 'Not paid',
            };
        }
        $refunded = (float) $r->refunds->where('status', 'success')->sum('amount');

        return 'Paid'.($paid->payment_mode ? ' via '.$paid->payment_mode : '').($refunded > 0 ? ' · ₹'.number_format($refunded, 2).' refunded' : '');
    }

    /** @return list<array{at:string, what:string, who:string}> booking, payment and refund events, newest first */
    private static function timeline(Booking $r): array
    {
        $tz = Station::TIMEZONE;
        $events = collect([['t' => $r->created_at, 'what' => 'Booking created (slot held)', 'who' => $r->customer_snapshot['name'] ?? 'Customer']]);

        foreach ($r->payments as $p) {
            /** @var Payment $p */
            $events->push(['t' => $p->created_at, 'what' => 'Payment started · ₹'.$p->amount, 'who' => 'Customer']);
            if ($p->paid_at) {
                $events->push(['t' => $p->paid_at, 'what' => 'Payment confirmed'.($p->verified_via ? " ({$p->verified_via})" : ''), 'who' => 'SabPaisa']);
            } elseif ($p->status === 'failed') {
                $events->push(['t' => $p->updated_at, 'what' => 'Payment failed'.($p->failure_reason ? ': '.$p->failure_reason : ''), 'who' => 'SabPaisa']);
            }
        }
        foreach ($r->refunds as $f) {
            /** @var Refund $f */
            $events->push(['t' => $f->created_at, 'what' => 'Refund of ₹'.$f->amount.' '.$f->status, 'who' => $f->requested_by ? 'Staff' : 'Customer']);
        }
        Activity::query()->where('subject_type', Booking::class)->where('subject_id', $r->id)->with('causer')->latest('id')->limit(20)->get()
            ->each(function (Activity $a) use ($events) {
                $status = $a->attribute_changes?->get('attributes')['status'] ?? null;
                if ($a->event === 'created') {
                    return;
                }
                $events->push(['t' => $a->created_at, 'what' => $status ? 'Status → '.ucfirst(str_replace('_', ' ', $status)) : ucfirst($a->description), 'who' => $a->causer?->name ?? 'System']);
            });

        return $events->filter(fn ($e) => $e['t'])->sortByDesc('t')->values()
            ->map(fn ($e) => ['at' => $e['t']->copy()->setTimezone($tz)->format('d M, h:i A'), 'what' => $e['what'], 'who' => $e['who']])->all();
    }
}
