<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\SystemBackup;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\SidebarNavigationService;
use App\Services\StudentAttendanceDayService;
use App\Services\SystemBackupService;
use App\Services\TeacherAttendanceDayService;
use App\Support\DataAuditVisibility;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OperationalRefinementsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    private function group(?Course $course = null): Group
    {
        if (! $course) {
            $year = AcademicYear::create(['name' => 'Test year '.Str::random(5), 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
            $course = Course::create(['name' => 'Test course '.Str::random(5), 'academic_year_id' => $year->id, 'is_active' => true]);
        }

        $teacher = Teacher::create(['first_name' => 'Test', 'last_name' => 'Teacher', 'phone' => '0999000000', 'is_helping' => true]);

        return Group::create(['teacher_id' => $teacher->id, 'name' => 'Group '.Str::random(5), 'course_id' => $course->id, 'academic_year_id' => $course->academic_year_id, 'is_active' => true]);
    }

    public function test_enrollment_cannot_be_duplicated_across_groups_dates_or_statuses(): void
    {
        $this->signIn();
        $group = $this->group();
        $otherGroup = $this->group($group->course);
        $student = Student::create(['first_name' => 'Test', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $existing = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'status' => 'completed', 'enrolled_at' => '2026-01-01']);

        $data = ['student_id' => $student->id, 'group_id' => $otherGroup->id, 'status' => 'active', 'enrolled_at' => '2026-09-26'];
        $this->postJson('/api/v1/enrollments', $data)->assertUnprocessable()->assertJsonValidationErrors('student_id');
        Volt::test('enrollments.index')->set('student_id', $student->id)->set('group_id', $otherGroup->id)
            ->call('save')->assertHasErrors('student_id');
        Volt::test('groups.show', ['group' => $otherGroup])->set('roster_student_id', (string) $student->id)
            ->set('roster_enrolled_at', '2026-09-26')->call('addStudent')->assertHasErrors('roster_student_id');
        Volt::test('groups.index')->set('rosterGroupId', $otherGroup->id)->set('roster_student_id', $student->id)
            ->set('roster_enrolled_at', '2026-09-26')->call('addStudentToRoster')->assertHasErrors('roster_student_id');

        $existing->update(['group_id' => $otherGroup->id]);
        $this->assertSame($otherGroup->id, $existing->fresh()->group_id);
        $existing->delete();

        try {
            Enrollment::create($data);
            $this->fail('A deleted enrollment must not allow a second record in the course.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_id', $exception->errors());
        }
        $existing->restore();
        $this->assertSame(1, Enrollment::withTrashed()->where('student_id', $student->id)->count());

        $differentCourseGroup = $this->group();
        Enrollment::create([...$data, 'group_id' => $differentCourseGroup->id]);
        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_inactive_group_can_only_be_reactivated_in_an_active_course_by_an_authorized_user(): void
    {
        $this->signIn();
        $group = $this->group();
        $group->refresh()->update(['is_active' => false]);
        Volt::test('groups.show', ['group' => $group])->assertSee('data-group-reactivate-action', false)
            ->call('reactivate')->assertHasNoErrors();
        $this->assertTrue($group->fresh()->is_active);

        $group->refresh()->update(['is_active' => false]);
        $group->course->update(['is_active' => false]);
        Volt::test('groups.show', ['group' => $group->fresh()])->assertDontSee('data-group-reactivate-action', false)
            ->call('reactivate')->assertHasErrors('group');
        $this->assertFalse($group->fresh()->is_active);

        $component = Volt::test('groups.show', ['group' => $group->fresh()]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('groups.view');
        $this->actingAs($viewer);
        $component->call('reactivate')->assertForbidden();
    }

    public function test_navigation_defaults_match_the_supplied_order_and_can_be_restored(): void
    {
        $this->signIn();
        $service = app(SidebarNavigationService::class);
        AppSetting::storeValue('general', 'activity_entries_enabled', false, 'boolean');
        $expected = [
            'platform' => ['dashboard', 'student_progress', 'reports', 'curricula'],
            'registration' => ['enrollments', 'students'],
            'tracking_attendance' => ['student_attendance'],
            'memorization_entry' => ['enter_memorize', 'quran_tests_quick_entry'],
            'tracking_quran' => ['memorization', 'quran_partial_tests', 'quran_final_tests', 'quran_tests'],
            'tracking_performance' => ['assessments', 'point_ledger'],
            'tracking_tools' => ['student_notes'],
            'finance' => ['finance_dashboard', 'finance_expense_requests', 'finance_revenue_requests', 'finance_exchange', 'finance_reports', 'student_billing'],
            'identity_tools' => ['id_card_print'],
            'designs' => ['public_website_settings', 'print_templates'],
            'academics' => ['courses', 'groups'],
            'people' => ['users', 'teachers', 'parents'],
            'activities' => ['community_contacts'],
            'database' => ['data_quality', 'data_audit'],
            'configuration' => ['dashboard_settings', 'finance_settings'],
        ];
        $service->save(['custom_test' => ['title' => 'Custom', 'sort_order' => 0]], ['reports' => ['group_key' => 'custom_test', 'sort_order' => 0]]);
        Volt::test('settings.sidebar-navigation')->assertSee('data-sidebar-restore-defaults', false)
            ->call('restoreDefaults')->assertDispatched('sidebar-navigation-updated');
        $actual = collect($service->sidebarFor(auth()->user()))->mapWithKeys(fn (array $group) => [$group['key'] => array_column($group['items'], 'key')])->all();
        $this->assertSame($expected, $actual);
        $this->assertArrayNotHasKey('custom_test', $service->settings()['groups']);
    }

    public function test_routine_audit_creations_and_edits_are_hidden_retroactively_but_deletions_remain(): void
    {
        $this->signIn();
        Activity::query()->delete();
        foreach (DataAuditVisibility::ROUTINE_MODELS as $model) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                Activity::create(['log_name' => 'data-audit', 'description' => $event.' '.$model, 'subject_type' => $model, 'subject_id' => 9999, 'event' => $event]);
            }
        }
        Volt::test('data-audit.index')->assertViewHas('activities', fn ($rows) => $rows->total() === count(DataAuditVisibility::ROUTINE_MODELS)
            && $rows->getCollection()->every(fn ($row) => $row['event'] === 'deleted'));
        $this->assertSame(count(DataAuditVisibility::ROUTINE_MODELS) * 3, Activity::count());

        $group = $this->group();
        $day = GroupAttendanceDay::create(['group_id' => $group->id, 'attendance_date' => '2026-09-26', 'status' => 'open']);
        $day->update(['notes' => 'Routine attendance update']);
        $this->assertFalse(Activity::where('subject_type', GroupAttendanceDay::class)->where('subject_id', $day->id)->exists());
        $day->delete();
        $this->assertTrue(Activity::where('subject_type', GroupAttendanceDay::class)->where('subject_id', $day->id)->where('event', 'deleted')->exists());
    }

    public function test_failed_scheduled_backups_retry_after_backoff_and_abandoned_attempts_do_not_block(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(5, 0));
        AppSetting::storeValue('backups', 'time', '02:00');
        $backup = SystemBackup::create(['uuid' => (string) Str::uuid(), 'disk' => 'local', 'file_path' => 'failed.alkhair-backup', 'filename' => 'failed.alkhair-backup', 'scope' => 'database', 'trigger' => 'scheduled', 'status' => 'failed']);
        $service = app(SystemBackupService::class);
        $this->assertFalse($service->scheduledBackupIsDue());
        $this->travel(16)->minutes();
        $this->assertTrue($service->scheduledBackupIsDue());
        $backup->forceFill(['status' => 'creating', 'created_at' => now()])->save();
        $this->assertFalse($service->scheduledBackupIsDue());
        $this->travel(121)->minutes();
        $this->assertTrue($service->scheduledBackupIsDue());
        $backup->forceFill(['status' => 'completed', 'verified_at' => now()])->save();
        $this->assertFalse($service->scheduledBackupIsDue());
    }

    public function test_opening_and_reopening_attendance_days_closes_the_other_day_and_its_groups(): void
    {
        $this->signIn();
        $group = $this->group();
        $studentDays = app(StudentAttendanceDayService::class);
        $first = $studentDays->createOrSyncDay('2026-09-24', collect([$group]));
        $second = $studentDays->createOrSyncDay('2026-09-25', collect([$group]));
        $this->assertSame('closed', $first->fresh()->status);
        $this->assertSame('closed', $first->groupAttendanceDays()->first()->status);
        $this->assertSame(1, StudentAttendanceDay::where('status', 'open')->count());

        Volt::test('student-attendance.show', ['studentAttendanceDay' => $first])->call('toggleDayStatus')->assertHasNoErrors();
        $this->assertSame('open', $first->fresh()->status);
        $this->assertSame('closed', $second->fresh()->status);
        $this->assertSame('closed', $second->groupAttendanceDays()->first()->status);

        $teacherDays = app(TeacherAttendanceDayService::class);
        $firstTeacherDay = $teacherDays->createOrSyncDay('2026-09-24', collect([$group->teacher]), courseId: $group->course_id);
        $secondTeacherDay = $teacherDays->createOrSyncDay('2026-09-25', collect([$group->teacher]), courseId: $group->course_id);
        $this->assertSame('closed', $firstTeacherDay->fresh()->status);
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $firstTeacherDay])->call('toggleDayStatus')->assertHasNoErrors();
        $this->assertSame('open', $firstTeacherDay->fresh()->status);
        $this->assertSame('closed', $secondTeacherDay->fresh()->status);
        $this->assertSame(1, TeacherAttendanceDay::where('status', 'open')->count());
        $this->assertSame('open', $first->fresh()->status);
        $this->assertSame(2, TeacherAttendanceRecord::count());

        // Upgrade existing installations with multiple open days without losing records.
        DB::table('student_attendance_days')->update(['status' => 'open']);
        DB::table('group_attendance_days')->update(['status' => 'open']);
        DB::table('teacher_attendance_days')->update(['status' => 'open']);
        (require database_path('migrations/2026_09_26_010000_keep_one_open_attendance_day.php'))->up();
        $this->assertSame([$second->id], StudentAttendanceDay::where('status', 'open')->pluck('id')->all());
        $this->assertSame('closed', $first->groupAttendanceDays()->first()->status);
        $this->assertSame([$secondTeacherDay->id], TeacherAttendanceDay::where('status', 'open')->pluck('id')->all());
        $this->assertSame(2, TeacherAttendanceRecord::count());
    }

    public function test_teacher_attendance_buttons_save_both_choices_and_respect_closed_days(): void
    {
        $this->signIn();
        $group = $this->group();
        $present = AttendanceStatus::create(['name' => 'Present', 'code' => 'present', 'scope' => 'both', 'is_present' => true, 'is_active' => true]);
        $absent = AttendanceStatus::create(['name' => 'Absent', 'code' => 'absent', 'scope' => 'both', 'is_present' => false, 'is_active' => true]);
        $day = app(TeacherAttendanceDayService::class)->createOrSyncDay('2026-09-26', collect([$group->teacher]), courseId: $group->course_id);

        app()->setLocale('en');
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])
            ->assertSee('>Present</button>', false)->assertSee('>Absent</button>', false);
        app()->setLocale('ar');
        $component = Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day]);
        $component->assertSee('>حضور</button>', false)->assertSee('>غياب</button>', false)
            ->assertDontSee('>Present</button>', false)->assertDontSee('>Absent</button>', false);
        $component->call('chooseTeacherStatus', $group->teacher_id, $absent->id)->assertHasNoErrors()
            ->assertSee(trans_choice('workflow.teacher_attendance.table.summary', 0, ['count' => 0]));
        $this->assertSame($absent->id, $day->records()->first()->attendance_status_id);
        $component->call('chooseTeacherStatus', $group->teacher_id, $present->id)->assertHasNoErrors()
            ->assertSee(trans_choice('workflow.teacher_attendance.table.summary', 1, ['count' => 1]));
        $this->assertSame($present->id, $day->records()->first()->attendance_status_id);
        $day->update(['status' => 'closed']);
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])
            ->assertSee('>حضور</span>', false)->assertDontSee('>Present</span>', false);
        $component->call('chooseTeacherStatus', $group->teacher_id, $absent->id)->assertStatus(409);
        $this->assertSame($present->id, $day->records()->first()->attendance_status_id);
    }

    public function test_attendance_can_enroll_an_available_student_and_keep_the_popup_open_for_another(): void
    {
        $this->signIn();
        $group = $this->group();
        $status = AttendanceStatus::create(['name' => 'Present', 'code' => 'present', 'scope' => 'student', 'is_present' => true, 'is_default' => true, 'is_active' => true]);
        $student = Student::create(['first_name' => 'Available', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $other = Student::create(['first_name' => 'Another', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $day = app(StudentAttendanceDayService::class)->createOrSyncDay('2026-09-26', collect([$group]));
        $groupDay = $day->groupAttendanceDays()->firstOrFail();

        $component = Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay])
            ->assertSee('data-attendance-add-student', false)->call('openAddStudentModal')
            ->assertSee('data-attendance-add-and-new', false)
            ->set('rosterStudentId', $student->id)->call('addStudent', true)
            ->assertHasNoErrors()->assertSet('rosterStudentId', null)->assertSet('showAddStudentModal', true)
            ->assertViewHas('availableStudents', fn ($students) => ! $students->contains('id', $student->id) && $students->contains('id', $other->id));
        $enrollment = Enrollment::where('student_id', $student->id)->firstOrFail();
        $this->assertSame($group->id, $enrollment->group_id);
        $this->assertDatabaseHas('student_attendance_records', ['enrollment_id' => $enrollment->id, 'group_attendance_day_id' => $groupDay->id, 'attendance_status_id' => $status->id]);
        $component->set('rosterStudentId', $other->id)->call('addStudent', false)
            ->assertHasNoErrors()->assertSet('showAddStudentModal', false);
    }

    public function test_student_present_totals_include_every_present_status(): void
    {
        $this->signIn();
        app()->setLocale('ar');
        $group = $this->group();
        $statuses = collect([
            ['present', 'حضور', true],
            ['late', 'حضور متأخر', false],
            ['early', 'حضور مبكر', false],
            ['early-leave', 'انصراف مبكر', false],
            ['custom_present', 'حضور مخصص', true],
            ['absent', 'غياب', false],
        ])->map(fn ($values) => AttendanceStatus::create([
            'code' => $values[0], 'name' => $values[1], 'is_present' => $values[2],
            'scope' => 'student', 'is_active' => true, 'is_default' => $values[0] === 'absent',
        ]));
        (require database_path('migrations/2026_09_28_000000_count_arrival_and_early_departure_statuses_as_present.php'))->up();
        $statuses->each->refresh();
        $this->assertFalse($statuses->last()->is_present);
        $enrollments = $statuses->map(function ($status) use ($group) {
            $student = Student::create(['first_name' => $status->code, 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);

            return Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'status' => 'active', 'enrolled_at' => '2026-09-26']);
        });
        $service = app(StudentAttendanceDayService::class);
        $day = $service->createOrSyncDay('2026-09-26', collect([$group]));
        foreach ($statuses as $index => $status) {
            $service->recordEnrollmentStatus($day, $enrollments[$index], $status);
        }
        $groupDay = $day->groupAttendanceDays()->firstOrFail();

        Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay])
            ->assertViewHas('presentCount', 5)->assertSee('٥ طلاب حاضرون')
            ->set('selected_statuses.'.$enrollments[1]->id, $statuses->last()->id)
            ->call('saveEnrollmentStatus', $enrollments[1]->id)->assertHasNoErrors()
            ->assertViewHas('presentCount', 4)->assertSee('٤ طلاب حاضرون');
        Volt::test('student-attendance.show', ['studentAttendanceDay' => $day])
            ->assertViewHas('dayRecord', fn ($record) => $record->groupAttendanceDays->sum('present_records_count') === 4);
        Volt::test('student-attendance.index')
            ->assertViewHas('days', fn ($days) => $days->first()->groupAttendanceDays->sum('present_records_count') === 4);
    }

    public function test_attendance_day_numbers_follow_dates_and_survive_filtering_and_pagination(): void
    {
        $this->signIn();
        $group = $this->group();

        foreach ([StudentAttendanceDay::class => 'student-attendance.index', TeacherAttendanceDay::class => 'teachers.attendance'] as $model => $view) {
            // Create out of chronological order so the number cannot be the database ID.
            $latest = $model::create(['attendance_date' => '2026-09-28', 'course_id' => $group->course_id, 'status' => 'open']);
            $first = $model::create(['attendance_date' => '2026-09-26', 'course_id' => $group->course_id, 'status' => 'closed']);
            $middle = $model::create(['attendance_date' => '2026-09-27', 'course_id' => $group->course_id, 'status' => 'closed']);
            $expectedNumbers = [$first->id => 1, $middle->id => 2, $latest->id => 3];

            $component = Volt::test($view)->set('perPage', 2)
                ->assertViewHas('dayNumbers', fn ($numbers) => $numbers->all() === $expectedNumbers)
                ->assertViewHas('days', fn ($days) => $days->pluck('id')->all() === [$latest->id, $middle->id]);
            $component->call('setPage', 2)
                ->assertViewHas('days', fn ($days) => $days->pluck('id')->all() === [$first->id])
                ->assertViewHas('dayNumbers', fn ($numbers) => $numbers->all() === $expectedNumbers);
            $component->set('statusFilter', 'open')
                ->assertViewHas('days', fn ($days) => $days->pluck('id')->all() === [$latest->id])
                ->assertViewHas('dayNumbers', fn ($numbers) => $numbers[$latest->id] === 3);
            $component->set('statusFilter', 'all')->set('search', '2026-09-27')
                ->assertViewHas('days', fn ($days) => $days->pluck('id')->all() === [$middle->id])
                ->assertViewHas('dayNumbers', fn ($numbers) => $numbers[$middle->id] === 2);
        }
    }

    public function test_adding_students_preserves_attendance_identity_when_the_roster_reorders(): void
    {
        $this->signIn();
        $group = $this->group();
        $present = AttendanceStatus::create(['name' => 'Present', 'code' => 'present', 'scope' => 'student', 'is_present' => true, 'is_default' => true, 'is_active' => true]);
        $absent = AttendanceStatus::create(['name' => 'Absent', 'code' => 'absent', 'scope' => 'student', 'is_present' => false, 'is_active' => true]);
        $students = collect(['Ziad', 'Ahmad', 'Omar'])->map(fn ($name) => Student::create([
            'first_name' => $name, 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active',
        ]));
        $existing = Enrollment::create(['student_id' => $students[0]->id, 'group_id' => $group->id, 'status' => 'active', 'enrolled_at' => '2026-09-26']);
        $day = app(StudentAttendanceDayService::class)->createOrSyncDay('2026-09-26', collect([$group]));
        $groupDay = $day->groupAttendanceDays()->firstOrFail();
        $component = Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay])
            ->set('selected_statuses.'.$existing->id, $absent->id)
            ->call('saveEnrollmentStatus', $existing->id)->assertHasNoErrors();

        $rows = [$existing];
        foreach ($students->slice(1) as $student) {
            $component->call('openAddStudentModal')
                ->set('rosterStudentId', $student->id)->call('addStudent', true)
                ->assertHasNoErrors()->assertSet('showAddStudentModal', true)
                ->assertSet('selected_statuses.'.$existing->id, $absent->id);
            $added = Enrollment::where('student_id', $student->id)->firstOrFail();
            $rows[] = $added;
            foreach ($rows as $enrollment) {
                $component->assertSee('wire:key="attendance-enrollment-'.$groupDay->id.'-'.$enrollment->id.'"', false)
                    ->assertSee('wire:key="attendance-status-'.$groupDay->id.'-'.$enrollment->id.'"', false);
            }
            $component->assertSet('selected_statuses.'.$added->id, $present->id);
        }

        $component->call('closeAddStudentModal')
            ->assertSeeInOrder(['Ahmad Student', 'Omar Student', 'Ziad Student'])
            ->set('selected_statuses.'.$added->id, $absent->id)
            ->call('saveEnrollmentStatus', $added->id)->assertHasNoErrors()
            ->assertSet('selected_statuses.'.$existing->id, $absent->id)
            ->assertSet('selected_statuses.'.$rows[1]->id, $present->id);
        $this->assertDatabaseHas('student_attendance_records', ['enrollment_id' => $added->id, 'group_attendance_day_id' => $groupDay->id, 'attendance_status_id' => $absent->id]);
    }

    public function test_attendance_enrollment_rechecks_eligibility_and_prevents_duplicates(): void
    {
        $this->signIn();
        $group = $this->group();
        $otherGroup = $this->group();
        $inactive = Student::create(['first_name' => 'Inactive', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'inactive']);
        $enrolled = Student::create(['first_name' => 'Enrolled', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $previous = Student::create(['first_name' => 'Previous', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $day = app(StudentAttendanceDayService::class)->createOrSyncDay('2026-09-26', collect([$group]));
        $component = Volt::test('student-attendance.mark', ['groupAttendanceDay' => $day->groupAttendanceDays()->firstOrFail()])->call('openAddStudentModal');
        // The student becomes unavailable after the popup has opened.
        Enrollment::create(['student_id' => $enrolled->id, 'group_id' => $otherGroup->id, 'status' => 'active', 'enrolled_at' => '2026-09-26']);
        Enrollment::create(['student_id' => $previous->id, 'group_id' => $group->id, 'status' => 'completed', 'enrolled_at' => '2026-01-01'])->delete();
        foreach ([$inactive, $enrolled, $previous] as $student) {
            $component->set('rosterStudentId', $student->id)->call('addStudent')->assertHasErrors('rosterStudentId');
        }
        $this->assertSame(0, $group->enrollments()->count());
    }

    public function test_attendance_enrollment_respects_permissions_and_closed_days_or_inactive_groups(): void
    {
        $this->signIn();
        $group = $this->group();
        $student = Student::create(['first_name' => 'Available', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $day = app(StudentAttendanceDayService::class)->createOrSyncDay('2026-09-26', collect([$group]));
        $component = Volt::test('student-attendance.mark', ['groupAttendanceDay' => $day->groupAttendanceDays()->firstOrFail()]);
        $group->update(['is_active' => false]);
        $component->set('rosterStudentId', $student->id)->call('addStudent')->assertHasErrors('rosterStudentId');
        $group->update(['is_active' => true]);
        $day->update(['status' => 'closed']);
        $component->call('addStudent')->assertHasErrors('rosterStudentId')->assertDontSee('data-attendance-add-student', false);
        $day->update(['status' => 'open']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('attendance.student.view');
        $this->actingAs($viewer);
        $component->call('addStudent')->assertForbidden();
        $this->assertSame(0, $group->enrollments()->count());
    }
}
