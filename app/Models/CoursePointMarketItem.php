<?php

namespace App\Models;

use App\Support\NumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePointMarketItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'course_point_market_department_id',
        'invoice_id',
        'invoice_item_id',
        'invoice_sort_order',
        'invoice_item_sort_order',
        'item_name',
        'quantity',
        'unit_price',
        'currency_code',
        'currency_symbol',
        'currency_decimal_places',
        'local_unit_price',
        'local_currency_code',
        'local_currency_symbol',
        'local_currency_decimal_places',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'currency_decimal_places' => 'integer',
            'invoice_sort_order' => 'integer',
            'invoice_item_sort_order' => 'integer',
            'local_currency_decimal_places' => 'integer',
            'local_unit_price' => 'decimal:2',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(CoursePointMarketDepartment::class, 'course_point_market_department_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function points(float|int|string|null $pointPrice = null): int
    {
        $price = (float) ($pointPrice ?? $this->department?->point_price ?? 1);

        if ($price <= 0) {
            return 0;
        }

        return (int) (round(((float) $this->local_unit_price / $price) / 10, 0, PHP_ROUND_HALF_UP) * 10);
    }

    public function scopeInInvoiceOrder(Builder $query): Builder
    {
        return $query
            ->orderBy('invoice_sort_order')
            ->orderBy('invoice_item_sort_order')
            ->orderBy('invoice_item_id')
            ->orderBy('id');
    }

    public function formattedAmount(string $field, bool $local = false): string
    {
        $decimals = $local ? $this->local_currency_decimal_places : $this->currency_decimal_places;
        $symbol = $local
            ? ($this->local_currency_symbol ?: $this->local_currency_code)
            : ($this->currency_symbol ?: $this->currency_code);

        return NumberFormatter::withSuffix($this->{$field}, $symbol, (int) $decimals);
    }
}
