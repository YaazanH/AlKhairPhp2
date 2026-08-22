<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentPageAchievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Quran progress snapshot for the mobile app.
 *
 * Mirrors the calculation in resources/views/livewire/students/progress.blade.php
 * (the dashboard's student progress page) so the app shows the same numbers,
 * the same per-juz status and the same missing-page lists. The dashboard view is
 * not modified — this reads the same tables through the same AccessScopeService
 * scopes.
 *
 * Page truth comes from student_page_achievements: one row per page the student
 * has actually recited, which is what makes "which pages are still missing from
 * this juz" answerable.
 */
class MobileStudentProgressService
{
    public function __construct(private AccessScopeService $scopes)
    {
    }

    /**
     * @return array{stats: array, juz: array, totals: array}
     */
    public function snapshot(Student $student, ?User $user): array
    {
        $enrollments = $this->scopes
            ->scopeEnrollments(
                Enrollment::query()
                    ->with(['group.course'])
                    ->where('student_id', $student->id),
                $user
            )
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();

        $enrollmentIds = $enrollments->pluck('id')->all();

        // The dashboard reports headline figures against the default course only,
        // so a student enrolled in several courses is not double counted.
        $defaultCourseId = Course::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->value('id');

        $highlightEnrollments = $defaultCourseId
            ? $enrollments->filter(fn (Enrollment $enrollment): bool => (int) $enrollment->group?->course_id === (int) $defaultCourseId)
            : collect();
        $highlightEnrollmentIds = $highlightEnrollments->pluck('id')->all();

        $generalPages = $this->achievedPages($student);
        $highlightPages = $highlightEnrollmentIds === []
            ? collect()
            : $this->achievedPages($student, $highlightEnrollmentIds);

        $canView = fn (string $permission): bool => (bool) $user?->can($permission);

        $partialTests = $canView('quran-partial-tests.view')
            ? $this->scopes->scopeQuranPartialTests(
                QuranPartialTest::query()
                    ->with(['parts'])
                    ->where('student_id', $student->id)
                    ->when($enrollmentIds === [],
                        fn (Builder $query) => $query->whereRaw('1 = 0'),
                        fn (Builder $query) => $query->whereIn('enrollment_id', $enrollmentIds)),
                $user
            )->get()
            : collect();

        $finalTests = $canView('quran-final-tests.view')
            ? $this->scopes->scopeQuranFinalTests(
                QuranFinalTest::query()
                    ->with(['attempts', 'enrollment.group.course'])
                    ->where('student_id', $student->id)
                    ->when($enrollmentIds === [],
                        fn (Builder $query) => $query->whereRaw('1 = 0'),
                        fn (Builder $query) => $query->whereIn('enrollment_id', $enrollmentIds)),
                $user
            )->get()
            : collect();

        $passedAwqafByJuz = ($canView('quran-awqaf-tests.view') || $canView('quran-tests.view'))
            ? $this->scopes->scopeQuranTests(
                QuranTest::query()
                    ->where('student_id', $student->id)
                    ->whereHas('type', fn (Builder $query) => $query->where('code', 'awqaf'))
                    ->where('status', 'passed')
                    ->when($enrollmentIds === [],
                        fn (Builder $query) => $query->whereRaw('1 = 0'),
                        fn (Builder $query) => $query->whereIn('enrollment_id', $enrollmentIds)),
                $user
            )->get()->groupBy('juz_id')
            : collect();

        $attendanceDays = $canView('attendance.student.view') && $highlightEnrollmentIds !== []
            ? $this->scopes->scopeStudentAttendanceRecords(
                StudentAttendanceRecord::query()
                    ->whereHas('status', fn (Builder $query) => $query->where('is_present', true))
                    ->whereIn('enrollment_id', $highlightEnrollmentIds),
                $user
            )->distinct('group_attendance_day_id')->count('group_attendance_day_id')
            : 0;

        $pageSet = $generalPages->flip();
        $externalJuzIds = $this->externalMemorizedJuzIds($student);

        $juzRows = QuranJuz::query()
            ->orderBy('juz_number')
            ->get()
            ->map(function (QuranJuz $juz) use ($pageSet, $partialTests, $finalTests, $passedAwqafByJuz, $externalJuzIds): array {
                $memorizedExternally = in_array((int) $juz->id, $externalJuzIds, true);
                $pages = collect(range((int) $juz->from_page, (int) $juz->to_page));
                $missingPages = $pages->reject(fn (int $page): bool => $pageSet->has($page))->values();
                $memorizedPages = $pages->reject(fn (int $page): bool => ! $pageSet->has($page))->values();

                $juzPartialTests = $partialTests->where('juz_id', $juz->id);
                $passedParts = $juzPartialTests->flatMap->parts->where('status', 'passed')->pluck('part_number')->unique()->count();

                $juzFinalTests = $finalTests->where('juz_id', $juz->id);
                $latestFinalAttempt = $juzFinalTests->flatMap->attempts
                    ->sortByDesc(fn ($attempt): string => sprintf('%010d-%010d', $attempt->tested_on?->timestamp ?? 0, $attempt->id))
                    ->first();
                $finalPassed = $juzFinalTests->contains('status', 'passed')
                    || $juzFinalTests->flatMap->attempts->contains('status', 'passed');

                $latestAwqaf = $passedAwqafByJuz->get($juz->id, collect())->sortByDesc('tested_on')->first();

                $status = $finalPassed ? 'finished' : ($missingPages->isNotEmpty() ? 'missing' : 'awaiting');

                return [
                    'juz_id' => $juz->id,
                    'juz_number' => $juz->juz_number,
                    'from_page' => $juz->from_page,
                    'to_page' => $juz->to_page,
                    'total_pages' => $pages->count(),
                    'memorized_pages_count' => $pages->count() - $missingPages->count(),
                    // The two lists the dashboard exposes: what was recited and
                    // what is still outstanding for this juz.
                    'memorized_pages' => $memorizedPages,
                    'missing_pages' => $missingPages,
                    'memorized_externally' => $memorizedExternally,
                    'passed_parts' => $passedParts,
                    'partial_test_created' => $juzPartialTests->isNotEmpty(),
                    'latest_final_score' => $latestFinalAttempt?->score !== null
                        ? round((float) $latestFinalAttempt->score, 2)
                        : null,
                    'latest_final_date' => $latestFinalAttempt?->tested_on?->toDateString(),
                    'latest_final_course' => $juzFinalTests->first()?->enrollment?->group?->course?->name,
                    'final_made' => $latestFinalAttempt !== null,
                    'final_passed' => $finalPassed,
                    'awqaf_passed' => $latestAwqaf !== null,
                    'awqaf_passed_on' => $latestAwqaf?->tested_on?->toDateString(),
                    'status' => $memorizedExternally ? 'memorized_before' : $status,
                ];
            })
            // Same filter as the dashboard: only juz the student has touched.
            ->filter(fn (array $row): bool => $row['memorized_externally']
                || $row['memorized_pages_count'] > 0
                || $row['passed_parts'] > 0
                || $row['latest_final_score'] !== null)
            ->values();

        return [
            'stats' => [
                'attendance_days' => $attendanceDays,
                'memorized_pages' => $highlightPages->count(),
                'quran_partial_tests' => $partialTests->whereIn('enrollment_id', $highlightEnrollmentIds)->count(),
                'quran_final_tests' => $finalTests->whereIn('enrollment_id', $highlightEnrollmentIds)->count(),
                'points' => (int) $highlightEnrollments->sum('final_points_cached'),
            ],
            'totals' => [
                'pages_in_quran' => 604,
                'memorized_pages_all_courses' => $generalPages->count(),
                'completed_juz' => $juzRows->filter(fn (array $row): bool => $row['missing_pages']->isEmpty())->count(),
                'in_progress_juz' => $juzRows->filter(fn (array $row): bool => $row['status'] === 'missing')->count(),
            ],
            'juz' => $juzRows,
        ];
    }

    /**
     * Juz the student had already memorised before joining, entered by hand in
     * the dashboard.
     *
     * The pivot table is skipped when it is absent so the endpoint still serves
     * page progress on databases that predate that feature, instead of failing
     * the whole request.
     *
     * @return array<int, int>
     */
    protected function externalMemorizedJuzIds(Student $student): array
    {
        if (! Schema::hasTable('student_external_memorized_juz')) {
            return [];
        }

        return $student->externalMemorizedJuzs
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Distinct page numbers the student has achieved, optionally restricted to
     * the enrollments that belong to the default course.
     *
     * @param  array<int, int>|null  $enrollmentIds
     */
    protected function achievedPages(Student $student, ?array $enrollmentIds = null): Collection
    {
        return StudentPageAchievement::query()
            ->where('student_id', $student->id)
            ->when($enrollmentIds !== null, fn (Builder $query) => $query->whereIn('first_enrollment_id', $enrollmentIds))
            ->distinct()
            ->pluck('page_no')
            ->map(fn ($page): int => (int) $page)
            ->unique()
            ->values();
    }
}
