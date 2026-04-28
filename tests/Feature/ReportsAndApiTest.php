<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestAttempt;
use App\Models\QuranPartialTestPart;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportsAndApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'en']);
    }

    public function test_reports_page_and_api_require_authentication(): void
    {
        $this->get(route('reports.index', absolute: false))->assertRedirect('/login');
        $this->getJson('/api/v1/reports/overview')->assertUnauthorized();
        $this->getJson('/api/v1/reports/teachers/daily-summary')->assertUnauthorized();
    }

    public function test_manager_can_view_reports_page_and_api_data(): void
    {
        [$manager, $group] = $this->reportingContext();

        $this->actingAs($manager)
            ->get(route('reports.index', absolute: false))
            ->assertOk()
            ->assertSee('Reports')
            ->assertDontSee('Students in scope');

        Sanctum::actingAs($manager);

        $overview = $this->getJson('/api/v1/reports/overview?group_id='.$group->id.'&date_from=2026-09-01&date_to=2026-09-30');
        $overview->assertOk();
        $overview->assertJsonPath('headline.students_in_scope', 1);
        $overview->assertJsonPath('headline.active_enrollments', 1);
        $overview->assertJsonPath('headline.memorized_pages', 5);
        $overview->assertJsonPath('headline.net_points', 4);
        $overview->assertJsonPath('headline.invoiced_amount', 30);
        $overview->assertJsonPath('headline.cash_collected', 30);
        $overview->assertJsonPath('attendance.days_recorded', 1);
        $overview->assertJsonPath('assessments.results_recorded', 1);
        $overview->assertJsonPath('assessments.passed', 1);
        $overview->assertJsonPath('assessments.failed', 0);
        $overview->assertJsonPath('assessments.average_score', 85);
        $overview->assertJsonPath('finance.invoice_billed', 30);
        $overview->assertJsonPath('finance.invoice_collected', 10);
        $overview->assertJsonPath('finance.activity_expected', 20);
        $overview->assertJsonPath('finance.activity_collected', 20);
        $overview->assertJsonPath('finance.activity_expenses', 5);
        $overview->assertJsonPath('finance.activity_net', 15);
        $overview->assertJsonPath('outstanding_invoices.0.balance', 20);

        $students = $this->getJson('/api/v1/students?group_id='.$group->id);
        $students->assertOk();
        $students->assertJsonPath('data.0.first_name', 'Report');
        $students->assertJsonPath('data.0.enrollments_count', 1);

        $groups = $this->getJson('/api/v1/groups?academic_year_id='.$group->academic_year_id);
        $groups->assertOk();
        $groups->assertJsonPath('data.0.name', 'Report Group');

        $enrollments = $this->getJson('/api/v1/enrollments?group_id='.$group->id);
        $enrollments->assertOk();
        $enrollments->assertJsonPath('data.0.student.full_name', 'Report Student');
        $enrollments->assertJsonPath('data.0.final_points', 4);

        $assessments = $this->getJson('/api/v1/assessments?group_id='.$group->id);
        $assessments->assertOk();
        $assessments->assertJsonPath('data.0.title', 'Monthly Quiz');
        $assessments->assertJsonPath('data.0.results_count', 1);

        $activities = $this->getJson('/api/v1/activities?group_id='.$group->id);
        $activities->assertOk();
        $activities->assertJsonPath('data.0.title', 'Field Trip');
        $activities->assertJsonPath('data.0.expected_revenue', 20);

        $invoices = $this->getJson('/api/v1/invoices');
        $invoices->assertOk();
        $invoices->assertJsonPath('data.0.invoice_no', 'INV-REPORT-0001');
        $invoices->assertJsonPath('data.0.balance', 20);
    }

    public function test_report_filters_tolerate_array_values_from_enhanced_selects(): void
    {
        [$manager, $group] = $this->reportingContext();
        $assessmentTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        $this->actingAs($manager);

        Volt::test('reports.index')
            ->set('group_id', [(string) $group->id])
            ->set('assessment_type_id', [(string) $assessmentTypeId])
            ->assertHasNoErrors();
    }

    public function test_teacher_api_read_endpoints_are_scoped(): void
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'name' => 'Teacher Api User',
            'username' => 'teacher-api-user',
            'phone' => '0777000400',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Teacher',
            'last_name' => 'Api',
            'phone' => '0991000400',
            'status' => 'active',
        ]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Api',
            'phone' => '0991000401',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Teacher API Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');
        $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        $assignedGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Teacher API Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other API Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $assignedParent = ParentProfile::create([
            'father_name' => 'Assigned API Parent',
            'father_phone' => '0944000501',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other API Parent',
            'father_phone' => '0944000502',
        ]);

        $assignedStudent = Student::create([
            'parent_id' => $assignedParent->id,
            'first_name' => 'Assigned',
            'last_name' => 'API Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'API Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $assignedStudent->id,
            'group_id' => $assignedGroup->id,
            'enrolled_at' => now()->toDateString(),
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => now()->toDateString(),
            'status' => 'active',
        ]);

        Assessment::create([
            'group_id' => $assignedGroup->id,
            'assessment_type_id' => $quizTypeId,
            'title' => 'Assigned API Quiz',
            'is_active' => true,
        ]);

        Assessment::create([
            'group_id' => $otherGroup->id,
            'assessment_type_id' => $quizTypeId,
            'title' => 'Other API Quiz',
            'is_active' => true,
        ]);

        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Assigned')
            ->assertJsonPath('data.0.last_name', 'API Student');

        $this->getJson('/api/v1/assessments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Assigned API Quiz');
    }

    public function test_manager_can_fetch_teacher_daily_summary_api(): void
    {
        [$manager, $group] = $this->reportingContext();

        $teacher = $group->teacher()->firstOrFail();
        $attendanceDay = GroupAttendanceDay::query()->where('group_id', $group->id)->firstOrFail();
        $juz = QuranJuz::query()->firstOrFail();
        $absentStatus = AttendanceStatus::query()
            ->where('scope', '!=', 'teacher')
            ->where('is_present', false)
            ->firstOrFail();

        $parent = ParentProfile::create([
            'father_name' => 'Absent Parent',
            'father_phone' => '0944000411',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Absent',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $attendanceDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $absentStatus->id,
        ]);

        $partialTest = QuranPartialTest::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'created_by' => $manager->id,
        ]);

        $partialPart = QuranPartialTestPart::create([
            'quran_partial_test_id' => $partialTest->id,
            'part_number' => 1,
            'status' => 'pending',
        ]);

        QuranPartialTestAttempt::create([
            'quran_partial_test_part_id' => $partialPart->id,
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-10',
            'mistake_count' => 7,
            'status' => 'failed',
            'attempt_no' => 1,
        ]);

        $finalTest = QuranFinalTest::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'created_by' => $manager->id,
        ]);

        QuranFinalTestAttempt::create([
            'quran_final_test_id' => $finalTest->id,
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-10',
            'score' => 50,
            'status' => 'failed',
            'attempt_no' => 1,
        ]);

        Sanctum::actingAs($manager);

        $summary = $this->getJson('/api/v1/reports/teachers/daily-summary?date=2026-09-10');

        $summary->assertOk();
        $summary->assertJsonPath('date', '2026-09-10');
        $summary->assertJsonPath('teachers_in_scope', 1);
        $summary->assertJsonPath('teachers_with_activity', 1);
        $summary->assertJsonPath('totals.absences_count', 1);
        $summary->assertJsonPath('totals.memorization_sessions_count', 1);
        $summary->assertJsonPath('totals.memorized_pages', 5);
        $summary->assertJsonPath('totals.failed_partial_attempts_count', 1);
        $summary->assertJsonPath('totals.failed_final_attempts_count', 1);
        $summary->assertJsonPath('teachers.0.teacher.name', 'Report Teacher');
        $summary->assertJsonPath('teachers.0.absences_count', 1);
        $summary->assertJsonPath('teachers.0.absences.0.student_name', 'Absent Student');
        $summary->assertJsonPath('teachers.0.memorization_sessions_count', 1);
        $summary->assertJsonPath('teachers.0.memorized_pages', 5);
        $summary->assertJsonPath('teachers.0.failed_partial_attempts_count', 1);
        $summary->assertJsonPath('teachers.0.failed_partial_attempts.0.part_number', 1);
        $summary->assertJsonPath('teachers.0.failed_partial_attempts.0.juz_number', $juz->juz_number);
        $summary->assertJsonPath('teachers.0.failed_final_attempts_count', 1);
        $summary->assertJsonPath('teachers.0.failed_final_attempts.0.score', 50);
    }

    private function reportingContext(): array
    {
        $this->seed();

        $manager = User::factory()->create([
            'name' => 'Reports Manager',
            'username' => 'reports-manager',
            'phone' => '0666000400',
        ]);
        $manager->assignRole('manager');

        $parent = ParentProfile::create([
            'father_name' => 'Report Parent',
            'father_phone' => '0944000400',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Report',
            'last_name' => 'Teacher',
            'phone' => '0944000401',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Reporting Course',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Report',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Report Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 4,
            'memorized_pages_cached' => 5,
        ]);

        $presentStatus = AttendanceStatus::query()
            ->where('code', 'present')
            ->firstOrFail();

        $attendanceDay = GroupAttendanceDay::create([
            'group_id' => $group->id,
            'attendance_date' => '2026-09-10',
            'status' => 'closed',
            'created_by' => $manager->id,
        ]);

        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $attendanceDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $presentStatus->id,
        ]);

        \App\Models\MemorizationSession::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'from_page' => 1,
            'to_page' => 5,
            'pages_count' => 5,
        ]);

        $pointTypeId = PointType::query()->value('id');

        PointTransaction::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'point_type_id' => $pointTypeId,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $manager->id,
            'entered_at' => '2026-09-10 09:00:00',
        ]);

        $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        $assessment = Assessment::create([
            'group_id' => $group->id,
            'assessment_type_id' => $quizTypeId,
            'title' => 'Monthly Quiz',
            'scheduled_at' => '2026-09-11 10:00:00',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
            'created_by' => $manager->id,
        ]);

        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'score' => 85,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $paymentMethodId = PaymentMethod::query()->value('id');
        $expenseCategoryId = ExpenseCategory::query()->value('id');

        $activity = Activity::create([
            'title' => 'Field Trip',
            'activity_date' => '2026-09-12',
            'group_id' => $group->id,
            'fee_amount' => 20,
            'expected_revenue_cached' => 20,
            'collected_revenue_cached' => 20,
            'expense_total_cached' => 5,
            'is_active' => true,
        ]);

        $registration = ActivityRegistration::create([
            'activity_id' => $activity->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'fee_amount' => 20,
            'status' => 'registered',
        ]);

        ActivityPayment::create([
            'activity_registration_id' => $registration->id,
            'payment_method_id' => $paymentMethodId,
            'paid_at' => '2026-09-12',
            'amount' => 20,
            'entered_by' => $manager->id,
        ]);

        ActivityExpense::create([
            'activity_id' => $activity->id,
            'expense_category_id' => $expenseCategoryId,
            'amount' => 5,
            'spent_on' => '2026-09-12',
            'description' => 'Transport',
            'entered_by' => $manager->id,
        ]);

        $invoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-REPORT-0001',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-13',
            'due_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 30,
            'discount' => 0,
            'total' => 30,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'description' => 'Monthly tuition',
            'quantity' => 1,
            'unit_price' => 30,
            'amount' => 30,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $paymentMethodId,
            'paid_at' => '2026-09-14',
            'amount' => 10,
            'received_by' => $manager->id,
        ]);

        return [$manager, $group];
    }
}
