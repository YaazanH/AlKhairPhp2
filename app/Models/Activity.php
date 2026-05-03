<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'activity_date',
        'audience_scope',
        'group_id',
        'fee_amount',
        'expected_revenue_cached',
        'collected_revenue_cached',
        'expense_total_cached',
        'is_active',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'fee_amount' => 'decimal:2',
            'expected_revenue_cached' => 'decimal:2',
            'collected_revenue_cached' => 'decimal:2',
            'expense_total_cached' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function financeRequests(): HasMany
    {
        return $this->hasMany(FinanceRequest::class);
    }

    public function financeTransactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ActivityExpense::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function targetGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'activity_group_targets')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }
}
