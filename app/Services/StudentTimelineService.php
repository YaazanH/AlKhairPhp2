<?php

namespace App\Services;

use Illuminate\Support\Collection;

class StudentTimelineService
{
    /** One course per page, using only records already restricted to the viewer. Null totals mean no permission. */
    public function build(Collection $enrollments, ?Collection $sessions, ?Collection $attendance, Collection $finalTests, Collection $awqafTests, Collection $finalExams, ?Collection $points): Collection
    {
        return $enrollments->filter(fn ($row) => $row->group?->course)
            ->groupBy('group.course.id')->map(function (Collection $rows) use ($sessions, $attendance, $finalTests, $awqafTests, $finalExams, $points) {
                $course = $rows->first()->group->course;
                $ids = $rows->pluck('id');
                $milestones = collect();
                $add = function ($date, string $kind, string $title, string $detail = '', string $highlight = '', array $stats = []) use ($milestones): void {
                    $milestones->push(['date' => $date?->format('Y-m-d'), 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'highlight' => $highlight, 'stats' => $stats]);
                };
                foreach ($rows->sortBy('enrolled_at') as $row) {
                    $teacher = $row->group->relationLoaded('teacher') ? $row->group->teacher : null;
                    $teacherName = $teacher ? trim($teacher->first_name.' '.$teacher->last_name) : '';
                    $detail = $row->group->name.($teacherName !== '' ? ' · '.__('student_timeline.teacher', ['name' => $teacherName]) : '');
                    $add($row->enrolled_at, 'joined', __('student_timeline.joined'), $detail, $row->group->name, $this->detailRows([
                        'group' => $row->group->name, 'teacher' => $teacherName,
                    ]));
                }
                // Keep the latest outcome of each saber, rather than every attempt.
                foreach ($finalTests->whereIn('enrollment_id', $ids) as $test) {
                    $attempt = $test->attempts->sortByDesc(fn ($row) => ($row->tested_on?->format('Y-m-d') ?? '').sprintf('%010d', $row->id))->first();
                    if ($attempt) {
                        $add($attempt->tested_on, 'final', __('student_timeline.final'), $this->testDetail($test, $attempt), __('workflow.common.labels.juz_number', ['number' => $test->juz?->juz_number ?? '—']), $this->testStats($test, $attempt, $test->attempts->count()));
                    }
                }
                foreach ($awqafTests->whereIn('enrollment_id', $ids)->sortByDesc(fn ($row) => ($row->tested_on?->format('Y-m-d') ?? '').sprintf('%010d', $row->id))->unique('juz_id') as $test) {
                    $add($test->tested_on, 'awqaf', __('student_timeline.awqaf'), $this->testDetail($test, $test), __('workflow.common.labels.juz_number', ['number' => $test->juz?->juz_number ?? '—']), $this->testStats($test, $test));
                }
                foreach ($finalExams->whereIn('enrollment_id', $ids)->sortByDesc('id')->unique('assessment_id') as $result) {
                    $add($result->assessment?->scheduled_at ?? $result->created_at, 'exam', __('student_timeline.exam'),
                        ($result->assessment?->title ?? '').' · '.__('student_timeline.score', ['score' => $result->score === null ? '—' : (float) $result->score])
                        .(in_array($result->status, ['passed', 'failed', 'pending'], true) ? ' · '.__('workflow.common.result_status.'.$result->status) : ''),
                        $result->score === null ? '' : __('student_timeline.score', ['score' => (float) $result->score]), $this->detailRows([
                            'assessment' => $result->assessment?->title,
                            'result' => in_array($result->status, ['passed', 'failed', 'pending'], true) ? __('workflow.common.result_status.'.$result->status) : null,
                            'score' => $result->score === null ? null : (string) (float) $result->score,
                        ]));
                }
                $complete = (bool) $course->finished_at || $rows->every(fn ($row) => $row->status === 'completed');
                $completionDate = $complete
                    ? ($rows->where('status', 'completed')->pluck('left_at')->filter()->sortDesc()->first() ?? $course->finished_at)
                    : null;
                $firstSession = $sessions?->whereIn('enrollment_id', $ids)->filter(fn ($session) => $session->recorded_on)->sortBy('recorded_on')->first();
                if ($firstSession) {
                    $firstPages = $this->sessionPages($firstSession);
                    $sessionTeacher = $firstSession->relationLoaded('teacher') ? $firstSession->teacher : null;
                    $firstPageDetail = __('student_timeline.page_numbers', ['pages' => $firstPages->implode('، ')]);
                    $add($firstSession->recorded_on, 'memorization', __('student_timeline.first_memorization'), $firstPageDetail,
                        __('student_timeline.page_count', ['count' => $firstPages->count()]), $this->detailRows([
                            'page_count' => __('student_timeline.page_count', ['count' => $firstPages->count()]),
                            'page_numbers' => $firstPages->implode('، '),
                            'teacher' => $sessionTeacher ? trim($sessionTeacher->first_name.' '.$sessionTeacher->last_name) : null,
                        ]));
                }
                $pages = $sessions?->whereIn('enrollment_id', $ids)->flatMap(fn ($session) => $this->sessionPages($session))->unique()->count();
                $recordedDays = $attendance?->whereIn('enrollment_id', $ids)
                    ->filter(fn ($row) => $row->status && $row->attendanceDay?->attendance_date)
                    ->groupBy(fn ($row) => $row->attendanceDay->attendance_date->format('Y-m-d'));
                // Count each date once across group changes; a present record takes precedence.
                $days = $recordedDays?->filter(fn ($records) => $records->contains(fn ($row) => $row->status->is_present))->count();
                $absences = $recordedDays !== null ? $recordedDays->count() - $days : null;
                // Finishing a course disables awards; its original setting is retained by the lifecycle service.
                $awardsPoints = $course->finished_at ? $course->course_finished_was_awarding_points : $course->awards_points;
                $netPoints = $points !== null && $awardsPoints
                    ? (int) $points->whereIn('enrollment_id', $ids)->whereNull('voided_at')->sum('points') : null;
                $summary = collect([
                    'points' => $netPoints, 'days' => $days, 'absent_days' => $absences, 'pages' => $pages,
                ])->filter(fn ($value) => $value !== null)->map(fn ($value, $key) => [
                    'label' => __('student_timeline.'.$key), 'value' => number_format($value),
                ])->values()->all();
                $milestones = $milestones->sortBy('date')->values()->prepend([
                    'date' => $course->starts_on?->format('Y-m-d'), 'kind' => 'start',
                    'title' => __('student_timeline.course_start'),
                    'detail' => $course->starts_on ? $course->name : __('student_timeline.start_unknown'),
                    'stats' => $this->detailRows([
                        'course' => $course->name,
                        'duration' => $course->starts_on && $course->ends_on && $course->ends_on->gte($course->starts_on)
                            ? __('counts.days', ['count' => (int) $course->starts_on->diffInDays($course->ends_on) + 1]) : null,
                    ]),
                ]);
                if ($complete) {
                    $milestones->push([
                        'date' => $completionDate?->format('Y-m-d'),
                        'kind' => 'completed',
                        'title' => __('student_timeline.course_completed'),
                        'detail' => '', 'stats' => $summary,
                        'highlight' => $netPoints !== null ? __('student_timeline.point_count', ['count' => number_format($netPoints)])
                            : ($pages !== null ? __('student_timeline.page_count', ['count' => $pages]) : ''),
                    ]);
                }

                return [
                    'id' => $course->id, 'name' => $course->name,
                    'start' => $course->starts_on?->format('Y-m-d'), 'end' => $course->ends_on?->format('Y-m-d'),
                    'complete' => $complete,
                    'latest_enrollment' => $rows->pluck('enrolled_at')->filter()->sortDesc()->first()?->format('Y-m-d'),
                    'latest_enrollment_id' => (int) $rows->max('id'),
                    'sort_date' => $course->starts_on?->format('Y-m-d') ?? $rows->pluck('enrolled_at')->filter()->sortDesc()->first()?->format('Y-m-d'),
                    'points' => $netPoints, 'days' => $days, 'pages' => $pages, 'absences' => $absences,
                    'milestones' => $milestones,
                ];
            })->sortBy([['sort_date', 'desc'], ['latest_enrollment', 'desc'], ['id', 'desc']])->values();
    }

