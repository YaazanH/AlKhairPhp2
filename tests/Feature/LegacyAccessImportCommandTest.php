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
