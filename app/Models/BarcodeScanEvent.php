<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BarcodeScanEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'barcode_scan_import_id',
        'sequence_no',
        'raw_value',
        'normalized_value',
        'token_type',
        'barcode_action_id',
        'student_id',
        'enrollment_id',
        'result',
        'message',
        'applied_model_type',
        'applied_model_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(BarcodeAction::class, 'barcode_action_id');
    }

    public function appliedModel(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'applied_model_type', 'applied_model_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BarcodeScanImport::class, 'barcode_scan_import_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
