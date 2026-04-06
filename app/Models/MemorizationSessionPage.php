<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemorizationSessionPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'memorization_session_id',
        'page_no',
    ];

    protected function casts(): array
    {
        return [
            'page_no' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(MemorizationSession::class, 'memorization_session_id');
    }
}
