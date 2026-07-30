<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class QuranJuz extends Model
{
    use HasFactory;

    protected $fillable = [
        'juz_number',
        'from_page',
        'to_page',
    ];

    protected function casts(): array
    {
        return [
            'juz_number' => 'integer',
            'from_page' => 'integer',
            'to_page' => 'integer',
        ];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'quran_current_juz_id');
    }

    public function externallyMemorizedByStudents(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_external_memorized_juz')
            ->withTimestamps();
    }
}
