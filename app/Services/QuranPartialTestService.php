<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestAttempt;
use App\Models\QuranPartialTestPart;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class QuranPartialTestService
{
    public function create(Enrollment $enrollment, QuranJuz $juz): QuranPartialTest
    {
        if (! $this->eligibleJuzIdsForStudent($enrollment->student)->contains($juz->id)) {
            throw new LogicException(__('workflow.quran_partial_tests.errors.juz_not_eligible'));
        }

        return DB::transaction(function () use ($enrollment, $juz): QuranPartialTest {
            $partialTest = QuranPartialTest::query()->create([
                'created_by' => auth()->id(),
                'enrollment_id' => $enrollment->id,
                'juz_id' => $juz->id,
                'status' => 'in_progress',
                'student_id' => $enrollment->student_id,
            ]);

            foreach (range(1, 4) as $partNumber) {
                $partialTest->parts()->create([
                    'part_number' => $partNumber,
                    'status' => 'pending',
                ]);
            }

            return $partialTest->fresh(['enrollment.group.course', 'juz', 'parts', 'student.parentProfile']);
        });
    }

    public function eligibleJuzIdsForStudent(Student $student): Collection
    {
        $completedPages = $student->pageAchievements()
            ->pluck('page_no')
            ->map(fn (int $pageNo) => (int) $pageNo)
            ->flip();

        $existingJuzIds = QuranPartialTest::query()
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

        $legacyPartialCounts = QuranTest::query()
            ->select('juz_id', DB::raw('count(*) as passed_count'))
            ->where('student_id', $student->id)
            ->where('status', 'passed')
            ->whereHas('type', fn ($query) => $query->where('code', 'partial'))
            ->groupBy('juz_id')
            ->pluck('passed_count', 'juz_id');

        return QuranJuz::query()
            ->orderBy('juz_number')
            ->get()
            ->filter(function (QuranJuz $juz) use ($completedPages, $existingJuzIds, $legacyBlockedJuzIds, $legacyPartialCounts): bool {
                if (in_array($juz->id, $existingJuzIds, true) || in_array($juz->id, $legacyBlockedJuzIds, true)) {
                    return false;
                }

                if ((int) ($legacyPartialCounts[$juz->id] ?? 0) >= 4) {
                    return false;
                }

                foreach (range($juz->from_page, $juz->to_page) as $pageNo) {
                    if (! $completedPages->has($pageNo)) {
                        return false;
                    }
                }

                return true;
            })
            ->pluck('id')
            ->values();
    }

    public function inProgressTestsForStudent(Student $student): Collection
    {
        return QuranPartialTest::query()
            ->with([
                'enrollment.group.course',
                'juz',
                'parts',
            ])
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->orderByDesc('id')
            ->get();
    }

    public function recordAttempt(QuranPartialTestPart $part, Teacher $teacher, array $data): QuranPartialTestAttempt
    {
        if ($part->status === 'passed') {
            throw new LogicException(__('workflow.quran_partial_tests.errors.part_already_passed'));
        }

        return DB::transaction(function () use ($part, $teacher, $data): QuranPartialTestAttempt {
            $score = $data['score'] !== '' ? (float) $data['score'] : null;
            $status = $score !== null
                ? app(QuranPartialTestRuleService::class)->statusForScore($score)
                : null;

            if (! $status) {
                throw new LogicException(__('workflow.quran_partial_tests.errors.score_not_in_range'));
            }

            $attempt = $part->attempts()->create([
                'attempt_no' => $part->attempts()->count() + 1,
                'notes' => blank($data['notes'] ?? null) ? null : $data['notes'],
                'score' => $score,
                'status' => $status,
                'teacher_id' => $teacher->id,
                'tested_on' => $data['tested_on'],
            ]);

            if ($attempt->status === 'passed') {
                $part->update([
                    'passed_on' => $attempt->tested_on,
                    'status' => 'passed',
                ]);

                $pointLedger = app(PointLedgerService::class);

                $pointLedger->recordQuranPartialTestPartPoints(
                    $part->fresh(['partialTest.enrollment.student.gradeLevel']),
                    $attempt->score !== null ? (float) $attempt->score : null,
                );

                $partialTest = $part->partialTest()->with(['parts', 'enrollment.student.gradeLevel'])->firstOrFail();

                if ($partialTest->parts->every(fn (QuranPartialTestPart $partialPart) => $partialPart->status === 'passed')) {
                    $partialTest->update([
                        'passed_on' => $attempt->tested_on,
                        'status' => 'passed',
                    ]);

                    $pointLedger->recordQuranPartialTestPoints($partialTest);
                }
            }

            return $attempt->fresh(['part.partialTest', 'teacher']);
        });
    }
}
