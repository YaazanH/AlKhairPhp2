<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'invoice_no',
        'invoicer_name',
        'invoice_type',
        'finance_invoice_kind_id',
        'finance_request_id',
        'issue_date',
        'due_date',
        'status',
        'subtotal',
        'discount',
        'total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function financeRequest(): BelongsTo
    {
        return $this->belongsTo(FinanceRequest::class);
    }

    public function invoiceKind(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoiceKind::class, 'finance_invoice_kind_id');
    }

    public function parentProfile(): BelongsTo
    {
        return $this->belongsTo(ParentProfile::class, 'parent_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
