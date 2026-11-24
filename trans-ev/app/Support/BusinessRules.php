<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Business rules stored in the database (system_settings, keys "rule.*") and edited in
 * Admin → Settings → Business rules. DEFINITIONS describe each rule (type, limits, help) and give the
 * first-install value that BusinessRulesSeeder writes to the database; at runtime the database value is used.
 */
final class BusinessRules
{
    public const DEFINITIONS = [
        // Booking
        'booking.hold_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 10, 'min' => 2, 'max' => 60, 'label' => 'Slot hold while paying (minutes)', 'help' => 'How long a selected slot is reserved before payment.'],
        'booking.checkout_hold_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 15, 'min' => 5, 'max' => 60, 'label' => 'Hold extension when checkout starts (minutes)', 'help' => 'Extra time once the customer is sent to the payment page.'],
        'booking.max_active_holds' => ['group' => 'Booking', 'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 10, 'label' => 'Unpaid holds per customer', 'help' => 'Stops one person reserving many slots at once.'],
        'booking.advance_days' => ['group' => 'Booking', 'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 90, 'label' => 'Book up to (days ahead)', 'help' => null],
        'booking.min_lead_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 240, 'label' => 'Minimum notice before a slot (minutes)', 'help' => null],
        'booking.no_show_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 60, 'label' => 'No-show after (minutes)', 'help' => 'A booking counts as a no-show if charging has not started this long after the slot start.'],
        'booking.max_slots' => ['group' => 'Booking', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 12, 'label' => 'Longest booking (slots)', 'help' => 'Used when a station does not set its own allowed durations.'],
        // Check-in (C1)
        'checkin.early_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 15, 'min' => 0, 'max' => 120, 'label' => 'Check-in opens before the slot (minutes)', 'help' => 'Customers can be checked in this long before their slot starts, if the charger is free.'],
        'checkin.qr_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 60, 'label' => 'Check-in QR valid for (minutes)', 'help' => 'The QR on the customer’s booking refreshes automatically; screenshots stop working after this.'],
        // Walk-ins (C3)
        'walkin.max_minutes' => ['group' => 'Booking', 'type' => 'int', 'default' => 180, 'min' => 15, 'max' => 720, 'label' => 'Longest walk-in session (minutes)', 'help' => 'Also limited by the next online booking on that connector.'],
        'platform_fees.walk_ins' => ['group' => 'Platform fees', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1, 'label' => 'Charge platform fees on walk-in sales (1 = yes, 0 = no)', 'help' => 'Walk-ins are paid at the station; by default only online bookings carry the platform fee.'],
        // Final bill / true-up (C4)
        'trueup.min_amount' => ['group' => 'Booking', 'type' => 'decimal', 'default' => '1.00', 'min' => 0, 'max' => 500, 'label' => 'Ignore final-bill differences below (₹)', 'help' => 'Smaller differences between the prepaid amount and the final bill are not refunded or collected.'],
        'trueup.collect_extra' => ['group' => 'Booking', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1, 'label' => 'Collect the balance when more energy is delivered than prepaid (1 = yes, 0 = operator absorbs it)', 'help' => 'When on, staff collect the balance at the station and a GST debit note is issued.'],
        // Pricing estimate
        'pricing.charging_efficiency' => ['group' => 'Pricing estimate', 'type' => 'decimal', 'default' => '0.90', 'min' => 0.5, 'max' => 1, 'label' => 'Charging efficiency', 'help' => 'Share of energy drawn from the charger that reaches the battery.'],
        'pricing.power_utilisation' => ['group' => 'Pricing estimate', 'type' => 'decimal', 'default' => '0.85', 'min' => 0.3, 'max' => 1, 'label' => 'Average power utilisation', 'help' => 'Average share of rated power a vehicle draws (charging slows near full).'],
        // Support
        'tickets.auto_close_days' => ['group' => 'Support tickets', 'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 60, 'label' => 'Close resolved tickets after (days)', 'help' => null],
        'tickets.max_files' => ['group' => 'Support tickets', 'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 10, 'label' => 'Attachments per message', 'help' => null],
        'tickets.max_file_mb' => ['group' => 'Support tickets', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 20, 'label' => 'Attachment size limit (MB)', 'help' => null],
        // Partners
        'partner.terms_version' => ['group' => 'Partners', 'type' => 'string', 'default' => 'partner-2026-11', 'label' => 'Partner terms version', 'help' => 'Recorded against each partner who accepts the terms. Change it when the partner terms change.'],
        'kyc.max_file_mb' => ['group' => 'Partners', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 20, 'label' => 'KYC document size limit (MB)', 'help' => null],
        // Platform fees (billed to operators)
        'platform_fees.sac' => ['group' => 'Platform fees', 'type' => 'string', 'default' => '998599', 'label' => 'SAC code for platform fees', 'help' => 'Confirm with your tax adviser.'],
        'platform_fees.gst_rate' => ['group' => 'Platform fees', 'type' => 'decimal', 'default' => '18', 'min' => 0, 'max' => 28, 'label' => 'GST rate on platform fees (%)', 'help' => null],
        'platform_fees.payment_days' => ['group' => 'Platform fees', 'type' => 'int', 'default' => 15, 'min' => 0, 'max' => 90, 'label' => 'Payment due (days after issue)', 'help' => null],
        // Operator wallets
        'platform_fees.default_billing_mode' => ['group' => 'Platform fees', 'type' => 'string', 'default' => 'wallet', 'label' => 'Billing for new partners (wallet or invoice)', 'help' => 'wallet = prepaid balance, fee taken on every booking; invoice = monthly invoice.'],
        'wallet.min_recharge' => ['group' => 'Platform fees', 'type' => 'int', 'default' => 500, 'min' => 1, 'max' => 1000000, 'label' => 'Minimum wallet recharge (₹)', 'help' => null],
        'wallet.low_balance_alert' => ['group' => 'Platform fees', 'type' => 'int', 'default' => 500, 'min' => 0, 'max' => 1000000, 'label' => 'Default low-balance alert (₹)', 'help' => 'Operators are emailed when their wallet falls below this; each operator can change their own.'],
        // Reconciliation (B8)
        'reconciliation.run_time' => ['group' => 'Reconciliation', 'type' => 'string', 'default' => '05:30', 'label' => 'Daily run time (IST, HH:MM)', 'help' => 'When the daily reconciliation runs.'],
        'reconciliation.window_days' => ['group' => 'Reconciliation', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 30, 'label' => 'Re-check payments with the gateway from the last (days)', 'help' => null],
        'reconciliation.max_enquiries' => ['group' => 'Reconciliation', 'type' => 'int', 'default' => 500, 'min' => 10, 'max' => 10000, 'label' => 'Gateway enquiries per run', 'help' => 'Limits calls to the payment gateway.'],
        'reconciliation.pending_minutes' => ['group' => 'Reconciliation', 'type' => 'int', 'default' => 60, 'min' => 10, 'max' => 1440, 'label' => 'Flag payments pending longer than (minutes)', 'help' => 'Also the grace period before a paid-but-not-booked payment is flagged.'],
        'reconciliation.refund_stuck_hours' => ['group' => 'Reconciliation', 'type' => 'int', 'default' => 72, 'min' => 1, 'max' => 720, 'label' => 'Flag refunds unconfirmed after (hours)', 'help' => null],
        'reconciliation.document_grace_hours' => ['group' => 'Reconciliation', 'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 72, 'label' => 'Flag missing invoices / credit notes after (hours)', 'help' => null],
        // Payments
        'payments.webhook_tolerance_seconds' => ['group' => 'Payments', 'type' => 'int', 'default' => 300, 'min' => 60, 'max' => 900, 'label' => 'Webhook clock tolerance (seconds)', 'help' => 'Older webhook signatures are rejected (replay protection).'],
    ];

    private static ?array $values = null;

    public static function get(string $key): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown business rule {$key}");
        $raw = self::all()[$key] ?? null;

        return $raw === null ? $def['default'] : self::cast($def['type'], $raw);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** @return array<string, string|null> key => raw stored value */
    public static function all(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }
        try {
            if (! Schema::hasTable('system_settings')) {
                return self::$values = [];
            }
            self::$values = SystemSetting::query()->where('key', 'like', 'rule.%')->pluck('value', 'key')
                ->mapWithKeys(fn ($v, $k) => [substr($k, 5) => $v])->all();
        } catch (Throwable) {
            self::$values = [];
        }

        return self::$values;
    }

    /** Validate and save, returning the changed keys. */
    public static function save(array $values, ?int $userId): array
    {
        $changed = [];
        foreach (self::DEFINITIONS as $key => $def) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];
            $stored = $def['type'] === 'json' ? json_encode(array_values($value ?? []), JSON_UNESCAPED_UNICODE) : (string) $value;
            $row = SystemSetting::query()->firstOrNew(['key' => 'rule.'.$key]);
            if (! $row->exists || $row->getRawOriginal('value') !== $stored) {
                $row->fill(['value' => $stored, 'is_secret' => false, 'updated_by' => $userId])->save();
                $changed[] = $key;
            }
        }
        self::flush();

        return $changed;
    }

    /** Write first-install values for rules not yet in the database. */
    public static function seedDefaults(): void
    {
        $missing = collect(self::DEFINITIONS)->keys()->reject(fn ($k) => array_key_exists($k, self::all()));
        self::save($missing->mapWithKeys(fn ($k) => [$k => self::DEFINITIONS[$k]['default']])->all(), null);
    }

    public static function flush(): void
    {
        self::$values = null;
    }

    private static function cast(string $type, string $raw): mixed
    {
        return match ($type) {
            'int' => (int) $raw,
            'decimal' => (string) $raw,
            'json' => json_decode($raw, true) ?: [],
            default => $raw,
        };
    }
}
