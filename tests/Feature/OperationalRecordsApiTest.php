<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalRecordsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_manager_can_load_operational_lists_details_and_toggle_student_attendance_status(): void
    {
        $context = $this->createContext();
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);
        $records = $this->createOperationalRecords($context, $manager);

        $this->getJson('/api/v1/student-attendance/days')
            ->assertOk()->assertJsonPath('data.0.id', $records['studentDay']->id);
        $this->getJson('/api/v1/student-attendance/days/'.$records['studentDay']->id)
            ->assertOk()->assertJsonPath('data.groups.0.records.0.student.id', $context['student']->id);
        $this->patchJson('/api/v1/student-attendance/days/'.$records['studentDay']->id.'/status', ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame('closed', $records['groupDay']->fresh()->status);

        $this->getJson('/api/v1/teacher-attendance/days')
            ->assertOk()->assertJsonPath('data.0.id', $records['teacherDay']->id);
        $this->getJson('/api/v1/teacher-attendance/days/'.$records['teacherDay']->id)
            ->assertOk()->assertJsonPath('data.records.0.teacher.id', $context['teacher']->id);

        $this->getJson('/api/v1/memorization')->assertOk()->assertJsonPath('data.0.id', $records['memorization']->id);
        $this->getJson('/api/v1/enrollments/'.$context['enrollment']->id.'/memorization')
            ->assertOk()->assertJsonPath('data.0.enrollment_id', $context['enrollment']->id);
        $this->getJson('/api/v1/quran-tests')->assertOk()->assertJsonPath('data.0.id', $records['quranTest']->id);
        $this->getJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-tests')
            ->assertOk()->assertJsonPath('data.0.juz.id', $records['juz']->id);
        $this->getJson('/api/v1/points')->assertOk()->assertJsonPath('data.0.id', $records['point']->id);
        $this->getJson('/api/v1/enrollments/'.$context['enrollment']->id.'/points')
            ->assertOk()->assertJsonPath('data.0.effective_state', 'active');
        $this->getJson('/api/v1/assessments/'.$records['assessment']->id)
            ->assertOk()->assertJsonPath('data.results.0.student_id', $context['student']->id);
    }

    public function test_teacher_operational_read_endpoints_hide_other_group_records(): void
    {
        $context = $this->createContext();
        $other = $this->createContext('Other', $context['course'], $context['year']);
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacherUser->givePermissionTo([
            'assessments.view',
            'attendance.student.toggle-day-status',
            'attendance.student.view',
            'attendance.teacher.view',
            'memorization.view',
            'points.view',
            'quran-tests.view',
        ]);
        $context['teacher']->user()->associate($teacherUser);
        $context['teacher']->save();

        $records = $this->createOperationalRecords($context, $teacherUser, $other);
        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/v1/student-attendance/days/'.$records['studentDay']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.group.id', $context['group']->id);

        $this->getJson('/api/v1/teacher-attendance/days/'.$records['teacherDay']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.records')
            ->assertJsonPath('data.records.0.teacher.id', $context['teacher']->id);

        $this->getJson('/api/v1/memorization')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/quran-tests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/points')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/enrollments/'.$other['enrollment']->id.'/memorization')->assertForbidden();
        $this->getJson('/api/v1/assessments/'.$records['otherAssessment']->id)->assertForbidden();
    }

    public function test_operational_reads_require_their_matching_permissions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach (['student-attendance/days', 'teacher-attendance/days', 'memorization', 'quran-tests', 'points'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden();
        }
    }

    protected function createContext(string $suffix = '', ?Course $course = null, ?AcademicYear $year = null): array
    {
        $year ??= AcademicYear::query()->where('is_current', true)->firstOrFail();
        $course ??= Course::query()->create([
            'academic_year_id' => $year->id,
            'awards_points' => true,
            'ends_on' => '2027-06-30',
            'is_active' => true,
            'is_default' => true,
            'name' => 'Operations Course',
            'starts_on' => '2026-09-01',
        ]);
        $parent = ParentProfile::query()->create(['father_name' => 'Parent '.$suffix]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Teacher',
            'last_name' => $suffix ?: 'Primary',
            'phone' => $suffix === '' ? '0991000000' : '0992000000',
            'status' => 'active',
        ]);
        $group = Group::query()->create([
            'academic_year_id' => $year->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'is_active' => true,
            'name' => 'Operations Group '.$suffix,
            'teacher_id' => $teacher->id,
        ]);
        $student = Student::query()->create([
            'birth_date' => '2014-01-01',
            'first_name' => 'Student',
            'last_name' => $suffix ?: 'Primary',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
        $enrollment = Enrollment::query()->create([
            'enrolled_at' => '2026-09-01',
            'group_id' => $group->id,
            'status' => 'active',
            'student_id' => $student->id,
        ]);

        return compact('course', 'enrollment', 'group', 'parent', 'student', 'teacher', 'year');
    }

    protected function createOperationalRecords(array $context, User $creator, ?array $other = null): array
    {
        $studentStatus = AttendanceStatus::query()->whereIn('scope', ['student', 'both'])->firstOrFail();
        $teacherStatus = AttendanceStatus::query()->whereIn('scope', ['teacher', 'both'])->firstOrFail();
        $studentDay = StudentAttendanceDay::query()->create([
            'attendance_date' => '2026-10-10',
            'course_id' => $context['course']->id,
            'created_by' => $creator->id,
            'status' => 'open',
        ]);
        $groupDay = GroupAttendanceDay::query()->create([
            'attendance_date' => '2026-10-10',
            'created_by' => $creator->id,
            'group_id' => $context['group']->id,
            'status' => 'open',
            'student_attendance_day_id' => $studentDay->id,
        ]);
        StudentAttendanceRecord::query()->create([
            'attendance_status_id' => $studentStatus->id,
            'enrollment_id' => $context['enrollment']->id,
            'group_attendance_day_id' => $groupDay->id,
        ]);

        $teacherDay = TeacherAttendanceDay::query()->create([
            'attendance_date' => '2026-10-10',
            'course_id' => $context['course']->id,
            'created_by' => $creator->id,
            'status' => 'completed',
        ]);
        TeacherAttendanceRecord::query()->create([
            'attendance_status_id' => $teacherStatus->id,
            'teacher_attendance_day_id' => $teacherDay->id,
            'teacher_id' => $context['teacher']->id,
        ]);

        $memorization = MemorizationSession::query()->create([
            'enrollment_id' => $context['enrollment']->id,
            'entry_type' => 'new',
            'from_page' => 1,
            'pages_count' => 2,
            'recorded_by_user_id' => $creator->id,
            'recorded_on' => '2026-10-10',
            'student_id' => $context['student']->id,
            'teacher_id' => $context['teacher']->id,
            'to_page' => 2,
        ]);
        $juz = QuranJuz::query()->orderBy('juz_number')->firstOrFail();
        $quranType = QuranTestType::query()->where('code', 'awqaf')->firstOrFail();
        $quranTest = QuranTest::query()->create([
            'attempt_no' => 1,
            'enrollment_id' => $context['enrollment']->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $quranType->id,
            'status' => 'passed',
            'student_id' => $context['student']->id,
            'teacher_id' => $context['teacher']->id,
            'tested_on' => '2026-10-10',
        ]);
        $pointType = PointType::query()->where('is_active', true)->firstOrFail();
        $point = PointTransaction::query()->create([
            'entered_at' => '2026-10-10 10:00:00',
            'entered_by' => $creator->id,
            'enrollment_id' => $context['enrollment']->id,
            'point_type_id' => $pointType->id,
            'points' => 5,
            'source_id' => $memorization->id,
            'source_type' => 'memorization_session',
            'student_id' => $context['student']->id,
        ]);
        $assessmentType = AssessmentType::query()->where('is_active', true)->firstOrFail();
        $assessment = Assessment::query()->create([
            'assessment_type_id' => $assessmentType->id,
            'created_by' => $creator->id,
            'group_id' => $context['group']->id,
            'group_scope' => 'single',
            'is_active' => true,
            'title' => 'Mobile Assessment',
            'total_mark' => 100,
        ]);
        AssessmentResult::query()->create([
            'assessment_id' => $assessment->id,
            'attempt_no' => 1,
            'enrollment_id' => $context['enrollment']->id,
            'score' => 80,
            'status' => 'passed',
            'student_id' => $context['student']->id,
            'teacher_id' => $context['teacher']->id,
        ]);

        $otherAssessment = $assessment;
        if ($other) {
            $otherGroupDay = GroupAttendanceDay::query()->create([
                'attendance_date' => '2026-10-10',
                'created_by' => $creator->id,
                'group_id' => $other['group']->id,
                'status' => 'open',
                'student_attendance_day_id' => $studentDay->id,
            ]);
            StudentAttendanceRecord::query()->create([
                'attendance_status_id' => $studentStatus->id,
                'enrollment_id' => $other['enrollment']->id,
                'group_attendance_day_id' => $otherGroupDay->id,
            ]);
            TeacherAttendanceRecord::query()->create([
                'attendance_status_id' => $teacherStatus->id,
                'teacher_attendance_day_id' => $teacherDay->id,
                'teacher_id' => $other['teacher']->id,
            ]);
            MemorizationSession::query()->create([
                'enrollment_id' => $other['enrollment']->id,
                'entry_type' => 'review',
                'from_page' => 3,
                'pages_count' => 1,
                'recorded_by_user_id' => $creator->id,
                'recorded_on' => '2026-10-10',
                'student_id' => $other['student']->id,
                'teacher_id' => $other['teacher']->id,
                'to_page' => 3,
            ]);
            QuranTest::query()->create([
                'attempt_no' => 1,
                'enrollment_id' => $other['enrollment']->id,
                'juz_id' => $juz->id,
                'quran_test_type_id' => $quranType->id,
                'status' => 'passed',
                'student_id' => $other['student']->id,
                'teacher_id' => $other['teacher']->id,
                'tested_on' => '2026-10-10',
            ]);
            PointTransaction::query()->create([
                'entered_at' => '2026-10-10 11:00:00',
                'entered_by' => $creator->id,
                'enrollment_id' => $other['enrollment']->id,
                'point_type_id' => $pointType->id,
                'points' => 3,
                'source_id' => 999,
                'source_type' => 'manual',
                'student_id' => $other['student']->id,
            ]);
            $otherAssessment = Assessment::query()->create([
                'assessment_type_id' => $assessmentType->id,
                'created_by' => $creator->id,
                'group_id' => $other['group']->id,
                'group_scope' => 'single',
                'is_active' => true,
                'title' => 'Other Assessment',
                'total_mark' => 100,
            ]);
        }

        return compact('assessment', 'groupDay', 'juz', 'memorization', 'otherAssessment', 'point', 'quranTest', 'studentDay', 'teacherDay');
    }
}