    public function defaultIndex(Collection $timeline, ?int $currentCourseId): int
    {
        $current = $currentCourseId ? $timeline->search(fn ($course) => (int) $course['id'] === $currentCourseId) : false;
        if ($current !== false) {
            return $current;
        }
        $latest = $timeline->sortBy([['latest_enrollment', 'desc'], ['latest_enrollment_id', 'desc']])->first();

        return $latest ? (int) $timeline->search(fn ($course) => $course['id'] === $latest['id']) : 0;
    }

    private function sessionPages($session): Collection
    {
        $pages = $session->pages->pluck('page_no')->unique()->sort()->values();

        return $pages->isNotEmpty() || ! $session->from_page || ! $session->to_page
            ? $pages : collect(range(min($session->from_page, $session->to_page), max($session->from_page, $session->to_page)));
    }

    private function detailRows(array $values): array
    {
        return collect($values)->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => ['label' => __('student_timeline.fields.'.$key), 'value' => (string) $value])
            ->values()->all();
    }

    private function testStats($test, $attempt, ?int $attempts = null): array
    {
        return $this->detailRows([
            'juz' => $test->juz?->juz_number,
            'result' => in_array($attempt->status, ['passed', 'failed', 'pending'], true) ? __('workflow.common.result_status.'.$attempt->status) : null,
            'score' => $attempt->score === null ? null : (string) (float) $attempt->score,
            'attempts' => $attempts,
        ]);
    }

    private function testDetail($test, $attempt): string
    {
        return __('workflow.common.labels.juz_number', ['number' => $test->juz?->juz_number ?? '—'])
            .' · '.__('workflow.common.result_status.'.$attempt->status)
            .($attempt->score !== null ? ' · '.__('student_timeline.score', ['score' => (float) $attempt->score]) : '');
    }
}
