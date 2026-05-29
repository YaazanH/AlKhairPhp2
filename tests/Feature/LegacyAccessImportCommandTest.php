<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyAccessImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected array $tempDirectories = [];

    public function test_legacy_import_reuses_existing_parent_and_student_records(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'أحمد محمد',
            'is_active' => true,
        ]);

        Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'محمد',
            'last_name' => 'أحمد',
            'birth_date' => '2010-01-01',
            'status' => 'active',
        ]);

        $importPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'names.csv', [
            ['full_name', 'father_name', 'father_mob', 'home_tel', 'address', 'birth_date', 'school', 'active'],
            ['محمد أحمد', 'أحمد محمد', '', '', '', '2011-05-10', 'مدرسة أ', '1'],
            ['محمود أحمد', 'أحمد محمد', '', '', '', '2012-06-15', 'مدرسة أ', '1'],
        ]);
        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'teachers.csv', [
            ['id', 'names', 'job', 'blocked', 'password'],
        ]);
        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'courses_name.csv', [
            ['Courses_Name', 'Note', 'Date_Start', 'Date_Finsh', 'active'],
        ]);
        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'groups.csv', [
            ['Courses_Name', 'Group_Name', 'Teacher_Name', 'Assistant_Name', 'Age'],
        ]);
        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'courses.csv', [
            ['Full_name', 'Courses_Name', 'Group_Name', 'Date_Courses', 'Note'],
        ]);

        $this->artisan('legacy:import-access-core', ['path' => $importPath])
            ->assertExitCode(0);

        $this->assertSame(1, ParentProfile::query()->count());
        $this->assertSame(2, Student::query()->count());
        $this->assertSame(1, Student::query()
            ->where('first_name', 'محمد')
            ->where('last_name', 'أحمد')
            ->count());
        $this->assertSame($parent->id, Student::query()
            ->where('first_name', 'محمود')
            ->where('last_name', 'أحمد')
            ->value('parent_id'));
    }

    public function test_people_only_import_creates_minimal_parent_and_student_records(): void
    {
        $importPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'names.csv', [
            ['full_name', 'father_name', 'father_mob', 'home_tel', 'address', 'birth_date', 'school', 'grade', 'juz_no', 'image_link', 'notes', 'active'],
            ['محمد عبد الكريم الحسن', 'عبد الكريم الحسن', '00963944512429', '', 'دمشق', '2012-05-10', 'مدرسة النهضة', 'السادس', '6', 'student.jpg', 'legacy note', '1'],
        ]);

        $this->artisan('legacy:import-access-core', [
            'path' => $importPath,
            '--people-only' => true,
        ])->assertExitCode(0);

        $parent = ParentProfile::query()->sole();
        $student = Student::query()->sole();

        $this->assertSame('عبد الكريم الحسن', $parent->father_name);
        $this->assertSame('0944512429', $parent->father_phone);
        $this->assertNull($parent->father_work);
        $this->assertNull($parent->home_phone);
        $this->assertNull($parent->address);
        $this->assertNull($parent->notes);

        $this->assertSame('محمد عبد الكريم', $student->first_name);
        $this->assertSame('الحسن', $student->last_name);
        $this->assertSame('2012-01-01', $student->birth_date?->toDateString());
        $this->assertNull($student->school_name);
        $this->assertNull($student->grade_level_id);
        $this->assertNull($student->quran_current_juz_id);
        $this->assertNull($student->photo_path);
        $this->assertNull($student->notes);
    }

    public function test_people_only_import_appends_student_last_name_to_parent_when_missing(): void
    {
        $importPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'names.csv', [
            ['full_name', 'father_name', 'father_mob', 'home_tel', 'address', 'birth_date', 'active'],
            ['زين شموط', 'سامر', '00963931426341', '', '', '2015', '1'],
        ]);

        $this->artisan('legacy:import-access-core', [
            'path' => $importPath,
            '--people-only' => true,
        ])->assertExitCode(0);

        $this->assertSame('سامر شموط', ParentProfile::query()->value('father_name'));
    }

    public function test_people_only_import_does_not_duplicate_last_name_when_father_name_already_contains_it(): void
    {
        $importPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'names.csv', [
            ['full_name', 'father_name', 'father_mob', 'home_tel', 'address', 'birth_date', 'active'],
            ['محمد أحمد', 'أحمد محمد', '00963945057080', '', '', '2011', '1'],
        ]);

        $this->artisan('legacy:import-access-core', [
            'path' => $importPath,
            '--people-only' => true,
        ])->assertExitCode(0);

        $this->assertSame('أحمد محمد', ParentProfile::query()->value('father_name'));
    }

    public function test_people_only_import_keeps_different_families_separate_and_groups_siblings(): void
    {
        $importPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'names.csv', [
            ['full_name', 'father_name', 'father_mob', 'home_tel', 'address', 'birth_date', 'active'],
            ['محمد علي الحسن', 'علي محمد', '', '', '', '2011', '1'],
            ['حسين علي الحسن', 'علي محمد', '', '', '', '2013', '1'],
            ['عمر علي الصالح', 'علي محمد', '', '', '', '2012', '1'],
        ]);

        $this->artisan('legacy:import-access-core', [
            'path' => $importPath,
            '--people-only' => true,
        ])->assertExitCode(0);

        $this->assertSame(2, ParentProfile::query()->count());
        $this->assertSame(3, Student::query()->count());

        $sharedParentId = Student::query()
            ->where('first_name', 'محمد علي')
            ->where('last_name', 'الحسن')
            ->value('parent_id');

        $this->assertSame($sharedParentId, Student::query()
            ->where('first_name', 'حسين علي')
            ->where('last_name', 'الحسن')
            ->value('parent_id'));

        $this->assertNotSame($sharedParentId, Student::query()
            ->where('first_name', 'عمر علي')
            ->where('last_name', 'الصالح')
            ->value('parent_id'));
    }

    public function test_backfill_parent_family_names_updates_old_imported_parents(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'سامر',
            'is_active' => true,
        ]);

        Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'زين',
            'last_name' => 'شموط',
            'birth_date' => '2015-01-01',
            'status' => 'active',
        ]);

        Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'محمد',
            'last_name' => 'شموط',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        $this->artisan('legacy:backfill-parent-family-names')
            ->assertExitCode(0);

        $this->assertSame('سامر شموط', $parent->fresh()->father_name);
    }

    public function test_legacy_memorization_analyzer_builds_reports_without_writing_data(): void
    {
        $academicYear = AcademicYear::create([
            'name' => '2026 / 2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'ولي الأمر',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'محمد',
            'last_name' => 'أحمد',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'معلّم',
            'last_name' => 'أول',
            'phone' => '0999000001',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'دورة الربيع',
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'مجموعة الربيع',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-01-01',
            'status' => 'active',
        ]);

        $existingSession = MemorizationSession::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-04-01',
            'entry_type' => 'new',
            'from_page' => 15,
            'to_page' => 15,
            'pages_count' => 1,
        ]);

        StudentPageAchievement::create([
            'student_id' => $student->id,
            'page_no' => 15,
            'first_enrollment_id' => $enrollment->id,
            'first_session_id' => $existingSession->id,
            'first_recorded_on' => '2026-04-01',
        ]);

        $importPath = $this->makeImportFolder();
        $reportPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'entre.csv', [
            ['record_no', 'full_name', 'page_no', 'listen_date', 'listener_name', 'Courses_Name'],
            ['1', 'محمد أحمد', '10', '2026-05-01', 'معلّم أول', 'دورة الربيع'],
            ['2', 'محمد أحمد', '11', '2026-05-01', 'معلّم أول', 'دورة الربيع'],
            ['3', 'محمد أحمد', '13', '2026-05-01', 'معلّم أول', 'دورة الربيع'],
            ['4', 'محمد أحمد', '13', '2026-05-01', 'معلّم أول', 'دورة الربيع'],
            ['5', 'محمد أحمد', '15', '2026-05-01', 'معلّم أول', 'دورة الربيع'],
            ['6', 'طالب مفقود', '16', '2026-05-01', 'معلّم أول', 'دورة الربيع'],
            ['7', 'محمد أحمد', '17', '2026-05-01', 'مدرس مفقود', 'دورة الربيع'],
            ['8', 'محمد أحمد', '18', 'not-a-date', 'معلّم أول', 'دورة الربيع'],
            ['9', 'محمد أحمد', '19', '2026-05-01', 'معلّم أول', 'دورة أخرى'],
        ]);

        $this->artisan('legacy:analyze-memorization-entre', [
            'path' => $importPath,
            '--report-dir' => $reportPath,
        ])
            ->expectsOutputToContain('Analysis source:')
            ->expectsOutputToContain('Legacy rows')
            ->expectsOutputToContain('Importable page rows')
            ->expectsOutputToContain('Proposed sessions')
            ->expectsOutputToContain('Reports written to:')
            ->assertExitCode(0);

        $this->assertSame(1, StudentPageAchievement::query()->count());
        $this->assertSame(1, MemorizationSession::query()->count());

        $proposedSessions = $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'proposed_sessions.csv');
        $this->assertCount(2, $proposedSessions);
        $this->assertSame('10', $proposedSessions[0]['from_page']);
        $this->assertSame('11', $proposedSessions[0]['to_page']);
        $this->assertSame('2', $proposedSessions[0]['pages_count']);
        $this->assertSame('1,2', $proposedSessions[0]['source_record_nos']);
        $this->assertSame('13', $proposedSessions[1]['from_page']);
        $this->assertSame('13', $proposedSessions[1]['to_page']);
        $this->assertSame('1', $proposedSessions[1]['pages_count']);
        $this->assertSame('3', $proposedSessions[1]['source_record_nos']);

        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'duplicate_legacy_rows.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'unmatched_students.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'unmatched_teachers.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'invalid_rows.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'unresolved_enrollments.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'overlapping_live_pages.csv'));
    }

    public function test_legacy_memorization_import_uses_shared_placeholder_group_without_creating_points(): void
    {
        $academicYear = AcademicYear::create([
            'name' => '2026 / 2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'ولي الأمر الأول',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'محمد',
            'last_name' => 'أحمد',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $secondParent = ParentProfile::create([
            'father_name' => 'ولي الأمر الثاني',
            'is_active' => true,
        ]);

        $secondStudent = Student::create([
            'parent_id' => $secondParent->id,
            'first_name' => 'سارة',
            'last_name' => 'علي',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'المعلم',
            'last_name' => 'الحالي',
            'phone' => '0999000009',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'الدورة الحالية',
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'المجموعة الحالية',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $currentEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-01-01',
            'status' => 'active',
        ]);

        $existingSession = MemorizationSession::create([
            'enrollment_id' => $currentEnrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-04-01',
            'entry_type' => 'new',
            'from_page' => 10,
            'to_page' => 10,
            'pages_count' => 1,
        ]);

        $existingSession->pages()->create([
            'page_no' => 10,
        ]);

        StudentPageAchievement::create([
            'student_id' => $student->id,
            'page_no' => 10,
            'first_enrollment_id' => $currentEnrollment->id,
            'first_session_id' => $existingSession->id,
            'first_recorded_on' => '2026-04-01',
        ]);

        $importPath = $this->makeImportFolder();
        $reportPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'entre.csv', [
            ['record_no', 'full_name', 'page_no', 'listen_date', 'listener_name', 'Courses_Name'],
            ['1', 'محمد أحمد', '10', '2026-01-01', 'قديم', 'قديم'],
            ['2', 'محمد أحمد', '11', '2026-01-01', 'قديم', 'قديم'],
            ['3', 'محمد أحمد', '12', '2026-01-01', 'قديم', 'قديم'],
            ['4', 'محمد أحمد', '12', '2026-02-01', 'قديم', 'قديم'],
            ['5', 'سارة علي', '21', '2026-03-05', 'قديم', 'قديم'],
            ['6', 'طالب مفقود', '31', '2026-03-05', 'قديم', 'قديم'],
            ['7', 'محمد أحمد', '50', 'bad-date', 'قديم', 'قديم'],
        ]);

        $this->artisan('legacy:import-memorization-entre', [
            'path' => $importPath,
            '--report-dir' => $reportPath,
        ])
            ->expectsOutputToContain('Legacy rows')
            ->expectsOutputToContain('Imported sessions')
            ->expectsOutputToContain('Affected students')
            ->assertExitCode(0);

        $importTeacher = Teacher::query()
            ->where('first_name', 'Import')
            ->where('last_name', 'Teacher')
            ->firstOrFail();

        $importCourse = Course::query()->where('name', 'Import Course')->firstOrFail();
        $importGroup = Group::query()->where('name', 'Import Group')->firstOrFail();
        $legacyYear = AcademicYear::query()->where('name', 'Legacy Import')->firstOrFail();

        $this->assertFalse($importCourse->is_active);
        $this->assertFalse($importGroup->is_active);
        $this->assertFalse($legacyYear->is_active);
        $this->assertSame($importTeacher->id, $importGroup->teacher_id);
        $this->assertSame($importCourse->id, $importGroup->course_id);
        $this->assertSame($legacyYear->id, $importGroup->academic_year_id);

        $legacyEnrollments = Enrollment::query()
            ->where('group_id', $importGroup->id)
            ->orderBy('student_id')
            ->get();

        $this->assertCount(2, $legacyEnrollments);
        $this->assertSame(['inactive', 'inactive'], $legacyEnrollments->pluck('status')->all());

        $this->assertTrue(MemorizationSession::query()
            ->where('enrollment_id', $legacyEnrollments->firstWhere('student_id', $student->id)?->id)
            ->where('student_id', $student->id)
            ->where('teacher_id', $importTeacher->id)
            ->whereDate('recorded_on', '2026-01-01')
            ->where('from_page', 11)
            ->where('to_page', 12)
            ->where('pages_count', 2)
            ->exists());

        $this->assertTrue(MemorizationSession::query()
            ->where('enrollment_id', $legacyEnrollments->firstWhere('student_id', $secondStudent->id)?->id)
            ->where('student_id', $secondStudent->id)
            ->where('teacher_id', $importTeacher->id)
            ->whereDate('recorded_on', '2026-03-05')
            ->where('from_page', 21)
            ->where('to_page', 21)
            ->where('pages_count', 1)
            ->exists());

        $this->assertSame(0, PointTransaction::query()->count());
        $this->assertSame(3, StudentPageAchievement::query()->where('student_id', $student->id)->count());
        $this->assertSame(1, StudentPageAchievement::query()->where('student_id', $secondStudent->id)->count());
        $this->assertSame(1, $currentEnrollment->fresh()->memorized_pages_cached);
        $this->assertSame(2, $legacyEnrollments->firstWhere('student_id', $student->id)?->fresh()->memorized_pages_cached);

        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'skipped_existing_pages.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'skipped_repeated_legacy_pages.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'unmatched_students.csv'));
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'invalid_rows.csv'));
        $this->assertCount(2, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'imported_sessions.csv'));
    }

    public function test_legacy_quran_ajza_import_creates_passed_partial_and_final_tests_without_points(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'وليد عمران',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'رامي',
            'last_name' => 'عمران',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $juz = QuranJuz::create([
            'juz_number' => 27,
            'from_page' => 522,
            'to_page' => 541,
        ]);

        $importPath = $this->makeImportFolder();
        $reportPath = $this->makeImportFolder();

        $this->writeCsv($importPath.DIRECTORY_SEPARATOR.'ajza.csv', [
            ['record_no', 'student_name', 'juz_number', 'listener_name', 'tested_on', 'evaluation', 'course_name', 'score', 'awqaf_exam_name', 'awqaf_exam_result'],
            ['2014', 'رامي عمران', '27', 'مروان مراد', '2025-09-01', 'جيد جداً', 'دورة صيف 2025 س', '90', '', ''],
        ]);

        $this->artisan('legacy:import-quran-ajza', [
            'path' => $importPath,
            '--report-dir' => $reportPath,
        ])
            ->expectsOutputToContain('Imported partial tests')
            ->expectsOutputToContain('Imported final tests')
            ->expectsOutputToContain('Affected students')
            ->assertExitCode(0);

        $importTeacher = Teacher::query()
            ->where('first_name', 'Import')
            ->where('last_name', 'Teacher')
            ->firstOrFail();

        $importCourse = Course::query()->where('name', 'Import Course')->firstOrFail();
        $importGroup = Group::query()->where('name', 'Import Group')->firstOrFail();
        $legacyYear = AcademicYear::query()->where('name', 'Legacy Import')->firstOrFail();

        $this->assertFalse($importCourse->is_active);
        $this->assertFalse($importGroup->is_active);
        $this->assertFalse($legacyYear->is_active);
        $this->assertSame($importTeacher->id, $importGroup->teacher_id);

        $legacyEnrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('group_id', $importGroup->id)
            ->firstOrFail();

        $this->assertSame('inactive', $legacyEnrollment->status);

        $partialTest = QuranPartialTest::query()
            ->with('parts.attempts')
            ->where('student_id', $student->id)
            ->where('juz_id', $juz->id)
            ->sole();

        $this->assertSame($legacyEnrollment->id, $partialTest->enrollment_id);
        $this->assertSame('passed', $partialTest->status);
        $this->assertSame('2025-09-01', $partialTest->passed_on?->toDateString());
        $this->assertCount(4, $partialTest->parts);

        foreach ($partialTest->parts as $part) {
            $this->assertSame('passed', $part->status);
            $this->assertSame('2025-09-01', $part->passed_on?->toDateString());
            $this->assertCount(1, $part->attempts);
            $this->assertSame(0, $part->attempts->first()->mistake_count);
            $this->assertSame('passed', $part->attempts->first()->status);
            $this->assertSame($importTeacher->id, $part->attempts->first()->teacher_id);
        }

        $finalTest = QuranFinalTest::query()
            ->with('attempts')
            ->where('student_id', $student->id)
            ->where('juz_id', $juz->id)
            ->sole();

        $this->assertSame($legacyEnrollment->id, $finalTest->enrollment_id);
        $this->assertSame('passed', $finalTest->status);
        $this->assertSame('2025-09-01', $finalTest->passed_on?->toDateString());
        $this->assertCount(1, $finalTest->attempts);
        $this->assertSame('passed', $finalTest->attempts->first()->status);
        $this->assertSame('90.00', number_format((float) $finalTest->attempts->first()->score, 2, '.', ''));
        $this->assertSame($importTeacher->id, $finalTest->attempts->first()->teacher_id);

        $this->assertSame(0, PointTransaction::query()->count());
        $this->assertCount(1, $this->readCsvRecords($reportPath.DIRECTORY_SEPARATOR.'imported_tests.csv'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($directory);
        }

        parent::tearDown();
    }

    protected function makeImportFolder(): string
    {
        $directory = storage_path('app/testing-legacy-import-'.Str::random(10));

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function readCsvRecords(string $path): array
    {
        $handle = fopen($path, 'rb');
        $headers = fgetcsv($handle) ?: [];
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $rows[] = array_combine($headers, $data);
        }

        fclose($handle);

        return $rows;
    }
}
