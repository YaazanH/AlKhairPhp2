<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranPartialTestAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

class GroupDailySummaryService
{
    public function openGroupCopyText(Group $group, string $date): string
    {
        return $this->currentCopyText($group, $date, [
            'attendance' => true,
            'memorization' => true,
            'partial_tests' => true,
            'final_tests' => true,
        ]);
    }

    /** @return array{attendance: bool, memorization: bool, partial_tests: bool, final_tests: bool} */
    public function visibilityFor(User $user): array
    {
        return [
            'attendance' => $user->can('attendance.student.view'),
            'memorization' => $user->can('memorization.view'),
            'partial_tests' => $user->can('quran-partial-tests.view'),
            'final_tests' => $user->can('quran-final-tests.view'),
        ];
    }

    public function currentCopyTextForUser(Group $group, string $date, User $user): string
    {
        return $this->currentCopyText($group, $date, $this->visibilityFor($user));
    }

    /** @param array{attendance?: bool, memorization?: bool, partial_tests?: bool, final_tests?: bool} $visibility */
    public function currentCopyText(Group $group, string $date, array $visibility): string
    {
        $group->loadMissing(['course', 'teacher']);

        return $this->copyText($group, $date, $this->build($group, $date, $visibility));
    }

    /**
     * @param  array{attendance?: bool, memorization?: bool, partial_tests?: bool, final_tests?: bool}  $visibility
     * @return array{rows: Collection<int, object>, partial_tests: Collection<int, object>, final_tests: Collection<int, object>}
     */
    public function build(Group $group, string $date, array $visibility): array
    {
        $enrollments = Enrollment::query()
            ->with('student.parentProfile')
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->orderBy('enrolled_at')
            ->orderBy('id')
            ->get();

        if ($enrollments->isEmpty()) {
            return ['rows' => collect(), 'partial_tests' => collect(), 'final_tests' => collect()];
        }

        $enrollmentIds = $enrollments->pluck('id');
        $attendanceRecords = ($visibility['attendance'] ?? false)
            ? GroupAttendanceDay::query()
                ->where('group_id', $group->id)
                ->whereDate('attendance_date', $date)
                ->with('records.status')
                ->get()
                ->flatMap->records
                ->keyBy('enrollment_id')
            : collect();
        $memorizationSessions = ($visibility['memorization'] ?? false)
            ? MemorizationSession::query()
                ->with(['pages' => fn ($query) => $query->orderBy('page_no')])
                ->whereIn('enrollment_id', $enrollmentIds)
                ->whereDate('recorded_on', $date)
                ->where('entry_type', 'new')
                ->get()
                ->groupBy('enrollment_id')
            : collect();

        $rows = $enrollments->map(function (Enrollment $enrollment) use ($attendanceRecords, $memorizationSessions, $visibility, $date): object {
            $studentName = $enrollment->student?->full_name ?: __('crud.common.not_available');
            $attendanceLabel = ($visibility['attendance'] ?? false)
                ? ($attendanceRecords->get($enrollment->id)?->status?->name ?: __('crud.groups.quick_summary.attendance_missing'))
                : __('crud.groups.quick_summary.attendance_unavailable');
            $pages = ($visibility['memorization'] ?? false)
                ? $memorizationSessions->get($enrollment->id, collect())
                    ->flatMap(fn (MemorizationSession $session) => $this->sessionPages($session))
                    ->unique()
                    ->sort()
                    ->values()
                : collect();
            $memorizedLabel = ($visibility['memorization'] ?? false)
                ? ($pages->isNotEmpty()
                    ? __('crud.groups.quick_summary.memorized_pages', ['pages' => $this->formatPages($pages)])
                    : __('crud.groups.quick_summary.memorization_missing'))
                : __('crud.groups.quick_summary.memorization_unavailable');

            return (object) [
                'enrollment_id' => $enrollment->id,
                'student_name' => $studentName,
                'student_number' => $enrollment->student?->student_number,
                'parent_name' => $enrollment->student?->parentProfile?->father_name,
                'attendance_label' => $attendanceLabel,
                'is_present' => (bool) $attendanceRecords->get($enrollment->id)?->status?->is_present,
                'memorized_label' => $memorizedLabel,
                'memorized_pages' => $pages,
                'copy_text' => implode(PHP_EOL, [
                    __('crud.groups.quick_summary.copy_lines.student', ['value' => $studentName]),
                    __('crud.groups.quick_summary.copy_lines.date', ['value' => $date]),
                    __('crud.groups.quick_summary.copy_lines.attendance', ['value' => $attendanceLabel]),
                    __('crud.groups.quick_summary.copy_lines.memorized', ['value' => $memorizedLabel]),
                ]),
            ];
        })->values();

        return [
            'rows' => $rows,
            'partial_tests' => ($visibility['partial_tests'] ?? false)
                ? $this->partialTests($enrollmentIds, $date)
                : collect(),
            'final_tests' => ($visibility['final_tests'] ?? false)
                ? $this->finalTests($enrollmentIds, $date)
                : collect(),
        ];
    }

