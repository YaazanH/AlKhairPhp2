<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GroupCustomCurriculumLesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['group_id', 'teacher_id', 'subject_name', 'name', 'page_count', 'importance', 'status', 'taught_on'];
    protected function casts(): array { return ['page_count' => 'integer', 'importance' => 'integer', 'taught_on' => 'date']; }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
}
