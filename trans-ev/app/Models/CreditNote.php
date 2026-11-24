<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/** GST credit note (s.34, Rule 53) against an invoice, e.g. for a refund. Immutable once issued. */
class CreditNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (CreditNote $c) => $c->uuid ??= (string) Str::uuid());
        static::updating(function (CreditNote $c) {
            if (array_diff(array_keys($c->getDirty()), Invoice::MUTABLE)) {
                throw new LogicException('Credit notes are immutable once issued.');
            }
        });
        static::deleting(fn () => throw new LogicException('Credit notes cannot be deleted.'));
    }

    public function isDebit(): bool
    {
        return $this->note_type === 'debit';
    }

    public function title(): string
    {
        return $this->isDebit() ? 'Debit note' : 'Credit note';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function filename(): string
    {
        return 'CreditNote-'.str_replace('/', '-', $this->number).'.pdf';
    }
}
