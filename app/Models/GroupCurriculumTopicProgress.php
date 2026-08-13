<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCurriculumTopicProgress extends Model
{
    use HasFactory;

    protected $table = 'group_curriculum_topic_progresses';

    protected $fillable = ['group_id', 'curriculum_lesson_topic_id', 'teacher_id', 'taught_on'];

    protected function casts(): array
    {
        return ['taught_on' => 'date'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CurriculumLessonTopic::class, 'curriculum_lesson_topic_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
