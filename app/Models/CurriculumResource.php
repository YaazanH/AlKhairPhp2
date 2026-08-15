<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CurriculumResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['subject_definition_id', 'book_name', 'author', 'publisher', 'edition_number', 'published_on', 'is_active'];
    protected function casts(): array { return ['published_on' => 'date', 'is_active' => 'boolean']; }
    public function subjectDefinition(): BelongsTo { return $this->belongsTo(CurriculumSubjectDefinition::class, 'subject_definition_id'); }
    public function curriculumSubjects(): BelongsToMany { return $this->belongsToMany(CurriculumSubject::class, 'curriculum_subject_resources'); }
}
