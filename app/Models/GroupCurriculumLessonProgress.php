<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCurriculumLessonProgress extends Model
{
    use HasFactory;

    protected $table = 'group_curriculum_lesson_progresses';

    protected $fillable = ['group_id', 'curriculum_lesson_id', 'teacher_id', 'status', 'taught_on'];
    protected function casts(): array { return ['taught_on' => 'date']; }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function lesson(): BelongsTo { return $this->belongsTo(CurriculumLesson::class, 'curriculum_lesson_id'); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
}
