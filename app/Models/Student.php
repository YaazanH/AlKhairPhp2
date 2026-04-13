<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'parent_id',
        'first_name',
        'last_name',
        'student_number',
        'birth_date',
        'gender',
        'school_name',
        'grade_level_id',
        'quran_current_juz_id',
        'photo_path',
        'status',
        'joined_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $student): void {
            $expectedStudentNumber = (string) $student->id;

            if ($student->student_number !== $expectedStudentNumber) {
                $student->forceFill([
                    'student_number' => $expectedStudentNumber,
                ])->saveQuietly();
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function currentActiveEnrollment(): ?Enrollment
    {
        if ($this->relationLoaded('enrollments')) {
            /** @var Enrollment|null $enrollment */
            $enrollment = $this->enrollments
                ->filter(fn (Enrollment $enrollment) => $enrollment->status === 'active')
                ->sortByDesc(fn (Enrollment $enrollment): int => (($enrollment->enrolled_at?->getTimestamp() ?? 0) * 1000000) + $enrollment->id)
                ->first();

            return $enrollment;
        }

        return $this->enrollments()
            ->with('group')
            ->where('status', 'active')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->first();
    }

    public function currentGroupName(): ?string
    {
        return $this->currentActiveEnrollment()?->group?->name;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(StudentFile::class);
    }

    public function activityRegistrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function assessmentResults(): HasMany
    {
        return $this->hasMany(AssessmentResult::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function memorizationSessions(): HasMany
    {
        return $this->hasMany(MemorizationSession::class);
    }

    public function pageAchievements(): HasMany
    {
        return $this->hasMany(StudentPageAchievement::class);
    }

    public function studentNotes(): HasMany
    {
        return $this->hasMany(StudentNote::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function quranTests(): HasMany
    {
        return $this->hasMany(QuranTest::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function parentProfile(): BelongsTo
    {
        return $this->belongsTo(ParentProfile::class, 'parent_id');
    }

    public function quranCurrentJuz(): BelongsTo
    {
        return $this->belongsTo(QuranJuz::class, 'quran_current_juz_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
