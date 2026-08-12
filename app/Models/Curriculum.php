<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Curriculum extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['course_id', 'grade_level_id', 'name', 'is_active'];

    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function gradeLevel(): BelongsTo { return $this->belongsTo(GradeLevel::class); }
    public function subjects(): HasMany { return $this->hasMany(CurriculumSubject::class)->orderBy('sort_order')->orderBy('id'); }
    public function groups(): HasMany { return $this->hasMany(Group::class); }
}
