<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\Student;
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
}
