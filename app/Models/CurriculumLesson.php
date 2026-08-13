<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CurriculumLesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['curriculum_subject_id', 'curriculum_resource_id', 'name', 'page_count', 'importance', 'sort_order'];

    protected function casts(): array
    {
        return ['page_count' => 'integer', 'importance' => 'integer', 'sort_order' => 'integer'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class, 'curriculum_subject_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CurriculumResource::class, 'curriculum_resource_id');
    }

    public function progresses(): HasMany
    {
        return $this->hasMany(GroupCurriculumLessonProgress::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(CurriculumLessonTopic::class)->orderBy('sort_order')->orderBy('id');
    }
}
