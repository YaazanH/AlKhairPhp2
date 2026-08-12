<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumSubject extends Model
{
    use HasFactory;

    protected $fillable = ['curriculum_id', 'subject_definition_id', 'sort_order'];
    protected function casts(): array { return ['sort_order' => 'integer']; }
    public function curriculum(): BelongsTo { return $this->belongsTo(Curriculum::class); }
    public function definition(): BelongsTo { return $this->belongsTo(CurriculumSubjectDefinition::class, 'subject_definition_id'); }
    public function resources(): BelongsToMany { return $this->belongsToMany(CurriculumResource::class, 'curriculum_subject_resources'); }
    public function lessons(): HasMany { return $this->hasMany(CurriculumLesson::class)->orderBy('sort_order')->orderBy('id'); }
}
