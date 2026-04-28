<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class QuranFinalTestService
{
    public function create(Enrollment $enrollment, QuranJuz $juz): QuranFinalTest
    {
        if (! $this->eligibleJuzIdsForStudent($enrollment->student)->contains($juz->id)) {
            throw new LogicException(__('workflow.quran_final_tests.errors.juz_not_eligible'));
        }

        $existingTest = $this->existingTestForStudentAndJuz($enrollment->student_id, $juz->id);

        if ($existingTest?->status === 'passed') {
            throw new LogicException(__('workflow.quran_final_tests.errors.already_passed'));
        }

        if ($existingTest) {
            throw new LogicException(__('workflow.quran_final_tests.errors.open_cycle_exists'));
        }

        return DB::transaction(function () use ($enrollment, $juz): QuranFinalTest {
            return QuranFinalTest::query()->create([
                'created_by' => auth()->id(),
                'enrollment_id' => $enrollment->id,
                'juz_id' => $juz->id,
                'status' => 'in_progress',
                'student_id' => $enrollment->student_id,
            ])->fresh(['attempts.teacher', 'enrollment.group.course', 'juz', 'student.parentProfile']);
        });
    }

    public function eligibleJuzIdsForStudent(Student $student): Collection
    {
        $passedPartialJuzIds = QuranPartialTest::query()
            ->where('student_id', $student->id)
            ->where('status', 'passed')
            ->pluck('juz_id')
            ->map(fn (int $juzId) => (int) $juzId)
            ->all();

        $existingFinalJuzIds = QuranFinalTest::query()
            ->where('student_id', $student->id)
            ->pluck('juz_id')
            ->map(fn (int $juzId) => (int) $juzId)
            ->all();

        $legacyBlockedJuzIds = QuranTest::query()
            ->where('student_id', $student->id)
            ->where('status', 'passed')
            ->whereHas('type', fn ($query) => $query->whereIn('code', ['final', 'awqaf']))
            ->pluck('juz_id')
            ->map(fn (int $juzId) => (int) $juzId)
            ->all();

        return QuranJuz::query()
            ->orderBy('juz_number')
            ->get()
            ->filter(fn (QuranJuz $juz): bool => in_array($juz->id, $passedPartialJuzIds, true)
                && ! in_array($juz->id, $existingFinalJuzIds, true)
                && ! in_array($juz->id, $legacyBlockedJuzIds, true))
            ->pluck('id')
            ->values();
    }

    public function existingTestForStudentAndJuz(int $studentId, int $juzId): ?QuranFinalTest
    {
        return QuranFinalTest::query()
            ->with(['attempts.teacher', 'enrollment.group.course', 'juz'])
            ->where('student_id', $studentId)
            ->where('juz_id', $juzId)
            ->first();
    }

    public function recordAttempt(QuranFinalTest $finalTest, Teacher $teacher, array $data): QuranFinalTestAttempt
    {
        if ($finalTest->status === 'passed') {
            throw new LogicException(__('workflow.quran_final_tests.errors.already_passed'));
        }

        return DB::transaction(function () use ($finalTest, $teacher, $data): QuranFinalTestAttempt {
            $score = $data['score'] !== '' ? (float) $data['score'] : null;
            $status = $score !== null
                ? app(QuranFinalTestRuleService::class)->statusForScore($score)
                : null;

            if (! $status) {
                throw new LogicException(__('workflow.quran_final_tests.errors.score_not_in_range'));
            }

            $attempt = $finalTest->attempts()->create([
                'attempt_no' => $finalTest->attempts()->count() + 1,
                'notes' => blank($data['notes'] ?? null) ? null : $data['notes'],
                'score' => $score,
                'status' => $status,
                'teacher_id' => $teacher->id,
                'tested_on' => $data['tested_on'],
            ]);

            if ($attempt->status === 'passed') {
                $finalTest->update([
                    'passed_on' => $attempt->tested_on,
                    'status' => 'passed',
                ]);

                app(PointLedgerService::class)->recordQuranFinalTestPoints(
                    $finalTest->fresh(['enrollment.student.gradeLevel', 'student']),
                    $attempt->score !== null ? (float) $attempt->score : null,
                );
            }

            return $attempt->fresh(['finalTest', 'teacher']);
        });
    }
}
