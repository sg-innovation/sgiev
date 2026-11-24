<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Monthly platform-fee statement for one operator. A draft can be regenerated or voided; once issued it is a
 * Trans EV tax invoice and its amounts can no longer change (only payment details are recorded).
 */
class PlatformFeeStatement extends Model
{
    use LogsActivity;

    public const STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'paid' => 'Paid', 'void' => 'Void'];

    /** Fields that may change after a statement is issued. */
    private const AFTER_ISSUE = ['status', 'paid_at', 'paid_amount', 'payment_reference', 'emailed_at', 'notes', 'updated_at'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'issued_at' => 'datetime', 'due_on' => 'date', 'paid_at' => 'datetime', 'emailed_at' => 'datetime',
            'lines' => 'array', 'supplier' => 'array', 'recipient' => 'array',
            'sales_taxable' => 'decimal:2', 'commission_percent' => 'decimal:2', 'commission_amount' => 'decimal:2', 'per_booking_fee' => 'decimal:2',
            'per_booking_amount' => 'decimal:2', 'subscription_amount' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'gst_rate' => 'decimal:2',
            'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'igst' => 'decimal:2', 'total_tax' => 'decimal:2', 'total' => 'decimal:2',
            'reimbursement_amount' => 'decimal:2', 'net_payable' => 'decimal:2', 'paid_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PlatformFeeStatement $s) => $s->uuid ??= (string) Str::uuid());
        static::updating(function (PlatformFeeStatement $s) {
            if ($s->getOriginal('status') !== 'draft' && array_diff(array_keys($s->getDirty()), self::AFTER_ISSUE)) {
                throw new LogicException('An issued platform-fee invoice cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Platform-fee statements are voided, not deleted.'));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('F Y');
    }

    public function filename(): string
    {
        return 'PlatformFees-'.($this->number ? str_replace('/', '-', $this->number) : $this->period_start->format('Y-m').'-draft').'.pdf';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'number', 'total', 'net_payable', 'paid_at', 'paid_amount', 'payment_reference'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