    /** @param array{rows: Collection<int, object>, partial_tests: Collection<int, object>, final_tests: Collection<int, object>} $summary */
    public function copyText(Group $group, string $date, array $summary): string
    {
        $blocks = [implode(PHP_EOL, [
            $group->name,
            trim(($group->course?->name ?: __('crud.common.not_available')).' / '.$date),
        ])];

        $attendingStudents = $summary['rows']
            ->filter(fn (object $row) => $row->is_present)
            ->pluck('student_name')
            ->unique()
            ->values();
        $blocks[] = __('crud.groups.quick_summary.copy_sections.attendance').PHP_EOL
            .$attendingStudents->implode(PHP_EOL);

        $memorizingStudents = $summary['rows']
            ->filter(fn (object $row) => $row->memorized_pages->isNotEmpty())
            ->map(fn (object $row) => $row->student_name.' '.$this->formatPages($row->memorized_pages))
            ->values();
        if ($memorizingStudents->isNotEmpty()) {
            $blocks[] = __('crud.groups.quick_summary.copy_sections.memorization').PHP_EOL
                .$memorizingStudents->implode(PHP_EOL);
        }

        if ($summary['partial_tests']->isNotEmpty()) {
            $blocks[] = __('crud.groups.quick_summary.copy_sections.partial_tests').PHP_EOL
                .$summary['partial_tests']->map(fn (object $test) => __('crud.groups.quick_summary.tests.partial_line', [
                    'student' => $test->student_name,
                    'juz' => $test->juz_number,
                    'quarter' => collect($test->part_numbers)
                        ->map(fn (int $partNumber) => __('crud.groups.quick_summary.tests.quarters.'.$partNumber))
                        ->implode('، '),
                ]))->implode(PHP_EOL);
        }

        if ($summary['final_tests']->isNotEmpty()) {
            $blocks[] = __('crud.groups.quick_summary.copy_sections.final_tests').PHP_EOL
                .$summary['final_tests']->map(fn (object $test) => __('crud.groups.quick_summary.tests.final_line', [
                    'student' => $test->student_name,
                    'juz' => $test->juz_number,
                ]))->implode(PHP_EOL);
        }

        $blocks[] = __('crud.groups.quick_summary.copy_sections.closing');

        return implode(PHP_EOL.PHP_EOL, array_filter($blocks));
    }

    private function partialTests(Collection $enrollmentIds, string $date): Collection
    {
        return QuranPartialTestAttempt::query()
            ->whereDate('tested_on', $date)
            ->where('status', 'passed')
            ->whereHas('part.partialTest', fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            ->with(['part.partialTest.student', 'part.partialTest.juz'])
            ->get()
            ->sortBy(fn (QuranPartialTestAttempt $attempt) => sprintf(
                '%s-%03d-%d-%010d',
                $attempt->part?->partialTest?->student?->full_name,
                $attempt->part?->partialTest?->juz?->juz_number,
                $attempt->part?->part_number,
                $attempt->id,
            ))
            ->groupBy(fn (QuranPartialTestAttempt $attempt) => $attempt->part?->partialTest?->student_id.'-'.$attempt->part?->partialTest?->juz_id)
            ->map(function (Collection $attempts): object {
                $attempt = $attempts->first();

                return (object) [
                    'student_name' => $attempt?->part?->partialTest?->student?->full_name ?: __('crud.common.not_available'),
                    'juz_number' => $attempt?->part?->partialTest?->juz?->juz_number ?: __('crud.common.not_available'),
                    'part_numbers' => $attempts->map(fn (QuranPartialTestAttempt $item) => (int) $item->part?->part_number)->unique()->sort()->values()->all(),
                ];
            })
            ->values();
    }

    private function finalTests(Collection $enrollmentIds, string $date): Collection
    {
        return QuranFinalTestAttempt::query()
            ->whereDate('tested_on', $date)
            ->where('status', 'passed')
            ->whereHas('finalTest', fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            ->with(['finalTest.student', 'finalTest.juz'])
            ->get()
            ->sortBy(fn (QuranFinalTestAttempt $attempt) => sprintf(
                '%s-%03d-%010d',
                $attempt->finalTest?->student?->full_name,
                $attempt->finalTest?->juz?->juz_number,
                $attempt->id,
            ))
            ->map(fn (QuranFinalTestAttempt $attempt): object => (object) [
                'student_name' => $attempt->finalTest?->student?->full_name ?: __('crud.common.not_available'),
                'juz_number' => $attempt->finalTest?->juz?->juz_number ?: __('crud.common.not_available'),
            ])
            ->unique(fn (object $attempt) => $attempt->student_name.'-'.$attempt->juz_number)
            ->values();
    }

    private function sessionPages(MemorizationSession $session): Collection
    {
        $pages = $session->pages->pluck('page_no')->map(fn ($page) => (int) $page)->filter()->values();

        if ($pages->isEmpty() && filled($session->from_page) && filled($session->to_page)) {
            return collect(range((int) min($session->from_page, $session->to_page), (int) max($session->from_page, $session->to_page)));
        }

        return $pages;
    }

    private function formatPages(Collection $pages): string
    {
        $ranges = [];
        $start = $end = $pages->first();

        foreach ($pages->slice(1) as $page) {
            if ($page === $end + 1) {
                $end = $page;

                continue;
            }

            $ranges[] = $start === $end ? (string) $start : $start.'-'.$end;
            $start = $end = $page;
        }

        $ranges[] = $start === $end ? (string) $start : $start.'-'.$end;

        return implode(', ', $ranges);
    }
}
