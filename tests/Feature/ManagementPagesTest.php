<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_table_headers_use_symbol_actions_and_single_column_filter_dialogs(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('initializeMobileTableHeaderActions(toolbar)', $script);
        $this->assertStringContainsString("toolbar.classList.add('mobile-table-header-controls')", $script);
        $this->assertStringContainsString("action.classList.add('mobile-table-header-action')", $script);
        $this->assertStringContainsString("if (!action.querySelector('.mobile-table-action__icon'))", $script);
        $this->assertStringContainsString('.surface-table > .admin-grid-meta--controls', $styles);
        $this->assertStringContainsString('.mobile-table-filters--open::before', $styles);
        $this->assertStringContainsString('.mobile-table-filters--open > .mobile-table-filter-criterion', $styles);
        $this->assertStringContainsString('grid-column: 1 / -1 !important;', $styles);
    }

    public function test_mobile_card_tables_are_limited_to_assessments_and_workflow_table_add_actions_are_removed(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.standard-mobile-table .responsive-records-mobile', $styles);
        $this->assertStringContainsString('.standard-mobile-table .points-ledger-mobile', $styles);
        $this->assertStringContainsString('.workflow-entry-action--hidden', $styles);

        foreach (['points', 'users', 'teachers', 'parents'] as $page) {
            $view = file_get_contents(resource_path("views/livewire/{$page}/index.blade.php"));

            $this->assertStringContainsString('standard-mobile-table', $view);
        }

        $assessmentView = file_get_contents(resource_path('views/livewire/assessments/index.blade.php'));
        $this->assertStringNotContainsString('standard-mobile-table', $assessmentView);

        foreach (['memorization', 'quran-partial-tests', 'quran-final-tests', 'quran-tests'] as $page) {
            $view = file_get_contents(resource_path("views/livewire/{$page}/index.blade.php"));

            $this->assertStringNotContainsString('wire:click="openCreateModal"', $view);
            $this->assertStringNotContainsString('workflow-entry-action--hidden', $view);
        }
    }

    public function test_sidebar_uses_the_approved_outline_icons_without_changing_reference_icons(): void
    {
        $items = app(SidebarNavigationService::class)->defaultItems();
        $expectedIcons = [
            'dashboard' => 'home',
            'reports' => 'chart-bar',
            'student_progress' => 'presentation-chart-line',
            'users' => 'user-group',
            'parents' => 'parents-couple',
            'teachers' => 'male-teacher',
            'students' => 'student-graduates',
            'community_contacts' => 'landline-phone',
            'courses' => 'mosque',
            'groups' => 'people-circle',
            'curricula' => 'books-leaning',
            'enrollments' => 'enrollment-add',
            'student_attendance' => 'clipboard-student',
            'teacher_attendance' => 'clipboard-person',
            'memorization' => 'quran-stand',
            'enter_memorize' => 'pencil-square',
            'quran_tests_quick_entry' => 'book-open-pencil',
            'quran_tests' => 'certificate-landscape',
            'assessments' => 'assessment-review',
            'point_ledger' => 'achievement-star',
            'student_notes' => 'note-document',
            'finance_dashboard' => 'finance-dashboard',
            'finance_expense_requests' => 'expense-receipt',
            'finance_revenue_requests' => 'income-hand',
            'finance_exchange' => 'arrows-right-left',
            'finance_reports' => 'document-chart-bar',
            'finance_pull_requests' => 'withdrawal-hand',
            'dashboard_settings' => 'cog-6-tooth',
            'finance_settings' => 'finance-settings',
            'public_website_settings' => 'globe-alt',
            'print_templates' => 'printing-template',
            'id_card_print' => 'student-id-card',
        ];

        $customIcons = [
            'achievement-star',
            'assessment-review',
            'book-open-pencil',
            'books-leaning',
            'certificate-landscape',
            'clipboard-person',
            'clipboard-student',
            'enrollment-add',
            'expense-receipt',
            'finance-dashboard',
            'finance-settings',
            'income-hand',
            'landline-phone',
            'male-teacher',
            'mosque',
            'note-document',
            'parents-couple',
            'people-circle',
            'printing-template',
            'quran-stand',
            'student-id-card',
            'student-graduates',
            'withdrawal-hand',
        ];

        foreach ($expectedIcons as $key => $icon) {
            $this->assertSame($icon, $items[$key]['icon']);

            if (in_array($icon, $customIcons, true)) {
                $this->assertFileExists(resource_path("views/flux/icon/{$icon}.blade.php"));

                continue;
            }

            $this->assertFileExists(base_path("vendor/livewire/flux/stubs/resources/views/flux/icon/{$icon}.blade.php"));
        }

        $wrapper = file_get_contents(resource_path('views/components/sidebar-outline-icon.blade.php'));
        $this->assertStringContainsString('stroke-width="1.5"', $wrapper);
        $this->assertStringContainsString('stroke-linecap="round"', $wrapper);
        $this->assertStringContainsString('stroke-linejoin="round"', $wrapper);
        $this->assertStringContainsString('Str::uuid()', $wrapper);
        $this->assertStringContainsString('mask-type="luminance"', $wrapper);
        $this->assertStringContainsString('data-sidebar-icon-merged-paint', $wrapper);
        $this->assertStringContainsString('data-attendance-badge="person"', file_get_contents(resource_path('views/flux/icon/clipboard-person.blade.php')));
        $this->assertStringContainsString('data-attendance-badge="graduation-cap"', file_get_contents(resource_path('views/flux/icon/clipboard-student.blade.php')));
        $this->assertStringContainsString('data-enrollment-icon="profile-card-add"', file_get_contents(resource_path('views/flux/icon/enrollment-add.blade.php')));
        $this->assertStringContainsString('data-certificate-style="centered-medal"', file_get_contents(resource_path('views/flux/icon/certificate-landscape.blade.php')));
    }

    public function test_secondary_student_tools_are_buttons_in_their_parent_pages(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['username' => 'student-tools-manager', 'phone' => '9000099']);
        $user->assignRole('manager');
        $this->actingAs($user);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertDontSeeText(__('ui.nav.bulk_student_photos'));
        $this->get(route('student-attendance.index', absolute: false))
            ->assertOk()
            ->assertSeeText(__('ui.nav.scanner_import'));

        $items = app(SidebarNavigationService::class)->defaultItems();
        $this->assertArrayNotHasKey('bulk_student_photos', $items);
        $this->assertArrayNotHasKey('scanner_import', $items);
        $this->assertArrayNotHasKey('awqaf_subject_tests', $items);
        $this->assertFalse(Route::has('awqaf-subject-tests.index'));
    }

    public function test_management_pages_require_authentication(): void
    {
        [$group, $student, $activity, $invoice] = $this->makeRouteModels();

        foreach ([
            route('reports.index', absolute: false),
            route('reports.student-activity-summary', absolute: false),
            route('reports.rankings.groups', absolute: false),
            route('reports.rankings.students', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('students.index', absolute: false),
            route('students.files', $student, absolute: false),
            route('memorization.index', absolute: false),
            route('student-attendance.index', absolute: false),
            route('courses.index', absolute: false),
            route('groups.index', absolute: false),
            route('groups.show', $group, absolute: false),
            route('groups.schedules', $group, absolute: false),
            route('enrollments.index', absolute: false),
            route('student-notes.index', absolute: false),
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.family', absolute: false),
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
            route('reports.student-activity-summary', absolute: false),
            route('reports.rankings.groups', absolute: false),
            route('reports.rankings.students', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('students.index', absolute: false),
            route('students.files', $student, absolute: false),
            route('memorization.index', absolute: false),
            route('student-attendance.index', absolute: false),
            route('courses.index', absolute: false),
            route('groups.index', absolute: false),
            route('groups.show', $group, absolute: false),
            route('groups.schedules', $group, absolute: false),
            route('enrollments.index', absolute: false),
            route('student-notes.index', absolute: false),
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_authenticated_users_can_download_group_roster_export(): void
    {
        $this->seed(RoleSeeder::class);
        [$group] = $this->makeRouteModels();

        $user = User::factory()->create([
            'username' => 'manager-roster-export',
            'phone' => '9000001',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);

        $this->get(route('groups.roster.export', $group, absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $pdfResponse = $this->get(route('groups.roster.pdf', $group, absolute: false));
        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdfResponse->getContent());
    }

    public function test_authenticated_users_can_download_eligible_awqaf_students_export(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'manager-awqaf-export',
            'phone' => '9000002',
        ]);
        $user->assignRole('manager');
        $this->actingAs($user);

        $teacher = Teacher::create([
            'first_name' => 'Awqaf',
            'last_name' => 'Export Teacher',
            'phone' => '0944005100',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Awqaf Export Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->first()
            ?? AcademicYear::create([
                'name' => '2026 / 2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
            ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Awqaf Export Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Export Father',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Eligible',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $juz = QuranJuz::query()->first()
            ?? QuranJuz::create([
                'juz_number' => 1,
                'name' => 'Juz 1',
                'from_page' => 1,
                'to_page' => 20,
            ]);

        QuranFinalTest::create([
            'created_by' => $user->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'passed',
            'passed_on' => '2026-09-10',
        ]);

        $this->get(route('quran-tests.eligible-awqaf.export', absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_authenticated_users_without_management_permissions_are_forbidden(): void
    {
        $this->seed(RoleSeeder::class);
        [$teacherUser, $teacherGroup, $teacherStudent, $teacherEnrollment, $otherGroup, $otherEnrollment, $activity, $invoice] = $this->makeTeacherJourneyModels();

        $this->actingAs($teacherUser);

        foreach ([
            route('students.index', absolute: false),
            route('students.files', $teacherStudent, absolute: false),
            route('groups.attendance', $teacherGroup, absolute: false),
            route('student-attendance.index', absolute: false),
            route('groups.schedules', $teacherGroup, absolute: false),
            route('enrollments.index', absolute: false),
            route('memorization.index', absolute: false),
            route('enrollments.memorization', $teacherEnrollment, absolute: false),
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('enrollments.quran-tests', $teacherEnrollment, absolute: false),
            route('enrollments.points', $teacherEnrollment, absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        $this->get(route('groups.index', absolute: false))
            ->assertOk()
            ->assertSeeText($teacherGroup->name);

        foreach ([
            route('reports.index', absolute: false),
            route('reports.student-activity-summary', absolute: false),
            route('reports.rankings.groups', absolute: false),
            route('reports.rankings.students', absolute: false),
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

        MemorizationSession::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownEnrollment->student_id,
            'teacher_id' => $ownEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'from_page' => 3,
            'to_page' => 5,
            'pages_count' => 3,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherEnrollment->student_id,
            'teacher_id' => $otherEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-11',
            'entry_type' => 'new',
            'from_page' => 6,
            'to_page' => 8,
            'pages_count' => 3,
        ]);

        $this->get(route('memorization.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('invoices.index', absolute: false))
            ->assertOk()
            ->assertSeeText($ownInvoice->invoice_no)
            ->assertDontSeeText($otherInvoice->invoice_no);

        Activity::create([
            'title' => 'Parent Activity',
            'activity_date' => '2026-09-22',
            'audience_scope' => 'single_group',
            'group_id' => $ownEnrollment->group_id,
            'fee_amount' => 15,
            'is_active' => true,
        ]);

        Activity::create([
            'title' => 'Hidden Parent Activity',
            'activity_date' => '2026-09-23',
            'audience_scope' => 'single_group',
            'group_id' => $otherEnrollment->group_id,
            'fee_amount' => 18,
            'is_active' => true,
        ]);

        $this->get(route('activities.family', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Activity')
            ->assertDontSeeText('Hidden Parent Activity');

        foreach ([
            route('students.files', $ownStudent, absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('enrollments.memorization', $ownEnrollment, absolute: false),
            route('enrollments.quran-tests', $ownEnrollment, absolute: false),
            route('enrollments.points', $ownEnrollment, absolute: false),
            route('invoices.payments', $ownInvoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
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

        MemorizationSession::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownEnrollment->student_id,
            'teacher_id' => $ownEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-12',
            'entry_type' => 'new',
            'from_page' => 9,
            'to_page' => 11,
            'pages_count' => 3,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherEnrollment->student_id,
            'teacher_id' => $otherEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-13',
            'entry_type' => 'new',
            'from_page' => 12,
            'to_page' => 14,
            'pages_count' => 3,
        ]);

        $this->get(route('memorization.index', absolute: false))
            ->assertOk()
            ->assertSeeText($studentRecord->first_name.' '.$studentRecord->last_name)
            ->assertDontSeeText('Other Student');

        foreach ([
            route('students.files', $studentRecord, absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('enrollments.memorization', $ownEnrollment, absolute: false),
            route('enrollments.quran-tests', $ownEnrollment, absolute: false),
            route('enrollments.points', $ownEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
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
