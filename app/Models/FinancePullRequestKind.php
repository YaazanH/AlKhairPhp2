<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancePullRequestKind extends Model
{
    use HasFactory;

    public const MODE_COUNT = 'count';
    public const MODE_INVOICE = 'invoice';

    public const MODES = [
        self::MODE_COUNT,
        self::MODE_INVOICE,
    ];

    protected $fillable = [
        'name',
        'code',
        'mode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(FinanceRequest::class, 'finance_pull_request_kind_id');
    }
}
