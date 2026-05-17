<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceReportTemplate extends Model
{
    use HasFactory;

    public const LANGUAGE_AR = 'ar';
    public const LANGUAGE_BOTH = 'both';
    public const LANGUAGE_EN = 'en';

    public const LANGUAGES = [
        self::LANGUAGE_AR,
        self::LANGUAGE_EN,
        self::LANGUAGE_BOTH,
    ];

    public const DEFAULT_COLUMNS = [
        'transaction_date',
        'transaction_no',
        'description',
        'type',
        'category',
        'income',
        'expense',
        'running_balance',
        'entered_by',
        'reference',
    ];

    protected $fillable = [
        'background_image',
        'columns',
        'created_by',
        'custom_date',
        'custom_text',
        'date_mode',
        'footer_text',
        'header_text',
        'include_closing_balance',
        'include_exported_at',
        'include_opening_balance',
        'is_default',
        'language',
        'logo_image',
        'name',
        'shape_color',
        'shape_opacity',
        'shape_type',
        'show_issuer_name',
        'show_page_numbers',
        'subtitle',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'custom_date' => 'date',
            'include_closing_balance' => 'boolean',
            'include_exported_at' => 'boolean',
            'include_opening_balance' => 'boolean',
            'is_default' => 'boolean',
            'show_issuer_name' => 'boolean',
            'show_page_numbers' => 'boolean',
            'shape_opacity' => 'float',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function normalizedColumns(): array
    {
        $available = array_keys(app(\App\Services\FinanceReportService::class)->ledgerColumnDefinitions());
        $columns = is_array($this->columns) ? $this->columns : [];
        $columns = array_values(array_intersect($columns, $available));

        return $columns === [] ? self::DEFAULT_COLUMNS : $columns;
    }

    public function getBackgroundImageUrlAttribute(): ?string
    {
        return $this->assetUrl($this->background_image);
    }

    public function getLogoImageUrlAttribute(): ?string
    {
        return $this->assetUrl($this->logo_image);
    }

    protected function assetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
