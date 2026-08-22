<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ActivityAudienceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentMobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_mobile_api_returns_only_logged_in_parent_data(): void
    {
        $this->seed(RoleSeeder::class);

        $context = $this->makeParentMobileContext();

        Sanctum::actingAs($context['parentUser']);

        $this->getJson('/api/v1/parent/profile')
            ->assertOk()
            ->assertJsonPath('data.father_name', 'Mobile Parent')
            ->assertJsonPath('data.children.0.full_name', 'Mobile Student');

        $this->getJson('/api/v1/parent/children')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Mobile Student')
            ->assertJsonPath('data.0.memorized_pages', 8)
            ->assertJsonPath('data.0.points', 15);

        $this->getJson('/api/v1/parent/children/'.$context['student']->id.'/attendance?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.0.status.code', 'present')
            ->assertJsonPath('data.0.group.name', 'Mobile Group');

        $this->getJson('/api/v1/parent/children/'.$context['student']->id.'/memorization')
            ->assertOk()
            ->assertJsonPath('data.0.pages_count', 3)
            ->assertJsonPath('data.0.group.name', 'Mobile Group');

        $this->getJson('/api/v1/parent/children/'.$context['student']->id.'/points')
            ->assertOk()
            ->assertJsonPath('data.0.points', 6)
            ->assertJsonPath('data.0.point_type.code', 'mobile_bonus');

        $this->getJson('/api/v1/parent/children/'.$context['student']->id.'/assessments')
            ->assertOk()
            ->assertJsonPath('data.0.assessment.title', 'Mobile Quiz')
            ->assertJsonPath('data.0.score', 90);

        $this->getJson('/api/v1/parent/children/'.$context['student']->id.'/quran-tests')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'legacy')
            ->assertJsonPath('data.0.score', 92);

        $this->getJson('/api/v1/parent/children/'.$context['student']->id.'/notes')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Visible mobile note')
            ->assertJsonMissing(['body' => 'Private mobile note']);

        $this->getJson('/api/v1/parent/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.invoice_no', 'MOB-INV-001')
            ->assertJsonPath('data.0.balance', 20);

        $this->getJson('/api/v1/parent/invoices/'.$context['invoice']->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.description', 'Monthly fee')
            ->assertJsonPath('data.payments.0.amount', 30);

        $this->getJson('/api/v1/parent/children/'.$context['otherStudent']->id)->assertNotFound();
        $this->getJson('/api/v1/parent/invoices/'.$context['otherInvoice']->id)->assertNotFound();
    }

    public function test_parent_can_respond_to_eligible_activity_via_mobile_api(): void
    {
        $this->seed(RoleSeeder::class);

        $context = $this->makeParentMobileContext();

        $activity = Activity::create([
            'title' => 'Mobile Picnic',
            'activity_date' => '2026-10-15',
            'audience_scope' => ActivityAudienceService::SCOPE_SINGLE_GROUP,
            'group_id' => $context['group']->id,
            'fee_amount' => 18,
            'is_active' => true,
        ]);

        app(ActivityAudienceService::class)->syncTargets($activity, ActivityAudienceService::SCOPE_SINGLE_GROUP, $context['group']->id);

        Sanctum::actingAs($context['parentUser']);

        $this->getJson('/api/v1/parent/activities')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Mobile Picnic')
            ->assertJsonPath('data.0.eligible_students.0.student.full_name', 'Mobile Student');

        $this->postJson('/api/v1/parent/activities/'.$activity->id.'/responses', [
            'student_id' => $context['student']->id,
            'response' => 'registered',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.fee_amount', 18);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id,
            'student_id' => $context['student']->id,
            'enrollment_id' => $context['enrollment']->id,
            'status' => 'registered',
        ]);

        $this->postJson('/api/v1/parent/activities/'.$activity->id.'/responses', [
            'student_id' => $context['otherStudent']->id,
            'response' => 'registered',
        ])->assertNotFound();
    }

    public function test_parent_auth_token_includes_mobile_parent_abilities(): void
    {
        $this->seed(RoleSeeder::class);

        $parentUser = User::factory()->create([
            'username' => 'parent-mobile-login',
            'phone' => '0993333000',
        ]);
        $parentUser->forceFill(['password' => 'password'])->save();
        $parentUser->assignRole('parent');

        ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Token Parent',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'device_name' => 'Parent Mobile',
            'login' => '0993333000',
            'password' => 'password',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.roles.0', 'parent');

        $this->assertContains('activities.responses.respond', $response->json('abilities'));
        $this->assertContains('activities.responses.view', $response->json('abilities'));
        $this->assertContains('dashboard.parent.view', $response->json('abilities'));
        $this->assertContains('points.view', $response->json('abilities'));
        $this->assertContains('quran-tests.view', $response->json('abilities'));
    }

    private function makeParentMobileContext(): array
    {
        $parentUser = User::factory()->create([
            'username' => 'parent-mobile',
            'phone' => '0993333001',
        ]);
        $parentUser->assignRole('parent');

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Mobile Parent',
            'father_phone' => '0993333001',
            'is_active' => true,
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Mobile Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Mobile',
            'last_name' => 'Teacher',
            'phone' => '0993333002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Mobile Quran',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => '2026/2027 Mobile',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Mobile Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Mobile Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $juz = QuranJuz::create([
            'juz_number' => 1,
            'from_page' => 1,
            'to_page' => 20,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Mobile',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Mobile',
            'birth_date' => '2014-05-13',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 15,
            'memorized_pages_cached' => 8,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 9,
            'memorized_pages_cached' => 4,
        ]);

        $attendanceStatus = AttendanceStatus::create([
            'name' => 'Present',
            'code' => 'present',
            'scope' => 'student',
            'is_present' => true,
            'is_default' => true,
            'is_active' => true,
        ]);

        $attendanceDay = GroupAttendanceDay::create([
            'group_id' => $group->id,
            'attendance_date' => '2026-09-10',
            'status' => 'closed',
        ]);

        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $attendanceDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $attendanceStatus->id,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-11',
            'entry_type' => 'new',
            'from_page' => 581,
            'to_page' => 583,
            'pages_count' => 3,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-11',
            'entry_type' => 'new',
            'from_page' => 584,
            'to_page' => 585,
            'pages_count' => 2,
        ]);

        $pointType = PointType::create([
            'name' => 'Mobile Bonus',
            'code' => 'mobile_bonus',
            'category' => 'bonus',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        PointTransaction::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 6,
            'entered_by' => $parentUser->id,
            'entered_at' => '2026-09-12 09:00:00',
            'notes' => 'Mobile visible points',
        ]);

        PointTransaction::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $parentUser->id,
            'entered_at' => '2026-09-12 09:00:00',
        ]);

        $assessmentType = AssessmentType::create([
            'name' => 'Mobile Quiz Type',
            'code' => 'mobile_quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $assessment = Assessment::create([
            'group_id' => $group->id,
            'assessment_type_id' => $assessmentType->id,
            'title' => 'Mobile Quiz',
            'due_at' => '2026-09-13',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'score' => 90,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $quranType = QuranTestType::create([
            'name' => 'Mobile Partial',
            'code' => 'mobile_partial',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        QuranTest::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $quranType->id,
            'tested_on' => '2026-09-14',
            'score' => 92,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        StudentNote::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Visible mobile note',
            'noted_at' => '2026-09-15 09:00:00',
        ]);

        StudentNote::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'staff_only',
            'body' => 'Private mobile note',
            'noted_at' => '2026-09-15 10:00:00',
        ]);

        $invoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'MOB-INV-001',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'status' => 'sent',
            'subtotal' => 50,
            'discount' => 0,
            'total' => 50,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'description' => 'Monthly fee',
            'quantity' => 1,
            'unit_price' => 50,
            'amount' => 50,
        ]);

        $method = PaymentMethod::create([
            'name' => 'Cash',
            'code' => 'cash_mobile',
            'is_active' => true,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $method->id,
            'paid_at' => '2026-09-05',
            'amount' => 30,
        ]);

        $otherInvoice = Invoice::create([
            'parent_id' => $otherParent->id,
            'invoice_no' => 'MOB-INV-002',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-01',
            'status' => 'sent',
            'subtotal' => 40,
            'discount' => 0,
            'total' => 40,
        ]);

        return [
            'parentUser' => $parentUser,
            'parent' => $parent,
            'student' => $student,
            'otherStudent' => $otherStudent,
            'group' => $group,
            'enrollment' => $enrollment,
            'invoice' => $invoice,
            'otherInvoice' => $otherInvoice,
        ];
    }
}
