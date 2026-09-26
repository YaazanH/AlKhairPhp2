<?php

namespace App\Support;

use App\Models;
use Illuminate\Database\Eloquent\Builder;

class DataAuditVisibility
{
    public const ROUTINE_MODELS = [
        Models\MemorizationSession::class,
        Models\QuranFinalTest::class,
        Models\QuranPartialTest::class,
        Models\QuranTest::class,
        Models\PointTransaction::class,
        Models\AssessmentResult::class,
        Models\GroupAttendanceDay::class,
        Models\StudentAttendanceDay::class,
        Models\StudentAttendanceRecord::class,
        Models\TeacherAttendanceDay::class,
        Models\TeacherAttendanceRecord::class,
        Models\StudentPageAchievement::class,
        Models\GroupCurriculumLessonProgress::class,
        Models\GroupCurriculumTopicProgress::class,
    ];

    public static function hiddenTypes(string $event): array
    {
        return match ($event) {
            'created' => [
                Models\Student::class,
                Models\ParentProfile::class,
                Models\Enrollment::class,
                Models\FinanceTransaction::class,
                ...self::ROUTINE_MODELS,
            ],
            'updated' => self::ROUTINE_MODELS,
            default => [],
        };
    }

    public static function apply(Builder $query): Builder
    {
        foreach (['created', 'updated'] as $event) {
            $query->where(fn (Builder $query) => $query
                ->where('event', '!=', $event)
                ->orWhereNull('event')
                ->orWhereNotIn('subject_type', self::hiddenTypes($event))
                ->orWhereNull('subject_type'));
        }

        return $query;
    }
}
