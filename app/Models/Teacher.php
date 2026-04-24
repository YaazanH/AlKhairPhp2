<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'phone',
        'job_title',
        'photo_path',
        'teacher_job_title_id',
        'course_id',
        'status',
        'is_helping',
        'hired_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'is_helping' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $teacher): void {
            if ($teacher->wasChanged('photo_path') && $teacher->user_id) {
                $teacher->user()->update([
                    'profile_photo_path' => $teacher->photo_path,
                ]);
            }
        });
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(TeacherJobTitle::class, 'teacher_job_title_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assignedGroups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function assistedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'assistant_teacher_id');
    }

    public function memorizationSessions(): HasMany
    {
        return $this->hasMany(MemorizationSession::class);
    }

    public function quranTests(): HasMany
    {
        return $this->hasMany(QuranTest::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(TeacherAttendanceRecord::class);
    }

    public function assessmentResults(): HasMany
    {
        return $this->hasMany(AssessmentResult::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
