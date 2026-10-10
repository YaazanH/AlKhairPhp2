<?php

namespace App\Services;

use App\Models\AssessmentResult;
use App\Models\GroupCurriculumLessonProgress;
use App\Models\LearningProgressionLevel;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentLearningProgression;
use App\Models\StudentLearningProgressionHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonLevelProgressionService
{
    public function assign(Student $student, ?User $actor = null): StudentLearningProgression
    {
        $settings = app(LearningProgressionService::class)->settings();
        if ($settings['profile'] !== LearningProgressionService::PROFILE_LESSON_LEVEL || ! $settings['configured']) {
            throw ValidationException::withMessages([
                'progression' => __('learning_progression.errors.lesson_profile_required'),
            ]);
        }

        $firstLevel = LearningProgressionLevel::query()->orderBy('sort_order')->orderBy('id')->first();
        if (! $firstLevel) {
            throw ValidationException::withMessages([
                'progression' => __('learning_progression.errors.no_levels'),
            ]);
        }

        return DB::transaction(function () use ($student, $actor, $firstLevel): StudentLearningProgression {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $existing = StudentLearningProgression::query()->where('student_id', $student->id)->first();
            if ($existing) {
                return $existing;
            }

            $now = now();
            $progression = StudentLearningProgression::query()->create([
                'student_id' => $student->id,
                'current_level_id' => $firstLevel->id,
                'status' => 'active',
                'assigned_by' => $actor?->id,
                'started_at' => $now,
                'level_started_at' => $now,
            ]);

            StudentLearningProgressionHistory::query()->create([
                'student_learning_progression_id' => $progression->id,
                'student_id' => $student->id,
                'to_level_id' => $firstLevel->id,
                'event' => 'assigned',
                'performed_by' => $actor?->id,
                'occurred_at' => $now,
            ]);

            return $progression->load('currentLevel');
        });
    }

    public function evaluateStudent(int $studentId): ?StudentLearningProgression
    {
        if (app(LearningProgressionService::class)->settings()['profile'] !== LearningProgressionService::PROFILE_LESSON_LEVEL) {
            return null;
        }

        return DB::transaction(function () use ($studentId): ?StudentLearningProgression {
            $progression = StudentLearningProgression::query()
                ->with('currentLevel')
                ->where('student_id', $studentId)
                ->lockForUpdate()
                ->first();

            if (! $progression || $progression->status !== 'active' || ! $progression->currentLevel) {
                return $progression;
            }

            $evidence = $this->evidence($progression);
            $progression->last_evaluated_at = now();

            if (! $evidence['eligible']) {
                $progression->save();

                return $progression->load('currentLevel');
            }

            $fromLevel = $progression->currentLevel;
            $nextLevel = LearningProgressionLevel::query()
                ->where('sort_order', '>', $fromLevel->sort_order)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($nextLevel) {
                $progression->update([
                    'current_level_id' => $nextLevel->id,
                    'level_started_at' => now(),
                    'last_evaluated_at' => now(),
                ]);
                $event = 'automatically_promoted';
            } else {
                $progression->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'last_evaluated_at' => now(),
                ]);
                $event = 'completed';
            }

            StudentLearningProgressionHistory::query()->create([
                'student_learning_progression_id' => $progression->id,
                'student_id' => $studentId,
                'from_level_id' => $fromLevel->id,
                'to_level_id' => $nextLevel?->id,
                'event' => $event,
                'evidence' => $evidence,
                'occurred_at' => now(),
            ]);

            return $progression->refresh()->load('currentLevel');
        });
    }

    public function assessmentResultSaved(AssessmentResult $result): void
    {
        if (app(LearningProgressionService::class)->settings()['profile'] !== LearningProgressionService::PROFILE_LESSON_LEVEL) {
            return;
        }

        DB::transaction(function () use ($result): void {
            $progression = StudentLearningProgression::query()
                ->with('currentLevel')
                ->where('student_id', $result->student_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $progression || ! $progression->currentLevel || (int) $progression->currentLevel->final_assessment_id !== (int) $result->assessment_id) {
                return;
            }

            $evidence = $this->evidence($progression);
            StudentLearningProgressionHistory::query()->create([
                'student_learning_progression_id' => $progression->id,
                'student_id' => $progression->student_id,
                'from_level_id' => $progression->current_level_id,
                'event' => 'assessment_attempted',
                'performed_by' => auth()->id(),
                'evidence' => [
                    ...$evidence,
                    'assessment_result_id' => $result->id,
                    'assessment_score' => $result->score !== null ? (float) $result->score : null,
                    'assessment_status' => $result->status,
                    'attempt_number' => (int) $result->attempt_no,
                ],
                'occurred_at' => now(),
            ]);
        });

        $this->evaluateStudent((int) $result->student_id);
    }

    public function evaluateGroup(int $groupId): void
    {
        StudentLearningProgression::query()
            ->where('status', 'active')
            ->whereHas('currentLevel.groups', fn ($query) => $query->whereKey($groupId))
            ->pluck('student_id')
            ->each(fn (int $studentId) => $this->evaluateStudent($studentId));
    }

    public function manuallyPromote(Student $student, User $actor, string $reason): StudentLearningProgression
    {
        if (! $actor->can('learning-progression.manual-promote')) {
            throw new AuthorizationException;
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'manualPromotionReason' => __('learning_progression.errors.manual_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($student, $actor, $reason): StudentLearningProgression {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $progression = StudentLearningProgression::query()
                ->with('currentLevel')
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if (! $progression || $progression->status !== 'active' || ! $progression->currentLevel) {
                throw ValidationException::withMessages([
                    'manualPromotionReason' => __('learning_progression.errors.no_active_level'),
                ]);
            }

            $fromLevel = $progression->currentLevel;
            $nextLevel = LearningProgressionLevel::query()
                ->where('sort_order', '>', $fromLevel->sort_order)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
            $evidence = $this->evidence($progression);

            if ($nextLevel) {
                $progression->update([
                    'current_level_id' => $nextLevel->id,
                    'level_started_at' => now(),
                    'last_evaluated_at' => now(),
                ]);
            } else {
                $progression->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'last_evaluated_at' => now(),
                ]);
            }

            StudentLearningProgressionHistory::query()->create([
                'student_learning_progression_id' => $progression->id,
                'student_id' => $student->id,
                'from_level_id' => $fromLevel->id,
                'to_level_id' => $nextLevel?->id,
                'event' => 'manually_promoted',
                'performed_by' => $actor->id,
                'reason' => $reason,
                'evidence' => $evidence,
                'occurred_at' => now(),
            ]);

            return $progression->refresh()->load('currentLevel');
        });
    }

    public function summary(Student $student): array
    {
        $progression = StudentLearningProgression::query()
            ->with(['currentLevel.finalAssessment', 'history.fromLevel', 'history.toLevel', 'history.performer'])
            ->where('student_id', $student->id)
            ->first();

        return [
            'progression' => $progression,
            'evidence' => $progression && $progression->status === 'active' ? $this->evidence($progression) : null,
        ];
    }

    public function evidence(StudentLearningProgression $progression): array
    {
        $level = $progression->currentLevel()->with(['lessons:id', 'groups:id'])->firstOrFail();
        $lessonIds = $level->lessons->pluck('id')->map(fn ($id): int => (int) $id);
        $groupIds = $level->groups->pluck('id')->map(fn ($id): int => (int) $id);
        $levelDate = $progression->level_started_at->toDateString();
        $usedEvidence = $progression->history()
            ->whereIn('event', ['automatically_promoted', 'completed', 'manually_promoted'])
            ->get(['evidence'])
            ->pluck('evidence');
        $usedDeliveryIds = $usedEvidence->flatMap(fn (?array $evidence) => $evidence['delivery_ids'] ?? [])->unique();
        $usedAssessmentResultIds = $usedEvidence->pluck('assessment_result_id')->filter()->unique();

        $deliveries = GroupCurriculumLessonProgress::query()
            ->whereIn('group_id', $groupIds)
            ->whereIn('curriculum_lesson_id', $lessonIds)
            ->where('status', 'taught')
            ->whereDate('taught_on', '>=', $levelDate)
            ->when($usedDeliveryIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $usedDeliveryIds))
            ->get();

        $deliveredLessonIds = $deliveries->pluck('curriculum_lesson_id')->map(fn ($id): int => (int) $id)->unique();
        $attendedLessonIds = $deliveries
            ->filter(function (GroupCurriculumLessonProgress $delivery) use ($progression): bool {
                return StudentAttendanceRecord::query()
                    ->where(fn ($query) => $query
                        ->where('student_id', $progression->student_id)
                        ->orWhereHas('enrollment', fn ($enrollments) => $enrollments->where('student_id', $progression->student_id)))
                    ->whereHas('status', fn ($statuses) => $statuses->where('is_present', true))
                    ->whereHas('attendanceDay', fn ($days) => $days
                        ->where('group_id', $delivery->group_id)
                        ->whereDate('attendance_date', $delivery->taught_on))
                    ->exists();
            })
            ->pluck('curriculum_lesson_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();

        $requiredLessons = $lessonIds->count();
        $attendancePercentage = $requiredLessons > 0
            ? round(($attendedLessonIds->count() / $requiredLessons) * 100, 2)
            : 0.0;
        $bestResult = AssessmentResult::query()
            ->where('student_id', $progression->student_id)
            ->where('assessment_id', $level->final_assessment_id)
            ->where('created_at', '>=', $progression->level_started_at)
            ->when($usedAssessmentResultIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $usedAssessmentResultIds))
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->first();
        $bestScore = $bestResult?->score;

        $lessonsComplete = $requiredLessons > 0 && $deliveredLessonIds->count() === $requiredLessons;
        $attendancePassed = $lessonsComplete && $attendancePercentage >= (float) $level->attendance_threshold;
        $assessmentPassed = $bestScore !== null && (float) $bestScore >= (float) $level->passing_score;

        return [
            'required_lessons' => $requiredLessons,
            'delivered_lessons' => $deliveredLessonIds->count(),
            'attended_lessons' => $attendedLessonIds->count(),
            'lessons_complete' => $lessonsComplete,
            'attendance_percentage' => $attendancePercentage,
            'attendance_required' => (float) $level->attendance_threshold,
            'attendance_passed' => $attendancePassed,
            'assessment_id' => $level->final_assessment_id,
            'assessment_result_id' => $bestResult?->id,
            'assessment_score' => $bestScore !== null ? (float) $bestScore : null,
            'assessment_required' => (float) $level->passing_score,
            'assessment_passed' => $assessmentPassed,
            'eligible' => $attendancePassed && $assessmentPassed,
            'delivery_ids' => $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }
}
