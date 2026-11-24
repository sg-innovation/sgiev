<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/** GST tax invoice (Rule 46). Immutable once issued: only delivery tracking (emailed_at) may change. */
class Invoice extends Model
{
    public const MUTABLE = ['emailed_at', 'updated_at'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
            'reverse_charge' => 'boolean',
            'supplier' => 'array',
            'recipient' => 'array',
            'site' => 'array',
            'lines' => 'array',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'round_off' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Invoice $i) => $i->uuid ??= (string) Str::uuid());
        static::updating(function (Invoice $i) {
            if (array_diff(array_keys($i->getDirty()), self::MUTABLE)) {
                throw new LogicException('Invoices are immutable once issued. Issue a credit note instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Invoices cannot be deleted.'));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** Amount still invoiced after credit notes. */
    public function netTotal(): string
    {
        return bcsub((string) $this->total, (string) $this->creditNotes()->sum('total'), 2);
    }

    public function filename(): string
    {
        return 'Invoice-'.str_replace('/', '-', $this->number).'.pdf';
    }
}
