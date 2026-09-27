<?php

namespace Tests\Unit;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Services\StudentTimelineService;
use Tests\TestCase;

class StudentTimelineServiceTest extends TestCase
{
    public function test_course_totals_merge_groups_and_keep_filtered_historical_points(): void
    {
        $course = new Course(['name' => 'Finished course', 'starts_on' => '2026-01-01', 'finished_at' => '2026-06-01', 'awards_points' => false, 'course_finished_was_awarding_points' => true]);
        $course->id = 1;
        $group = (new Group(['name' => 'First group']))->setRelation('course', $course)
            ->setRelation('teacher', new Teacher(['first_name' => 'Test', 'last_name' => 'Teacher']));
        $first = (new Enrollment(['status' => 'completed', 'enrolled_at' => '2026-01-01']))->setRelation('group', $group);
        $first->id = 10;
        $second = (new Enrollment(['status' => 'completed', 'enrolled_at' => '2026-02-01']))->setRelation('group', $group);
        $second->id = 11;
        $sessions = collect([
            (new MemorizationSession(['enrollment_id' => 10, 'from_page' => 1, 'to_page' => 9]))->setRelation('pages', collect([1, 2, 5])->map(fn ($p) => new MemorizationSessionPage(['page_no' => $p]))),
            (new MemorizationSession(['enrollment_id' => 11, 'from_page' => 5, 'to_page' => 6]))->setRelation('pages', collect()),
            (new MemorizationSession(['enrollment_id' => 99, 'from_page' => 10, 'to_page' => 50]))->setRelation('pages', collect()),
        ]);
        $points = collect([
            new PointTransaction(['enrollment_id' => 10, 'points' => 1000]),
            new PointTransaction(['enrollment_id' => 10, 'points' => -500, 'source_type' => 'course_completion_rule']),
            new PointTransaction(['enrollment_id' => 10, 'points' => 700, 'voided_at' => now()]),
            new PointTransaction(['enrollment_id' => 11, 'points' => 20]),
            new PointTransaction(['enrollment_id' => 99, 'points' => 9000]),
        ]);
        $attendance = collect([[10, true], [11, true], [10, false]])->map(fn ($r) => (new StudentAttendanceRecord(['enrollment_id' => $r[0]]))
            ->setRelation('status', new AttendanceStatus(['is_present' => $r[1]]))
            ->setRelation('attendanceDay', new GroupAttendanceDay(['attendance_date' => '2026-02-01'])));
        $timeline = app(StudentTimelineService::class)->build(collect([$first, $second]), $sessions, $attendance, collect(), collect(), collect(), $points);
        $this->assertCount(1, $timeline);
        $this->assertSame(520, $timeline[0]['points']);
        $this->assertSame(4, $timeline[0]['pages']);
        $this->assertSame(1, $timeline[0]['days']);
        $this->assertTrue($timeline[0]['complete']);
        $this->assertCount(4, $timeline[0]['milestones']);
        $this->assertNotContains('attendance', $timeline[0]['milestones']->pluck('kind'));
        $this->assertSame(0, $timeline[0]['absences']);
        $this->assertSame('start', $timeline[0]['milestones']->first()['kind']);
        $this->assertSame('520', $timeline[0]['milestones']->last()['stats'][0]['value']);
        $this->assertStringContainsString('Test Teacher', $timeline[0]['milestones'][1]['detail']);
        $this->assertSame('completed', $timeline[0]['milestones']->last()['kind']);
        $this->assertSame('2026-06-01', $timeline[0]['milestones']->last()['date']);

        $course->course_finished_was_awarding_points = false;
        $hidden = app(StudentTimelineService::class)->build(collect([$first]), null, null, collect(), collect(), collect(), $points)->first();
        $this->assertNull($hidden['points']);
        $this->assertNull($hidden['days']);
        $this->assertNull($hidden['absences']);
        $this->assertNull($hidden['pages']);
    }

