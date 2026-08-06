<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    use HasFactory;

    public const TYPES = ['expense', 'income'];
    public const EXPENSE_MODES = ['count', 'invoice'];
    public const INCOME_MODES = ['return', 'income', 'donation'];

    protected $fillable = [
        'name',
        'code',
        'type',
        'mode',
        'is_donation',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_donation' => 'boolean',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(FinanceRequest::class, 'finance_category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'finance_category_id');
    }

    public function categoryType(): string
    {
        return in_array($this->type, ['expense', 'management'], true) ? 'expense' : 'income';
    }

    public function categoryMode(): string
    {
        if (filled($this->mode)) {
            return $this->mode;
        }

        return match ($this->type) {
            'management' => 'invoice',
            'return' => 'return',
            'revenue' => $this->is_donation ? 'donation' : 'income',
            default => 'count',
        };
    }

    public static function storageType(string $type, string $mode): string
    {
        if ($type === 'expense') {
            return 'expense';
        }

        return $mode === 'return' ? 'return' : 'revenue';
    }
}
