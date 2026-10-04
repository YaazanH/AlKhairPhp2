<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role;

class ReportDefinition extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name',
        'description',
        'data_source',
        'selected_fields',
        'calculations',
        'group_by',
        'presentation',
        'filters',
        'sort_field',
        'sort_direction',
        'status',
        'library_item_uuid',
        'library_revision',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'selected_fields' => 'array',
            'calculations' => 'array',
            'presentation' => 'array',
            'filters' => 'array',
            'library_revision' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function dashboardRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'report_dashboard_placements')
            ->withPivot(['position', 'size'])
            ->withTimestamps();
    }
}
