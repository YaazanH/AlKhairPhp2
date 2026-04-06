<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_pages_require_authentication(): void
    {
        [$group, $student, $activity, $invoice] = $this->makeRouteModels();

        foreach ([
            route('reports.index', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('students.index', absolute: false),
            route('students.files', $student, absolute: false),
            route('courses.index', absolute: false),
            route('groups.index', absolute: false),
            route('groups.schedules', $group, absolute: false),
            route('enrollments.index', absolute: false),
            route('student-notes.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_authenticated_users_can_open_management_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [$group, $student, $activity, $invoice] = $this->makeRouteModels();

        $user = User::factory()->create([
            'username' => 'manager-user',
            'phone' => '9000000',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);

        foreach ([
            route('reports.index', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('students.index', absolute: false),
            route('students.files', $student, absolute: false),
            route('courses.index', absolute: false),
            route('groups.index', absolute: false),
            route('groups.schedules', $group, absolute: false),
            route('enrollments.index', absolute: false),
            route('student-notes.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_authenticated_users_without_management_permissions_are_forbidden(): void
    {
        $this->seed(RoleSeeder::class);
        [$teacherUser, $teacherGroup, $teacherStudent, $teacherEnrollment, $otherGroup, $otherEnrollment, $activity, $invoice] = $this->makeTeacherJourneyModels();

        $this->actingAs($teacherUser);

        foreach ([
            route('students.index', absolute: false),
            route('students.files', $teacherStudent, absolute: false),
            route('groups.index', absolute: false),
            route('groups.attendance', $teacherGroup, absolute: false),
            route('groups.schedules', $teacherGroup, absolute: false),
            route('enrollments.index', absolute: false),
            route('enrollments.memorization', $teacherEnrollment, absolute: false),
            route('enrollments.quran-tests', $teacherEnrollment, absolute: false),
            route('enrollments.points', $teacherEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('reports.index', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('courses.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
            route('groups.attendance', $otherGroup, absolute: false),
            route('enrollments.memorization', $otherEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_parent_users_can_open_their_read_only_student_enrollment_and_invoice_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $ownStudent, $ownEnrollment, $otherEnrollment, $ownInvoice, $otherInvoice] = $this->makeParentJourneyModels();

        $this->actingAs($parentUser);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Group')
            ->assertDontSeeText('Other Group');

        $this->get(route('invoices.index', absolute: false))
            ->assertOk()
            ->assertSeeText($ownInvoice->invoice_no)
            ->assertDontSeeText($otherInvoice->invoice_no);

        foreach ([
            route('students.files', $ownStudent, absolute: false),
            route('enrollments.memorization', $ownEnrollment, absolute: false),
            route('enrollments.quran-tests', $ownEnrollment, absolute: false),
            route('enrollments.points', $ownEnrollment, absolute: false),
            route('invoices.payments', $ownInvoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('enrollments.memorization', $otherEnrollment, absolute: false),
            route('invoices.payments', $otherInvoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_student_users_can_open_their_read_only_progress_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [$studentUser, $studentRecord, $ownEnrollment, $otherEnrollment] = $this->makeStudentJourneyModels();

        $this->actingAs($studentUser);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText($studentRecord->first_name.' '.$studentRecord->last_name)
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Student Scope Group')
            ->assertDontSeeText('Other Group');

        foreach ([
            route('students.files', $studentRecord, absolute: false),
            route('enrollments.memorization', $ownEnrollment, absolute: false),
            route('enrollments.quran-tests', $ownEnrollment, absolute: false),
            route('enrollments.points', $ownEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('students.files', $otherEnrollment->student, absolute: false),
            route('enrollments.memorization', $otherEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    private function makeRouteModels(): array
    {
        $parent = ParentProfile::create([
            'father_name' => 'Route Parent',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Route',
            'last_name' => 'Teacher',
            'phone' => '0990000000',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Route Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Route Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Route',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $activity = Activity::create([
            'title' => 'Route Activity',
            'activity_date' => '2026-09-15',
            'group_id' => $group->id,
            'fee_amount' => 25,
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-ROUTE-0001',
            'invoice_type' => 'other',
            'issue_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
        ]);

        return [$group, $student, $activity, $invoice];
    }

    private function makeTeacherJourneyModels(): array
    {
        $teacherUser = User::factory()->create([
            'username' => 'teacher-user',
            'phone' => '9000001',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Journey',
            'last_name' => 'Teacher',
            'phone' => '0991000001',
            'status' => 'active',
        ]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0991000002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Teacher Journey Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Journey Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Teacher',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $teacherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Teacher Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $teacherEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $teacherGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $activity = Activity::create([
            'title' => 'Teacher Activity',
            'activity_date' => '2026-09-15',
            'group_id' => $otherGroup->id,
            'fee_amount' => 25,
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-TEACHER-0001',
            'invoice_type' => 'other',
            'issue_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
        ]);

        return [$teacherUser, $teacherGroup, $student, $teacherEnrollment, $otherGroup, $otherEnrollment, $activity, $invoice];
    }

    private function makeParentJourneyModels(): array
    {
        $parentUser = User::factory()->create([
            'username' => 'parent-user',
            'phone' => '9000002',
        ]);
        $parentUser->assignRole('parent');

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Scoped Parent',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Parent',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Parent',
            'last_name' => 'Teacher',
            'phone' => '0991000003',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Parent Journey Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Parent',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Parent Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $ownInvoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-PARENT-0001',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 100,
            'discount' => 0,
            'total' => 100,
        ]);

        $otherInvoice = Invoice::create([
            'parent_id' => $otherParent->id,
            'invoice_no' => 'INV-PARENT-0002',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-21',
            'status' => 'issued',
            'subtotal' => 120,
            'discount' => 0,
            'total' => 120,
        ]);

        return [$parentUser, $ownStudent, $ownEnrollment, $otherEnrollment, $ownInvoice, $otherInvoice];
    }

    private function makeStudentJourneyModels(): array
    {
        $studentUser = User::factory()->create([
            'username' => 'student-user',
            'phone' => '9000003',
        ]);
        $studentUser->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Student Parent',
        ]);

        $studentRecord = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Scoped',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Student',
            'last_name' => 'Teacher',
            'phone' => '0991000004',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Student Journey Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Scope Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $studentRecord->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        return [$studentUser, $studentRecord, $ownEnrollment, $otherEnrollment];
    }
}
