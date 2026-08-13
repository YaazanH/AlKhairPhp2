<?php

namespace App\Models;

use App\Services\PointLedgerService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (Group $group): void {
            if ($group->wasChanged('course_id')) {
                app(PointLedgerService::class)->syncGroupEnrollmentCaches($group);
            }
        });
    }

    protected $fillable = [
        'course_id',
        'academic_year_id',
        'teacher_id',
        'assistant_teacher_id',
        'grade_level_id',
        'curriculum_id',
        'name',
        'capacity',
        'starts_on',
        'ends_on',
        'monthly_fee',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'monthly_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(GroupAttendanceDay::class);
    }

    public function assistantTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'assistant_teacher_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function curriculumProgresses(): HasMany
    {
        return $this->hasMany(GroupCurriculumLessonProgress::class);
    }

    public function curriculumTopicProgresses(): HasMany
    {
        return $this->hasMany(GroupCurriculumTopicProgress::class);
    }

    public function customCurriculumLessons(): HasMany
    {
        return $this->hasMany(GroupCustomCurriculumLesson::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(GroupSchedule::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
