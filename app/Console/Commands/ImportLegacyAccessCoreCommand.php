<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportLegacyAccessCoreCommand extends Command
{
    protected $signature = 'legacy:import-access-core
        {path=storage/app/legacy-access-export : Folder containing exported CSV files}
        {--dry-run : Roll back the import after validation}
        {--people-only : Import only parents and students}';

    protected $description = 'Import core legacy Access data (teachers, students, parents, courses, groups, enrolments) from exported CSV files.';

    /**
     * @var array<string, int>
     */
    protected array $summary = [
        'courses' => 0,
        'teachers' => 0,
        'placeholder_teachers' => 0,
        'inactive_teachers' => 0,
        'parents' => 0,
        'inactive_parents' => 0,
        'students' => 0,
        'inactive_students' => 0,
        'groups' => 0,
        'enrollments' => 0,
    ];

    /**
     * @var array<string, list<string>>
     */
    protected array $warnings = [
        'missing_father_name' => [],
        'missing_birth_date' => [],
        'missing_group_teacher' => [],
        'missing_group_assistant' => [],
        'missing_student_for_enrollment' => [],
        'missing_group_for_enrollment' => [],
    ];

    /**
     * @var array<string, Teacher>
     */
    protected array $teachersByLegacyName = [];

    /**
     * @var array<string, GradeLevel>
     */
    protected array $gradeLevelsByName = [];

    /**
     * @var array<string, ParentProfile>
     */
    protected array $parentsByKey = [];

    /**
     * @var array<string, Student>
     */
    protected array $studentsByFullName = [];

    /**
     * @var array<string, Student>
     */
    protected array $studentsByParentAndFullName = [];

    /**
     * @var array<string, Group>
     */
    protected array $groupsByKey = [];

    /**
     * @var array<string, Course>
     */
    protected array $coursesByName = [];

    public function handle(): int
    {
        $path = $this->resolveImportPath((string) $this->argument('path'));
        $this->info("Import source: {$path}");
        $peopleOnly = (bool) $this->option('people-only');

        $files = [
            'names' => $path.DIRECTORY_SEPARATOR.'names.csv',
        ];

        if (! $peopleOnly) {
            $files['teachers'] = $path.DIRECTORY_SEPARATOR.'teachers.csv';
            $files['courses_name'] = $path.DIRECTORY_SEPARATOR.'courses_name.csv';
            $files['groups'] = $path.DIRECTORY_SEPARATOR.'groups.csv';
            $files['courses'] = $path.DIRECTORY_SEPARATOR.'courses.csv';
        }

        foreach ($files as $label => $file) {
            if (! is_file($file)) {
                $this->error("Missing required file for {$label}: {$file}");

                return self::FAILURE;
            }
        }

        $studentRows = $this->readCsv($files['names']);
        $teacherRows = $peopleOnly ? [] : $this->readCsv($files['teachers']);
        $courseRows = $peopleOnly ? [] : $this->readCsv($files['courses_name']);
        $groupRows = $peopleOnly ? [] : $this->readCsv($files['groups']);
        $enrollmentRows = $peopleOnly ? [] : $this->readCsv($files['courses']);

        DB::beginTransaction();

        try {
            $this->bootstrapExistingParentsAndStudents();
            $this->importParentsAndStudents($studentRows);

            if (! $peopleOnly) {
                $legacyAcademicYear = $this->resolveLegacyAcademicYear($courseRows);
                $this->importCourses($courseRows);
                $this->importTeachers($teacherRows);
                $this->importGroups($groupRows, $legacyAcademicYear);
                $this->importEnrollments($enrollmentRows);
            }

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Dry run enabled: transaction rolled back.');
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $this->table(
            ['Entity', 'Imported'],
            [
                ['Courses', $this->summary['courses']],
                ['Teachers', $this->summary['teachers']],
                ['Placeholder teachers', $this->summary['placeholder_teachers']],
                ['Inactive teachers', $this->summary['inactive_teachers']],
                ['Parents', $this->summary['parents']],
                ['Inactive parents', $this->summary['inactive_parents']],
                ['Students', $this->summary['students']],
                ['Inactive students', $this->summary['inactive_students']],
                ['Groups', $this->summary['groups']],
                ['Enrolments', $this->summary['enrollments']],
            ],
        );

        foreach ($this->warnings as $type => $items) {
            if ($items === []) {
                continue;
            }

            $this->warn($type.': '.count($items));

            foreach (array_slice(array_unique($items), 0, 10) as $item) {
                $this->line(' - '.$item);
            }
        }

        return self::SUCCESS;
    }

    protected function importCourses(array $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->cleanString($row['Courses_Name'] ?? null);

            if ($name === null) {
                continue;
            }

            $course = Course::withTrashed()->firstOrNew(['name' => $name]);
            $course->description = $this->cleanString($row['Note'] ?? null);
            $course->starts_on = $this->parseDate($row['Date_Start'] ?? null);
            $course->ends_on = $this->parseDate($row['Date_Finsh'] ?? null);
            $course->is_active = $this->parseBool($row['active'] ?? true, true);
            $course->deleted_at = null;
            $course->save();

            $this->coursesByName[$name] = $course;
            $this->summary['courses']++;
        }
    }

    protected function importTeachers(array $rows): void
    {
        foreach ($rows as $row) {
            $fullName = $this->cleanString($row['names'] ?? null);

            if ($fullName === null) {
                continue;
            }

            $jobTitleName = $this->cleanString($row['job'] ?? null) ?? 'Legacy Teacher';

            [$firstName, $lastName] = $this->splitName($fullName);
            $requiredFieldReviewNeeded = true;

            $teacher = Teacher::withTrashed()->firstOrNew([
                'phone' => $this->legacyTeacherPhone((string) ($row['id'] ?? $fullName)),
            ]);

            $teacher->first_name = $firstName;
            $teacher->last_name = $lastName;
            $teacher->job_title = $jobTitleName;
            $teacher->teacher_job_title_id = null;
            $teacher->status = $requiredFieldReviewNeeded || $this->parseBool($row['blocked'] ?? false, false)
                ? 'inactive'
                : 'active';
            $teacher->is_helping = str_contains($jobTitleName, 'مسمع') || str_contains(Str::lower($jobTitleName), 'assistant');
            $teacher->notes = $this->appendLegacyNote(
                $this->cleanString($row['password'] ?? null),
                'legacy_teacher_id',
                (string) ($row['id'] ?? ''),
            );
            $teacher->notes = $this->appendLegacyNote(
                $teacher->notes,
                'legacy_review_reason',
                'missing_required_phone',
            );
            $teacher->deleted_at = null;
            $teacher->save();

            $this->teachersByLegacyName[$fullName] = $teacher;
            $this->summary['teachers']++;

            if ($teacher->status === 'inactive') {
                $this->summary['inactive_teachers']++;
            }
        }
    }

    protected function importParentsAndStudents(array $rows): void
    {
        $defaultGender = DB::table('student_genders')
            ->where('is_default', true)
            ->value('code') ?? 'male';
        $minimalPeopleImport = (bool) $this->option('people-only');

        foreach ($rows as $row) {
            $fullName = $this->cleanString($row['full_name'] ?? null);

            if ($fullName === null) {
                continue;
            }

            $fatherName = $this->cleanString($row['father_name'] ?? null);
            $fatherPhone = $this->normalizePhone($row['father_mob'] ?? null);
            $homePhone = $this->normalizePhone($row['home_tel'] ?? null);
            $primaryPhone = $fatherPhone ?: $homePhone;
            $address = $this->cleanString($row['address'] ?? null);
            $parentNeedsReview = false;

            if ($fatherName === null) {
                $fatherName = 'ولي أمر '.$fullName;
                $this->warnings['missing_father_name'][] = $fullName;
                $parentNeedsReview = true;
            }

            $parentDisplayName = $this->buildImportedFatherName($fatherName, $fullName) ?? $fatherName;
            $parent = $this->findParentForImport($fatherName, $fatherPhone, $homePhone, $address, $fullName);

            $isNewParent = ! $parent;

            if (! $parent) {
                $parent = new ParentProfile;
                $this->summary['parents']++;
            }

            $legacyParentStatus = $parentNeedsReview ? false : $this->parseBool($row['active'] ?? true, true);

            $parent->father_name = $parent->father_name ?: $parentDisplayName;
            $parent->father_phone = $parent->father_phone ?: $primaryPhone;

            if (! $minimalPeopleImport) {
                $existingParentNotes = $parent->notes;
                $parent->father_work = $parent->father_work ?: $this->cleanString($row['father_job'] ?? null);
                $parent->home_phone = $parent->home_phone ?: $homePhone;
                $parent->address = $parent->address ?: $address;
                $parent->notes = $this->appendLegacyNote(
                    $existingParentNotes ?: $this->cleanString($row['notes'] ?? null),
                    'legacy_review_reason',
                    $parentNeedsReview ? 'missing_required_father_name' : '',
                );
            }

            $parent->is_active = $parent->exists
                ? ($parent->is_active || $legacyParentStatus)
                : $legacyParentStatus;
            $parent->deleted_at = null;
            $parent->save();
            $this->cacheParent($parent, $fullName);

            if ($isNewParent && ! $parent->is_active) {
                $this->summary['inactive_parents']++;
            }

            $birthDate = $minimalPeopleImport
                ? $this->parseBirthYearDate($row['birth_date'] ?? null)
                : $this->parseBirthDate($row['birth_date'] ?? null);
            $studentNeedsReview = false;

            if ($birthDate === null) {
                $birthDate = '2000-01-01';
                $this->warnings['missing_birth_date'][] = $fullName;
                $studentNeedsReview = true;
            }

            [$firstName, $lastName] = $this->splitName($fullName);
            $student = $this->findStudentForImport($fullName, $parent);
            $isNewStudent = ! $student;

            if (! $student) {
                $student = new Student;
                $this->summary['students']++;
            }

            $legacyStudentStatus = $studentNeedsReview || ! $parent->is_active
                ? 'inactive'
                : ($this->parseBool($row['active'] ?? true, true) ? 'active' : 'inactive');

            $student->parent_id = $parent->id;
            $student->first_name = $firstName;
            $student->last_name = $lastName;
            $student->birth_date = $student->birth_date ?: $birthDate;
            $student->gender = $student->gender ?: $defaultGender;
            $student->status = $student->exists && $student->status === 'active'
                ? 'active'
                : $legacyStudentStatus;
            $student->joined_at = $student->joined_at;

            if (! $minimalPeopleImport) {
                $gradeLevelId = $this->resolveGradeLevelId($this->cleanString($row['grade'] ?? null));
                $juzNumber = $this->parseInteger($row['juz_no'] ?? null);
                $juzId = $juzNumber
                    ? QuranJuz::query()->where('juz_number', $juzNumber)->value('id')
                    : null;

                $student->school_name = $this->cleanString($row['school'] ?? null) ?: $student->school_name;
                $student->grade_level_id = $gradeLevelId ?: $student->grade_level_id;
                $student->quran_current_juz_id = $juzId ?: $student->quran_current_juz_id;
                $student->photo_path = $this->cleanString($row['image_link'] ?? null) ?: $student->photo_path;
                $student->notes = $this->appendLegacyNote(
                    $student->notes ?: $this->buildStudentNotes($row),
                    'legacy_review_reason',
                    $studentNeedsReview ? 'missing_required_birth_date' : (! $parent->is_active ? 'inactive_parent_record' : ''),
                );
            }

            $student->deleted_at = null;
            $student->save();
            $this->cacheStudent($student);

            if ($isNewStudent && $student->status === 'inactive') {
                $this->summary['inactive_students']++;
            }
        }
    }

    protected function importGroups(array $rows, AcademicYear $academicYear): void
    {
        foreach ($rows as $row) {
            $courseName = $this->cleanString($row['Courses_Name'] ?? null);
            $groupName = $this->cleanString($row['Group_Name'] ?? null);

            if ($courseName === null || $groupName === null) {
                continue;
            }

            $course = $this->coursesByName[$courseName] ?? null;

            if (! $course) {
                continue;
            }

            $teacherName = $this->cleanString($row['Teacher_Name'] ?? null);
            $assistantName = $this->cleanString($row['Assistant_Name'] ?? null);

            $teacher = $teacherName
                ? $this->findOrCreateTeacherByName($teacherName, false, 'missing_group_teacher')
                : null;
            $assistant = $assistantName
                ? $this->findOrCreateTeacherByName($assistantName, true, 'missing_group_assistant')
                : null;

            if (! $teacher) {
                continue;
            }

            $group = Group::withTrashed()->firstOrNew([
                'academic_year_id' => $academicYear->id,
                'name' => $groupName,
            ]);

            $group->course_id = $course->id;
            $group->teacher_id = $teacher->id;
            $group->assistant_teacher_id = $assistant?->id;
            $group->grade_level_id = $this->resolveGradeLevelId($this->cleanString($row['Age'] ?? null));
            $group->capacity = 0;
            $group->starts_on = $course->starts_on;
            $group->ends_on = $course->ends_on;
            $group->monthly_fee = null;
            $group->is_active = (bool) $course->is_active;
            $group->deleted_at = null;
            $group->save();

            $groupKey = $this->buildGroupKey($courseName, $groupName);
            $this->groupsByKey[$groupKey] = $group;
            $this->summary['groups']++;
        }
    }

    protected function importEnrollments(array $rows): void
    {
        foreach ($rows as $row) {
            $fullName = $this->cleanString($row['Full_name'] ?? null);
            $courseName = $this->cleanString($row['Courses_Name'] ?? null);
            $groupName = $this->cleanString($row['Group_Name'] ?? null);

            if ($fullName === null || $courseName === null || $groupName === null) {
                continue;
            }

            $student = $this->studentsByFullName[$this->normalizeLookupValue($fullName) ?? ''] ?? null;

            if (! $student) {
                $this->warnings['missing_student_for_enrollment'][] = $fullName;

                continue;
            }

            $group = $this->groupsByKey[$this->buildGroupKey($courseName, $groupName)] ?? null;

            if (! $group) {
                $this->warnings['missing_group_for_enrollment'][] = "{$fullName} -> {$courseName} / {$groupName}";

                continue;
            }

            $enrolledAt = $this->parseDate($row['Date_Courses'] ?? null)
                ?? $group->starts_on?->toDateString()
                ?? $group->course?->starts_on?->toDateString()
                ?? now()->toDateString();

            Enrollment::query()->firstOrCreate(
                [
                    'student_id' => $student->id,
                    'group_id' => $group->id,
                    'enrolled_at' => $enrolledAt,
                ],
                [
                    'status' => 'active',
                    'left_at' => null,
                    'notes' => $this->cleanString($row['Note'] ?? null),
                ],
            );

            if ($student->joined_at === null || $student->joined_at->gt($enrolledAt)) {
                $student->forceFill(['joined_at' => $enrolledAt])->saveQuietly();
            }

            $this->summary['enrollments']++;
        }
    }

    protected function resolveLegacyAcademicYear(array $courseRows): AcademicYear
    {
        $starts = [];
        $ends = [];

        foreach ($courseRows as $row) {
            $start = $this->parseDate($row['Date_Start'] ?? null);
            $end = $this->parseDate($row['Date_Finsh'] ?? null);

            if ($start) {
                $starts[] = $start;
            }

            if ($end) {
                $ends[] = $end;
            }
        }

        $startsOn = $starts !== [] ? collect($starts)->sort()->first() : '2022-01-01';
        $endsOn = $ends !== [] ? collect($ends)->sort()->last() : now()->toDateString();

        return AcademicYear::query()->updateOrCreate(
            ['name' => 'Legacy Import'],
            [
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'is_current' => false,
                'is_active' => true,
            ],
        );
    }

    protected function findOrCreateTeacherByName(string $fullName, bool $isHelping, string $warningBucket): ?Teacher
    {
        if (isset($this->teachersByLegacyName[$fullName])) {
            return $this->teachersByLegacyName[$fullName];
        }

        [$firstName, $lastName] = $this->splitName($fullName);
        $jobTitleName = $isHelping ? 'Assistant Teacher' : 'Quran Teacher';

        $teacher = Teacher::withTrashed()->firstOrNew([
            'phone' => $this->legacyTeacherPhone('name-'.md5($fullName)),
        ]);

        $teacher->first_name = $firstName;
        $teacher->last_name = $lastName;
        $teacher->job_title = $jobTitleName;
        $teacher->teacher_job_title_id = null;
        $teacher->status = 'inactive';
        $teacher->is_helping = $isHelping;
        $teacher->notes = $this->appendLegacyNote(
            $teacher->notes,
            'legacy_placeholder_for',
            $fullName,
        );
        $teacher->notes = $this->appendLegacyNote(
            $teacher->notes,
            'legacy_review_reason',
            'missing_required_teacher_master_record',
        );
        $teacher->deleted_at = null;
        $teacher->save();

        $this->teachersByLegacyName[$fullName] = $teacher;
        $this->summary['placeholder_teachers']++;
        $this->summary['inactive_teachers']++;
        $this->warnings[$warningBucket][] = $fullName;

        return $teacher;
    }

    protected function resolveGradeLevelId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        if (isset($this->gradeLevelsByName[$name])) {
            return $this->gradeLevelsByName[$name]->id;
        }

        $gradeLevel = GradeLevel::query()->firstOrCreate(
            ['name' => $name],
            [
                'sort_order' => (GradeLevel::max('sort_order') ?? 0) + 10,
                'is_active' => true,
            ],
        );

        $this->gradeLevelsByName[$name] = $gradeLevel;

        return $gradeLevel->id;
    }

    protected function buildStudentNotes(array $row): ?string
    {
        $parts = [];

        foreach ([
            'student_id' => 'legacy_student_id',
            'kher_id' => 'legacy_kher_id',
            'transportation' => 'legacy_transportation',
            'previous_memories' => 'legacy_previous_memories',
            'notes' => 'legacy_notes',
        ] as $field => $label) {
            $value = $this->cleanString($row[$field] ?? null);

            if ($value !== null) {
                $parts[] = "{$label}: {$value}";
            }
        }

        return $parts === [] ? null : implode(PHP_EOL, $parts);
    }

    protected function appendLegacyNote(?string $base, string $key, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $base === null ? '' : trim($base);
        }

        $line = "{$key}: {$value}";

        return trim(($base ? $base.PHP_EOL : '').$line);
    }

    protected function buildGroupKey(string $courseName, string $groupName): string
    {
        return $this->normalizeLookupValue($courseName).'|'.$this->normalizeLookupValue($groupName);
    }

    protected function legacyTeacherPhone(string $legacyKey): string
    {
        return 'legacy-'.substr(preg_replace('/[^A-Za-z0-9]/', '', $legacyKey) ?: md5($legacyKey), 0, 22);
    }

    protected function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];

        if (count($parts) <= 1) {
            return [$fullName, $fullName];
        }

        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);

        return [$firstName, $lastName];
    }

    protected function parseBirthDate(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}$/', $value) === 1) {
            return $value.'-01-01';
        }

        return $this->parseDate($value);
    }

    protected function parseBirthYearDate(mixed $value): ?string
    {
        $parsedBirthDate = $this->parseBirthDate($value);

        if ($parsedBirthDate === null) {
            return null;
        }

        try {
            return Carbon::parse($parsedBirthDate)->startOfYear()->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function parseDate(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function parseBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = $this->cleanString($value);

        if ($value === null) {
            return $default;
        }

        return match (Str::lower($value)) {
            '1', 'true', 'yes', 'on', '-1' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    protected function parseInteger(mixed $value): ?int
    {
        $value = $this->cleanString($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function normalizePhone(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/u', '', $value);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        foreach (['00963', '963'] as $countryPrefix) {
            if (str_starts_with($normalized, $countryPrefix) && strlen($normalized) > strlen($countryPrefix)) {
                $normalized = '0'.substr($normalized, strlen($countryPrefix));
                break;
            }
        }

        return $normalized === '' ? null : $normalized;
    }

    protected function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV file: {$file}");
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $headers = array_map(function (string $header): string {
            return preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
        }, $headers);

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? (string) $data[$index] : '';
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    protected function resolveImportPath(string $path): string
    {
        $resolved = $path;

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $resolved = base_path($path);
        }

        $resolved = realpath($resolved) ?: $resolved;

        if (! is_dir($resolved)) {
            throw new \RuntimeException("Import path does not exist: {$resolved}");
        }

        return $resolved;
    }

    protected function bootstrapExistingParentsAndStudents(): void
    {
        ParentProfile::withTrashed()
            ->with(['students' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->chunkById(200, function ($parents): void {
                foreach ($parents as $parent) {
                    $this->cacheParent($parent);

                    foreach ($parent->students as $student) {
                        $this->cacheParent($parent, trim($student->first_name.' '.$student->last_name));
                        $this->cacheStudent($student);
                    }
                }
            });
    }

    protected function cacheParent(ParentProfile $parent, ?string $studentFullName = null): void
    {
        foreach ($this->parentLookupKeys($parent->father_name, $parent->father_phone, $parent->home_phone, $parent->address, $studentFullName) as $key) {
            $this->parentsByKey[$key] ??= $parent;
        }
    }

    protected function cacheStudent(Student $student): void
    {
        $fullName = trim($student->first_name.' '.$student->last_name);
        $fullNameKey = $this->normalizeLookupValue($fullName);

        if ($fullNameKey === null) {
            return;
        }

        $this->studentsByFullName[$fullNameKey] ??= $student;
        $this->studentsByParentAndFullName[$this->buildStudentParentKey($fullName, (int) $student->parent_id)] = $student;
    }

    protected function findParentForImport(?string $fatherName, ?string $fatherPhone, ?string $homePhone, ?string $address, string $studentFullName): ?ParentProfile
    {
        foreach ($this->parentLookupKeys($fatherName, $fatherPhone, $homePhone, $address, $studentFullName) as $key) {
            if (isset($this->parentsByKey[$key])) {
                return $this->parentsByKey[$key];
            }
        }

        return null;
    }

    protected function findStudentForImport(string $fullName, ParentProfile $parent): ?Student
    {
        $parentKey = $this->buildStudentParentKey($fullName, (int) $parent->id);

        if (isset($this->studentsByParentAndFullName[$parentKey])) {
            return $this->studentsByParentAndFullName[$parentKey];
        }

        $fullNameKey = $this->normalizeLookupValue($fullName);

        if ($fullNameKey === null) {
            return null;
        }

        $student = $this->studentsByFullName[$fullNameKey] ?? null;

        return $student && (int) $student->parent_id === (int) $parent->id
            ? $student
            : null;
    }

    /**
     * @return list<string>
     */
    protected function parentLookupKeys(?string $fatherName, ?string $fatherPhone, ?string $homePhone, ?string $address, ?string $studentFullName = null): array
    {
        $keys = [];
        $normalizedFatherNames = array_map(
            fn (string $name): string => (string) $this->normalizeLookupValue($name),
            $this->parentLookupFatherNames($fatherName, $studentFullName),
        );
        $normalizedAddress = $this->normalizeLookupValue($address);
        $normalizedStudentLastName = $this->normalizeLookupValue($this->extractLastName($studentFullName));

        foreach ($normalizedFatherNames as $normalizedFatherName) {
            if ($fatherPhone !== null) {
                $keys[] = 'father-phone|'.$normalizedFatherName.'|'.$fatherPhone;
            }

            if ($homePhone !== null) {
                $keys[] = 'father-home|'.$normalizedFatherName.'|'.$homePhone;
            }

            if ($normalizedAddress !== null) {
                $keys[] = 'father-address|'.$normalizedFatherName.'|'.$normalizedAddress;
            }

            if ($normalizedStudentLastName !== null) {
                $keys[] = 'father-student-last|'.$normalizedFatherName.'|'.$normalizedStudentLastName;
            }

            if ($normalizedStudentLastName === null) {
                $keys[] = 'father-name|'.$normalizedFatherName;
            }
        }

        return array_values(array_unique($keys));
    }

    protected function buildStudentParentKey(string $fullName, int $parentId): string
    {
        return $parentId.'|'.$this->normalizeLookupValue($fullName);
    }

    protected function normalizeLookupValue(?string $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    protected function extractLastName(?string $fullName): ?string
    {
        $fullName = $this->cleanString($fullName);

        if ($fullName === null) {
            return null;
        }

        [, $lastName] = $this->splitName($fullName);

        return $this->cleanString($lastName);
    }

    /**
     * @return list<string>
     */
    protected function parentLookupFatherNames(?string $fatherName, ?string $studentFullName = null): array
    {
        $fatherName = $this->cleanString($fatherName);

        if ($fatherName === null) {
            return [];
        }

        $names = [$fatherName];
        $studentLastName = $this->extractLastName($studentFullName);
        $expandedFatherName = $this->buildImportedFatherName($fatherName, $studentFullName);
        $baseFatherName = $this->stripTrailingFamilyName($fatherName, $studentLastName);

        if ($expandedFatherName !== null) {
            $names[] = $expandedFatherName;
        }

        if ($baseFatherName !== null) {
            $names[] = $baseFatherName;
        }

        return array_values(array_unique(array_filter($names)));
    }

    protected function buildImportedFatherName(?string $fatherName, ?string $studentFullName): ?string
    {
        $fatherName = $this->cleanString($fatherName);

        if ($fatherName === null) {
            return null;
        }

        $studentLastName = $this->extractLastName($studentFullName);

        if ($studentLastName === null) {
            return $fatherName;
        }

        if ($this->nameContainsPart($fatherName, $studentLastName)) {
            return $fatherName;
        }

        return trim($fatherName.' '.$studentLastName);
    }

    protected function stripTrailingFamilyName(?string $fatherName, ?string $familyName): ?string
    {
        $fatherName = $this->cleanString($fatherName);
        $familyName = $this->cleanString($familyName);

        if ($fatherName === null || $familyName === null) {
            return $fatherName;
        }

        $fatherParts = preg_split('/\s+/u', $fatherName) ?: [];
        $familyParts = preg_split('/\s+/u', $familyName) ?: [];

        if ($fatherParts === [] || $familyParts === [] || count($fatherParts) <= count($familyParts)) {
            return $fatherName;
        }

        $fatherSuffix = implode(' ', array_slice($fatherParts, -count($familyParts)));

        if ($this->normalizeLookupValue($fatherSuffix) !== $this->normalizeLookupValue($familyName)) {
            return $fatherName;
        }

        $baseFatherName = implode(' ', array_slice($fatherParts, 0, count($fatherParts) - count($familyParts)));

        return $this->cleanString($baseFatherName);
    }

    protected function nameContainsPart(?string $name, ?string $part): bool
    {
        $name = $this->cleanString($name);
        $part = $this->cleanString($part);

        if ($name === null || $part === null) {
            return false;
        }

        $nameParts = preg_split('/\s+/u', $name) ?: [];
        $normalizedPart = $this->normalizeLookupValue($part);

        foreach ($nameParts as $namePart) {
            if ($this->normalizeLookupValue($namePart) === $normalizedPart) {
                return true;
            }
        }

        return false;
    }
}