    public function test_courses_are_separate_and_show_latest_saber_outcomes_and_final_exam(): void
    {
        app()->setLocale('en');
        $enrollments = collect([1, 2])->map(function ($id) {
            $course = new Course(['name' => 'Course '.$id, 'starts_on' => '2026-0'.$id.'-01']);
            $course->id = $id;
            $enrollment = new Enrollment(['enrolled_at' => '2026-0'.$id.'-02', 'status' => 'active']);
            $enrollment->id = $id;

            return $enrollment->setRelation('group', (new Group(['name' => 'Group '.$id]))->setRelation('course', $course));
        });
        $juz = new QuranJuz(['juz_number' => 30]);
        $test = (new QuranFinalTest(['enrollment_id' => 2]))->setRelation('juz', $juz)
            ->setRelation('attempts', collect([
                new QuranFinalTestAttempt(['tested_on' => '2026-02-10', 'status' => 'failed', 'score' => 40]),
                new QuranFinalTestAttempt(['tested_on' => '2026-02-12', 'status' => 'passed', 'score' => 90]),
            ]));
        $awqaf = (new QuranTest(['enrollment_id' => 2, 'tested_on' => '2026-02-14', 'status' => 'passed', 'score' => 95]))->setRelation('juz', $juz);
        $exam = (new AssessmentResult(['enrollment_id' => 2, 'score' => 85, 'status' => 'passed']))
            ->setRelation('assessment', new Assessment(['title' => 'Final exam', 'scheduled_at' => '2026-02-15']));
        $timeline = app(StudentTimelineService::class)->build($enrollments, null, null, collect([$test]), collect([$awqaf]), collect([$exam]), null);
        $this->assertSame([2, 1], $timeline->pluck('id')->all());
        $events = $timeline[0]['milestones'];
        $this->assertSame(['start', 'joined', 'final', 'awqaf', 'exam'], $events->pluck('kind')->all());
        $this->assertSame('2026-02-12', $events[2]['date']);
        $this->assertStringContainsString('90', $events[2]['detail']);
        $this->assertSame('2026-02-15', $events[4]['date']);
        $this->assertStringContainsString(__('workflow.common.result_status.passed'), $events[4]['detail']);
        $this->assertSame(['30', __('workflow.common.result_status.passed'), '90', '2'], array_column($events[2]['stats'], 'value'));
        $this->assertSame(['30', __('workflow.common.result_status.passed'), '95'], array_column($events[3]['stats'], 'value'));
        $this->assertSame(['Final exam', __('workflow.common.result_status.passed'), '85'], array_column($events[4]['stats'], 'value'));
        $this->assertCount(2, $timeline[1]['milestones']);
        $this->assertNotContains('completed', $timeline[0]['milestones']->pluck('kind'));
        $this->assertNotContains('progress', $timeline[0]['milestones']->pluck('kind'));
    }

    public function test_tooltip_rows_include_course_duration_group_teacher_and_actual_memorised_pages(): void
    {
        app()->setLocale('ar');
        $course = new Course(['name' => 'دورة الاختبار', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-03']);
        $course->id = 1;
        $teacher = new Teacher(['first_name' => 'أحمد', 'last_name' => 'محمد']);
        $group = (new Group(['name' => 'حلقة الاختبار']))->setRelation('course', $course)->setRelation('teacher', $teacher);
        $enrollment = (new Enrollment(['status' => 'active', 'enrolled_at' => '2026-01-01']))->setRelation('group', $group);
        $enrollment->id = 10;
        $session = (new MemorizationSession(['enrollment_id' => 10, 'recorded_on' => '2026-01-02']))
            ->setRelation('teacher', $teacher)
            ->setRelation('pages', collect([3, 1, 3])->map(fn ($page) => new MemorizationSessionPage(['page_no' => $page])));
        $events = app(StudentTimelineService::class)->build(collect([$enrollment]), collect([$session]), null, collect(), collect(), collect(), null)->first()['milestones'];
        $this->assertSame(['دورة الاختبار', '3 أيام'], array_column($events[0]['stats'], 'value'));
        $this->assertSame(['حلقة الاختبار', 'أحمد محمد'], array_column($events[1]['stats'], 'value'));
        $this->assertSame(['صفحتين', '1، 3', 'أحمد محمد'], array_column($events[2]['stats'], 'value'));
        $this->assertSame(['start', 'joined', 'memorization'], $events->pluck('kind')->all());
    }

    public function test_default_course_prefers_current_course_then_latest_enrollment_without_changing_date_order(): void
    {
        $enrollments = collect([
            [1, '2026-01-01', '2026-09-20'],
            [2, '2026-09-01', '2026-09-02'],
            [3, null, '2026-08-01'],
        ])->map(function ($data) {
            [$id, $start, $joined] = $data;
            $course = new Course(['name' => 'Course '.$id, 'starts_on' => $start]);
            $course->id = $id;
            $enrollment = new Enrollment(['enrolled_at' => $joined, 'status' => 'active']);
            $enrollment->id = $id;

            return $enrollment->setRelation('group', (new Group(['name' => 'Group '.$id]))->setRelation('course', $course));
        });
        $service = app(StudentTimelineService::class);
        $timeline = $service->build($enrollments, null, null, collect(), collect(), collect(), null);
        $this->assertSame([2, 3, 1], $timeline->pluck('id')->all());
        $this->assertSame(0, $service->defaultIndex($timeline, 2));
        $this->assertSame(1, $service->defaultIndex($timeline, 3));
        $this->assertSame(2, $service->defaultIndex($timeline, null));
        $this->assertSame(2, $service->defaultIndex($timeline, 99));
        $this->assertSame(0, $service->defaultIndex(collect(), null));
        $this->assertSame('Group 2', $timeline[0]['milestones'][1]['highlight']);
    }
}
