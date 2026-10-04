<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDefinitionRevision extends Model
{
    protected $fillable = [
        'report_definition_id',
        'revision_number',
        'action',
        'snapshot',
        'restored_from_revision_number',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'snapshot' => 'array',
            'restored_from_revision_number' => 'integer',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
