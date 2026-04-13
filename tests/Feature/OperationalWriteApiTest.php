<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Assessment;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\PaymentMethod;
use App\Models\PointPolicy;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_manage_operational_and_finance_transactions_via_api(): void
    {
        $context = $this->managerApiContext();

        $studentAttendanceStatus = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->orderByDesc('is_present')
            ->firstOrFail();
        $teacherAttendanceStatus = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['teacher', 'both'])
            ->firstOrFail();
        $assessmentType = AssessmentType::query()->where('code', 'quiz')->firstOrFail();
        $expenseCategory = ExpenseCategory::query()->where('is_active', true)->firstOrFail();
        $finalType = QuranTestType::query()->where('code', 'final')->firstOrFail();
        $juz = QuranJuz::query()->orderBy('juz_number')->firstOrFail();
        $partialType = QuranTestType::query()->where('code', 'partial')->firstOrFail();
        $paymentMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();

        $studentAttendanceStatus->update(['default_points' => 5]);

        $memorizationPointType = PointType::query()->create([
            'allow_manual_entry' => false,
            'allow_negative' => false,
            'category' => 'memorization',
            'code' => 'api-memorization-bonus',
            'default_points' => 2,
            'is_active' => true,
            'name' => 'API Memorization Bonus',
        ]);
        PointPolicy::query()->create([
            'grade_level_id' => $context['gradeLevel']->id,
            'is_active' => true,
            'name' => 'API Memorization Per Page',
            'point_type_id' => $memorizationPointType->id,
            'points' => 2,
            'priority' => 10,
            'source_type' => 'memorization',
            'trigger_key' => 'page',
        ]);

        $manualPointType = PointType::query()->create([
            'allow_manual_entry' => true,
            'allow_negative' => true,
            'category' => 'manual',
            'code' => 'api-manual-adjustment',
            'default_points' => 0,
            'is_active' => true,
            'name' => 'API Manual Adjustment',
        ]);

        $assessmentPointType = PointType::query()->create([
            'allow_manual_entry' => false,
            'allow_negative' => false,
            'category' => 'assessment',
            'code' => 'api-assessment-bonus',
            'default_points' => 0,
            'is_active' => true,
            'name' => 'API Assessment Bonus',
        ]);
        AssessmentScoreBand::query()->create([
            'assessment_type_id' => $assessmentType->id,
            'from_mark' => 80,
            'is_active' => true,
            'is_fail' => false,
            'name' => 'Excellent',
            'point_type_id' => $assessmentPointType->id,
            'points' => 6,
            'to_mark' => 100,
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/groups/'.$context['group']->id.'/attendance', [
            'attendance_date' => '2026-10-05',
            'records' => [[
                'attendance_status_id' => $studentAttendanceStatus->id,
                'enrollment_id' => $context['enrollment']->id,
                'notes' => 'On time.',
            ]],
        ])->assertOk()
            ->assertJsonPath('records.0.enrollment_id', $context['enrollment']->id)
            ->assertJsonPath('records.0.attendance_status_id', $studentAttendanceStatus->id);

        $this->assertDatabaseHas('student_attendance_records', [
            'attendance_status_id' => $studentAttendanceStatus->id,
            'enrollment_id' => $context['enrollment']->id,
        ]);
        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $context['enrollment']->id,
            'points' => 5,
            'source_type' => 'student_attendance_record',
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/teacher-attendance', [
            'attendance_date' => '2026-10-05',
            'records' => [[
                'attendance_status_id' => $teacherAttendanceStatus->id,
                'teacher_id' => $context['teacher']->id,
            ]],
        ])->assertOk()
            ->assertJsonPath('records.0.teacher_id', $context['teacher']->id);

        $this->assertDatabaseHas('teacher_attendance_records', [
            'attendance_status_id' => $teacherAttendanceStatus->id,
            'teacher_id' => $context['teacher']->id,
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/memorization', [
            'entry_type' => 'new',
            'from_page' => 1,
            'recorded_on' => '2026-10-06',
            'teacher_id' => $context['teacher']->id,
            'to_page' => 3,
        ])->assertCreated()
            ->assertJsonPath('new_pages_count', 3)
            ->assertJsonPath('pages_count', 3);

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $context['enrollment']->id,
            'from_page' => 1,
            'to_page' => 3,
        ]);
        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $context['enrollment']->id,
            'point_type_id' => $memorizationPointType->id,
            'points' => 6,
            'source_type' => 'memorization_session',
        ]);

        $manualPointResponse = $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/points/manual', [
            'notes' => 'Penalty correction',
            'point_type_id' => $manualPointType->id,
            'points' => -3,
        ]);

        $manualPointResponse->assertCreated()
            ->assertJsonPath('points', -3);

        $manualTransactionId = $manualPointResponse->json('id');

        $this->withToken($context['token'])->postJson('/api/v1/points/'.$manualTransactionId.'/void')
            ->assertOk()
            ->assertJsonPath('id', $manualTransactionId)
            ->assertJsonPath('voided_by', $context['manager']->id);

        $this->assertDatabaseHas('point_transactions', [
            'id' => $manualTransactionId,
            'voided_by' => $context['manager']->id,
        ]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-tests', [
                'juz_id' => $juz->id,
                'quran_test_type_id' => $partialType->id,
                'status' => 'passed',
                'teacher_id' => $context['teacher']->id,
                'tested_on' => '2026-10-'.sprintf('%02d', 6 + $attempt),
            ])->assertCreated()
                ->assertJsonPath('attempt_no', $attempt);
        }

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-tests', [
            'juz_id' => $juz->id,
            'quran_test_type_id' => $finalType->id,
            'status' => 'passed',
            'teacher_id' => $context['teacher']->id,
            'tested_on' => '2026-10-11',
        ])->assertCreated()
            ->assertJsonPath('attempt_no', 1)
            ->assertJsonPath('quran_test_type_id', $finalType->id);

        $assessment = Assessment::query()->create([
            'assessment_type_id' => $assessmentType->id,
            'created_by' => $context['manager']->id,
            'group_id' => $context['group']->id,
            'is_active' => true,
            'pass_mark' => 50,
            'scheduled_at' => '2026-10-12 09:00:00',
            'title' => 'API Quiz',
            'total_mark' => 100,
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/assessments/'.$assessment->id.'/results', [
            'results' => [[
                'attempt_no' => 1,
                'enrollment_id' => $context['enrollment']->id,
                'score' => 91,
                'status' => 'passed',
            ]],
        ])->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.enrollment_id', $context['enrollment']->id)
            ->assertJsonPath('results.0.score', 91);

        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $assessment->id,
            'enrollment_id' => $context['enrollment']->id,
            'score' => '91.00',
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $context['enrollment']->id,
            'points' => 6,
            'source_type' => 'assessment_result',
        ]);

        $activity = Activity::query()->create([
            'activity_date' => '2026-10-15',
            'collected_revenue_cached' => 0,
            'description' => 'Integration trip',
            'expected_revenue_cached' => 0,
            'expense_total_cached' => 0,
            'fee_amount' => 50,
            'group_id' => $context['group']->id,
            'is_active' => true,
            'title' => 'API Trip',
        ]);

        $registrationResponse = $this->withToken($context['token'])->postJson('/api/v1/activities/'.$activity->id.'/registrations', [
            'enrollment_id' => $context['enrollment']->id,
            'fee_amount' => 50,
            'status' => 'registered',
            'student_id' => $context['student']->id,
        ]);
        $registrationResponse->assertCreated()
            ->assertJsonPath('student_id', $context['student']->id);
        $registrationId = $registrationResponse->json('id');

        $activity->refresh();
        $this->assertSame(50.0, (float) $activity->expected_revenue_cached);

        $activityPaymentResponse = $this->withToken($context['token'])->postJson('/api/v1/activities/'.$activity->id.'/payments', [
            'activity_registration_id' => $registrationId,
            'amount' => 20,
            'paid_at' => '2026-10-16',
            'payment_method_id' => $paymentMethod->id,
        ]);
        $activityPaymentResponse->assertCreated()
            ->assertJsonPath('amount', 20);
        $activityPaymentId = $activityPaymentResponse->json('id');

        $activity->refresh();
        $this->assertSame(20.0, (float) $activity->collected_revenue_cached);

        $this->withToken($context['token'])->postJson('/api/v1/activities/'.$activity->id.'/expenses', [
            'amount' => 7,
            'description' => 'Bus deposit',
            'expense_category_id' => $expenseCategory->id,
            'spent_on' => '2026-10-16',
        ])->assertCreated()
            ->assertJsonPath('amount', 7);

        $activity->refresh();
        $this->assertSame(7.0, (float) $activity->expense_total_cached);

        $this->withToken($context['token'])->deleteJson('/api/v1/activities/'.$activity->id.'/registrations/'.$registrationId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration cannot be deleted while active payments exist.');

        $this->withToken($context['token'])->postJson('/api/v1/activities/'.$activity->id.'/payments/'.$activityPaymentId.'/void')
            ->assertOk()
            ->assertJsonPath('id', $activityPaymentId);

        $this->withToken($context['token'])->deleteJson('/api/v1/activities/'.$activity->id.'/registrations/'.$registrationId)
            ->assertNoContent();

        $activity->refresh();
        $this->assertSame(0.0, (float) $activity->expected_revenue_cached);
        $this->assertSame(0.0, (float) $activity->collected_revenue_cached);

        $invoice = Invoice::query()->create([
            'discount' => 0,
            'due_date' => '2026-11-01',
            'invoice_no' => 'INV-API-000001',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-10-20',
            'notes' => 'API invoice',
            'parent_id' => $context['parent']->id,
            'status' => 'issued',
            'subtotal' => 0,
            'total' => 0,
        ]);

        $itemResponse = $this->withToken($context['token'])->postJson('/api/v1/invoices/'.$invoice->id.'/items', [
            'activity_id' => $activity->id,
            'description' => 'Monthly fee',
            'enrollment_id' => $context['enrollment']->id,
            'quantity' => 1,
            'student_id' => $context['student']->id,
            'unit_price' => 30,
        ]);
        $itemResponse->assertCreated()
            ->assertJsonPath('amount', 30)
            ->assertJsonPath('student_id', $context['student']->id);
        $itemId = $itemResponse->json('id');

        $invoice->refresh();
        $this->assertSame(30.0, (float) $invoice->subtotal);
        $this->assertSame(30.0, (float) $invoice->total);
        $this->assertSame('issued', $invoice->status);

        $invoicePaymentResponse = $this->withToken($context['token'])->postJson('/api/v1/invoices/'.$invoice->id.'/payments', [
            'amount' => 10,
            'paid_at' => '2026-10-21',
            'payment_method_id' => $paymentMethod->id,
        ]);
        $invoicePaymentResponse->assertCreated()
            ->assertJsonPath('amount', 10);
        $invoicePaymentId = $invoicePaymentResponse->json('id');

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);

        $this->withToken($context['token'])->postJson('/api/v1/invoices/'.$invoice->id.'/payments/'.$invoicePaymentId.'/void')
            ->assertOk()
            ->assertJsonPath('id', $invoicePaymentId);

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);

        $this->withToken($context['token'])->deleteJson('/api/v1/invoices/'.$invoice->id.'/items/'.$itemId)
            ->assertNoContent();

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->subtotal);
        $this->assertSame(0.0, (float) $invoice->total);
        $this->assertSame('issued', $invoice->status);

        $context['enrollment']->refresh();
        $this->assertSame(17, $context['enrollment']->final_points_cached);
        $this->assertSame(3, $context['enrollment']->memorized_pages_cached);
    }

    public function test_teacher_operational_api_is_scoped_and_enforces_memorization_and_progression_rules(): void
    {
        $context = $this->teacherApiContext();

        $studentAttendanceStatus = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->firstOrFail();
        $assessmentType = AssessmentType::query()->where('code', 'quiz')->firstOrFail();
        $finalType = QuranTestType::query()->where('code', 'final')->firstOrFail();
        $juz = QuranJuz::query()->orderBy('juz_number')->firstOrFail();
        $partialType = QuranTestType::query()->where('code', 'partial')->firstOrFail();

        $this->withToken($context['token'])->postJson('/api/v1/groups/'.$context['group']->id.'/attendance', [
            'attendance_date' => '2026-10-05',
            'records' => [[
                'attendance_status_id' => $studentAttendanceStatus->id,
                'enrollment_id' => $context['enrollment']->id,
            ]],
        ])->assertOk();

        $this->withToken($context['token'])->postJson('/api/v1/groups/'.$context['otherGroup']->id.'/attendance', [
            'attendance_date' => '2026-10-05',
            'records' => [[
                'attendance_status_id' => $studentAttendanceStatus->id,
                'enrollment_id' => $context['otherEnrollment']->id,
            ]],
        ])->assertForbidden();

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/memorization', [
            'entry_type' => 'new',
            'from_page' => 4,
            'recorded_on' => '2026-10-06',
            'teacher_id' => $context['teacher']->id,
            'to_page' => 5,
        ])->assertCreated()
            ->assertJsonPath('new_pages_count', 2);

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/memorization', [
            'entry_type' => 'new',
            'from_page' => 4,
            'recorded_on' => '2026-10-07',
            'teacher_id' => $context['teacher']->id,
            'to_page' => 5,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'One or more pages were already achieved by this student.');

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/memorization', [
            'entry_type' => 'new',
            'from_page' => 6,
            'recorded_on' => '2026-10-07',
            'teacher_id' => $context['otherTeacher']->id,
            'to_page' => 6,
        ])->assertForbidden();

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-tests', [
            'juz_id' => $juz->id,
            'quran_test_type_id' => $finalType->id,
            'status' => 'passed',
            'teacher_id' => $context['teacher']->id,
            'tested_on' => '2026-10-08',
        ])->assertStatus(422);

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-tests', [
            'juz_id' => $juz->id,
            'quran_test_type_id' => $partialType->id,
            'status' => 'passed',
            'teacher_id' => $context['teacher']->id,
            'tested_on' => '2026-10-08',
        ])->assertCreated()
            ->assertJsonPath('attempt_no', 1);

        $assessment = Assessment::query()->create([
            'assessment_type_id' => $assessmentType->id,
            'created_by' => $context['teacherUser']->id,
            'group_id' => $context['group']->id,
            'is_active' => true,
            'pass_mark' => 50,
            'scheduled_at' => '2026-10-09 09:00:00',
            'title' => 'Teacher Quiz',
            'total_mark' => 100,
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/assessments/'.$assessment->id.'/results', [
            'results' => [[
                'attempt_no' => 1,
                'enrollment_id' => $context['enrollment']->id,
                'score' => 75,
                'status' => 'passed',
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.enrollment_id', $context['enrollment']->id);

        $otherAssessment = Assessment::query()->create([
            'assessment_type_id' => $assessmentType->id,
            'created_by' => $context['teacherUser']->id,
            'group_id' => $context['otherGroup']->id,
            'is_active' => true,
            'pass_mark' => 50,
            'scheduled_at' => '2026-10-10 09:00:00',
            'title' => 'Forbidden Quiz',
            'total_mark' => 100,
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/assessments/'.$otherAssessment->id.'/results', [
            'results' => [[
                'attempt_no' => 1,
                'enrollment_id' => $context['otherEnrollment']->id,
                'score' => 70,
                'status' => 'passed',
            ]],
        ])->assertForbidden();

        $manualPointType = PointType::query()->create([
            'allow_manual_entry' => true,
            'allow_negative' => true,
            'category' => 'manual',
            'code' => 'teacher-blocked-manual',
            'default_points' => 0,
            'is_active' => true,
            'name' => 'Teacher Blocked Manual',
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/points/manual', [
            'point_type_id' => $manualPointType->id,
            'points' => 2,
        ])->assertForbidden();

        $activity = Activity::query()->create([
            'activity_date' => '2026-10-12',
            'collected_revenue_cached' => 0,
            'expected_revenue_cached' => 0,
            'expense_total_cached' => 0,
            'fee_amount' => 20,
            'group_id' => $context['group']->id,
            'is_active' => true,
            'title' => 'Teacher Blocked Activity',
        ]);

        $this->withToken($context['token'])->postJson('/api/v1/activities/'.$activity->id.'/registrations', [
            'fee_amount' => 20,
            'status' => 'registered',
            'student_id' => $context['student']->id,
        ])->assertForbidden();
    }

    private function issueToken(User $user, string $deviceName): string
    {
        $response = $this->postJson('/api/v1/auth/token', [
            'device_name' => $deviceName,
            'login' => $user->username,
            'password' => 'P@ssw0rd',
        ]);

        $response->assertCreated();

        return $response->json('token');
    }

    private function managerApiContext(): array
    {
        $this->seed();

        $manager = User::factory()->create([
            'name' => 'Operational Manager',
            'password' => 'P@ssw0rd',
            'phone' => '0666000600',
            'username' => 'operational-manager',
        ]);
        $manager->assignRole('manager');

        $parent = ParentProfile::query()->create([
            'father_name' => 'Manager Parent',
            'father_phone' => '0944000600',
        ]);

        $teacher = Teacher::query()->create([
            'first_name' => 'Main',
            'last_name' => 'Teacher',
            'phone' => '0944000601',
            'status' => 'active',
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $course = Course::query()->create([
            'is_active' => true,
            'name' => 'Operational Course',
        ]);
        $gradeLevel = \App\Models\GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'grade_level_id' => $gradeLevel->id,
            'is_active' => true,
            'monthly_fee' => 30,
            'name' => 'Operational Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ]);

        $student = Student::query()->create([
            'birth_date' => '2014-05-12',
            'first_name' => 'Operational',
            'grade_level_id' => $gradeLevel->id,
            'joined_at' => '2026-09-01',
            'last_name' => 'Student',
            'parent_id' => $parent->id,
            'school_name' => 'Alkhair School',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::query()->create([
            'enrolled_at' => '2026-09-01',
            'group_id' => $group->id,
            'memorized_pages_cached' => 0,
            'notes' => 'Operational enrollment',
            'status' => 'active',
            'student_id' => $student->id,
        ]);

        return [
            'academicYear' => $academicYear,
            'course' => $course,
            'enrollment' => $enrollment,
            'gradeLevel' => $gradeLevel,
            'group' => $group,
            'manager' => $manager,
            'parent' => $parent,
            'student' => $student,
            'teacher' => $teacher,
            'token' => $this->issueToken($manager, 'manager-operational-device'),
        ];
    }

    private function teacherApiContext(): array
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'name' => 'Scoped Teacher',
            'password' => 'P@ssw0rd',
            'phone' => '0666000601',
            'username' => 'scoped-teacher',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::query()->create([
            'first_name' => 'Scoped',
            'job_title' => 'Teacher',
            'last_name' => 'Teacher',
            'phone' => '0944000602',
            'status' => 'active',
            'user_id' => $teacherUser->id,
        ]);

        $otherTeacher = Teacher::query()->create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0944000603',
            'status' => 'active',
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Teacher Parent',
            'father_phone' => '0944000604',
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $course = Course::query()->create([
            'is_active' => true,
            'name' => 'Teacher Course',
        ]);
        $gradeLevel = \App\Models\GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 15,
            'course_id' => $course->id,
            'grade_level_id' => $gradeLevel->id,
            'is_active' => true,
            'monthly_fee' => 25,
            'name' => 'Teacher Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ]);

        $otherGroup = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 15,
            'course_id' => $course->id,
            'grade_level_id' => $gradeLevel->id,
            'is_active' => true,
            'monthly_fee' => 25,
            'name' => 'Other Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $otherTeacher->id,
        ]);

        $student = Student::query()->create([
            'birth_date' => '2015-02-10',
            'first_name' => 'Scoped',
            'grade_level_id' => $gradeLevel->id,
            'joined_at' => '2026-09-01',
            'last_name' => 'Student',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $otherStudent = Student::query()->create([
            'birth_date' => '2015-03-10',
            'first_name' => 'Other',
            'grade_level_id' => $gradeLevel->id,
            'joined_at' => '2026-09-01',
            'last_name' => 'Student',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $enrollment = Enrollment::query()->create([
            'enrolled_at' => '2026-09-01',
            'group_id' => $group->id,
            'status' => 'active',
            'student_id' => $student->id,
        ]);

        $otherEnrollment = Enrollment::query()->create([
            'enrolled_at' => '2026-09-01',
            'group_id' => $otherGroup->id,
            'status' => 'active',
            'student_id' => $otherStudent->id,
        ]);

        return [
            'enrollment' => $enrollment,
            'group' => $group,
            'otherEnrollment' => $otherEnrollment,
            'otherGroup' => $otherGroup,
            'otherTeacher' => $otherTeacher,
            'student' => $student,
            'teacher' => $teacher,
            'teacherUser' => $teacherUser,
            'token' => $this->issueToken($teacherUser, 'teacher-operational-device'),
        ];
    }
}
