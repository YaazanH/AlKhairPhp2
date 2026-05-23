<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Services\LegacyMemorizationImportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportLegacyMemorizationEntreCommand extends Command
{
    protected $signature = 'legacy:import-memorization-entre
        {path=storage/app/legacy-access-export : Folder containing exported CSV files}
        {--dry-run : Roll back the import after validation}
        {--report-dir= : Directory for CSV import reports}
        {--teacher-name=Import Teacher : Placeholder teacher name for imported legacy pages}
        {--course-name=Import Course : Placeholder course name for imported legacy pages}
        {--group-name=Import Group : Placeholder group name for imported legacy pages}
        {--academic-year-name=Legacy Import : Placeholder academic year name if one must be created}';

    protected $description = 'Import legacy memorization pages from entre.csv into one placeholder import course/group without creating legacy memorization points.';

    /**
     * @var array<string, list<array{id:int, student_number:?string, full_name:string}>>
     */
    protected array $studentsByFullName = [];

    /**
     * @var array<string, bool>
     */
    protected array $existingAchievements = [];

    /**
     * @var array<string, int>
     */
    protected array $summary = [
        'rows_total' => 0,
        'rows_valid_shape' => 0,
        'duplicate_legacy_rows' => 0,
        'invalid_rows' => 0,
        'unmatched_students' => 0,
        'ambiguous_students' => 0,
        'skipped_existing_pages' => 0,
        'skipped_repeated_legacy_pages' => 0,
        'imported_page_rows' => 0,
        'imported_sessions' => 0,
        'affected_students' => 0,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    protected array $reportRows = [
        'invalid_rows' => [],
        'duplicate_legacy_rows' => [],
        'unmatched_students' => [],
        'ambiguous_students' => [],
        'skipped_existing_pages' => [],
        'skipped_repeated_legacy_pages' => [],
        'imported_sessions' => [],
    ];

    public function handle(): int
    {
        $path = $this->resolveImportPath((string) $this->argument('path'));
        $this->info("Import source: {$path}");

        $file = $path.DIRECTORY_SEPARATOR.'entre.csv';

        if (! is_file($file)) {
            $this->error("Missing required file: {$file}");

            return self::FAILURE;
        }

        $reportDir = $this->resolveReportDirectory((string) ($this->option('report-dir') ?: ''));
        $rows = $this->readCsv($file);

        $this->bootstrapStudents();
        $this->bootstrapExistingAchievements();

        $normalizedRows = $this->normalizeLegacyRows($rows);
        $acceptedRows = $this->matchAcceptedRows($normalizedRows);

        DB::beginTransaction();

        try {
            if ($acceptedRows !== []) {
                $this->importAcceptedRows($acceptedRows);
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

        $this->writeReports($reportDir);
        $this->renderSummary($reportDir);

        return self::SUCCESS;
    }

    protected function bootstrapStudents(): void
    {
        Student::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $students): void {
                foreach ($students as $student) {
                    $fullName = trim($student->first_name.' '.$student->last_name);
                    $key = $this->normalizeLookupValue($fullName);

                    if ($key === null) {
                        continue;
                    }

                    $this->studentsByFullName[$key] ??= [];
                    $this->studentsByFullName[$key][] = [
                        'id' => (int) $student->id,
                        'student_number' => $student->student_number,
                        'full_name' => $fullName,
                    ];
                }
            });
    }

    protected function bootstrapExistingAchievements(): void
    {
        StudentPageAchievement::query()
            ->orderBy('id')
            ->chunkById(500, function (Collection $achievements): void {
                foreach ($achievements as $achievement) {
                    $this->existingAchievements[$this->buildAchievementKey((int) $achievement->student_id, (int) $achievement->page_no)] = true;
                }
            });
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return list<array{
     *     record_no:string,
     *     full_name:string,
     *     lookup_name:string,
     *     page_no:int,
     *     recorded_on:string
     * }>
     */
    protected function normalizeLegacyRows(array $rows): array
    {
        $seenLegacyRows = [];
        $normalizedRows = [];

        foreach ($rows as $row) {
            $this->summary['rows_total']++;

            $recordNo = $this->cleanString($row['record_no'] ?? null) ?? (string) $this->summary['rows_total'];
            $fullName = $this->cleanString($row['full_name'] ?? null);
            $pageNo = $this->parsePageNumber($row['page_no'] ?? null);
            $recordedOn = $this->parseDate($row['listen_date'] ?? null);

            if ($fullName === null || $pageNo === null || $recordedOn === null) {
                $this->summary['invalid_rows']++;
                $this->reportRows['invalid_rows'][] = [
                    $recordNo,
                    $fullName ?? '',
                    (string) ($row['page_no'] ?? ''),
                    (string) ($row['listen_date'] ?? ''),
                    'missing_or_invalid_required_field',
                ];

                continue;
            }

            $this->summary['rows_valid_shape']++;
            $lookupName = $this->normalizeLookupValue($fullName) ?? '';
            $legacyKey = implode('|', [$lookupName, $pageNo, $recordedOn]);

            if (isset($seenLegacyRows[$legacyKey])) {
                $this->summary['duplicate_legacy_rows']++;
                $this->reportRows['duplicate_legacy_rows'][] = [
                    $recordNo,
                    $fullName,
                    (string) $pageNo,
                    $recordedOn,
                    (string) $seenLegacyRows[$legacyKey],
                ];

                continue;
            }

            $seenLegacyRows[$legacyKey] = $recordNo;
            $normalizedRows[] = [
                'record_no' => $recordNo,
                'full_name' => $fullName,
                'lookup_name' => $lookupName,
                'page_no' => $pageNo,
                'recorded_on' => $recordedOn,
            ];
        }

        usort($normalizedRows, function (array $left, array $right): int {
            $dateComparison = strcmp($left['recorded_on'], $right['recorded_on']);

            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return strnatcmp($left['record_no'], $right['record_no']);
        });

        return $normalizedRows;
    }

    /**
     * @param  list<array{
     *     record_no:string,
     *     full_name:string,
     *     lookup_name:string,
     *     page_no:int,
     *     recorded_on:string
     * }>  $rows
     * @return list<array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     page_no:int,
     *     recorded_on:string
     * }>
     */
    protected function matchAcceptedRows(array $rows): array
    {
        $acceptedRows = [];
        $seenStudentPages = [];

        foreach ($rows as $row) {
            $studentMatches = $this->studentsByFullName[$row['lookup_name']] ?? [];

            if ($studentMatches === []) {
                $this->summary['unmatched_students']++;
                $this->reportRows['unmatched_students'][] = [
                    $row['record_no'],
                    $row['full_name'],
                    (string) $row['page_no'],
                    $row['recorded_on'],
                ];

                continue;
            }

            if (count($studentMatches) > 1) {
                $this->summary['ambiguous_students']++;
                $this->reportRows['ambiguous_students'][] = [
                    $row['record_no'],
                    $row['full_name'],
                    (string) $row['page_no'],
                    $row['recorded_on'],
                    collect($studentMatches)->map(fn (array $match) => '#'.$match['id'].' '.$match['student_number'])->implode(' ; '),
                ];

                continue;
            }

            $studentMatch = $studentMatches[0];
            $achievementKey = $this->buildAchievementKey($studentMatch['id'], $row['page_no']);

            if (isset($this->existingAchievements[$achievementKey])) {
                $this->summary['skipped_existing_pages']++;
                $this->reportRows['skipped_existing_pages'][] = [
                    $row['record_no'],
                    $row['full_name'],
                    $studentMatch['student_number'] ?? '',
                    (string) $row['page_no'],
                    $row['recorded_on'],
                ];

                continue;
            }

            if (isset($seenStudentPages[$achievementKey])) {
                $this->summary['skipped_repeated_legacy_pages']++;
                $this->reportRows['skipped_repeated_legacy_pages'][] = [
                    $row['record_no'],
                    $row['full_name'],
                    $studentMatch['student_number'] ?? '',
                    (string) $row['page_no'],
                    $row['recorded_on'],
                    (string) $seenStudentPages[$achievementKey],
                ];

                continue;
            }

            $seenStudentPages[$achievementKey] = $row['record_no'];
            $acceptedRows[] = [
                'record_no' => $row['record_no'],
                'student_id' => $studentMatch['id'],
                'student_name' => $studentMatch['full_name'],
                'student_number' => $studentMatch['student_number'],
                'page_no' => $row['page_no'],
                'recorded_on' => $row['recorded_on'],
            ];
        }

        return $acceptedRows;
    }

    /**
     * @param  list<array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     page_no:int,
     *     recorded_on:string
     * }>  $rows
     */
    protected function importAcceptedRows(array $rows): void
    {
        $groupedByStudent = collect($rows)
            ->groupBy('student_id')
            ->map(fn (Collection $studentRows) => $studentRows->values()->all())
            ->all();

        $minRecordedOn = collect($rows)->min('recorded_on');
        $maxRecordedOn = collect($rows)->max('recorded_on');
        $context = $this->resolveLegacyContext((string) $minRecordedOn, (string) $maxRecordedOn);
        $enrollmentsByStudent = [];

        foreach ($groupedByStudent as $studentId => $studentRows) {
            $student = Student::query()->findOrFail((int) $studentId);
            $studentDateWindow = collect($studentRows);
            $enrollmentsByStudent[(int) $studentId] = $this->resolveLegacyEnrollment(
                $student,
                $context['group'],
                (string) $studentDateWindow->min('recorded_on'),
                (string) $studentDateWindow->max('recorded_on'),
            );
        }

        foreach ($this->buildSessions($rows) as $sessionData) {
            $enrollment = $enrollmentsByStudent[$sessionData['student_id']];
            $session = MemorizationSession::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $sessionData['student_id'],
                'teacher_id' => $context['teacher']->id,
                'recorded_on' => $sessionData['recorded_on'],
                'entry_type' => 'new',
                'from_page' => $sessionData['from_page'],
                'to_page' => $sessionData['to_page'],
                'pages_count' => count($sessionData['pages']),
                'notes' => 'Legacy import from Entre records: '.implode(',', $sessionData['source_record_nos']),
            ]);

            MemorizationSessionPage::query()->insert(
                collect($sessionData['pages'])->map(fn (int $pageNo) => [
                    'memorization_session_id' => $session->id,
                    'page_no' => $pageNo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );

            $this->summary['imported_sessions']++;
            $this->summary['imported_page_rows'] += count($sessionData['pages']);
            $this->reportRows['imported_sessions'][] = [
                (string) $sessionData['student_id'],
                $sessionData['student_number'] ?? '',
                $sessionData['student_name'],
                (string) $enrollment->id,
                $sessionData['recorded_on'],
                (string) $sessionData['from_page'],
                (string) $sessionData['to_page'],
                (string) count($sessionData['pages']),
                implode(',', $sessionData['source_record_nos']),
            ];
        }

        foreach (array_keys($groupedByStudent) as $studentId) {
            app(LegacyMemorizationImportService::class)
                ->rebuildStudentAchievementsAndCaches(Student::query()->findOrFail((int) $studentId));
        }

        $this->summary['affected_students'] = count($groupedByStudent);
    }

    /**
     * @param  list<array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     page_no:int,
     *     recorded_on:string
     * }>  $rows
     * @return list<array{
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     recorded_on:string,
     *     from_page:int,
     *     to_page:int,
     *     pages:list<int>,
     *     source_record_nos:list<string>
     * }>
     */
    protected function buildSessions(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $groupKey = $row['student_id'].'|'.$row['recorded_on'];
            $grouped[$groupKey] ??= [];
            $grouped[$groupKey][] = $row;
        }

        $sessions = [];

        foreach ($grouped as $groupRows) {
            usort($groupRows, fn (array $left, array $right): int => $left['page_no'] <=> $right['page_no']);
            $current = null;

            foreach ($groupRows as $row) {
                if ($current === null) {
                    $current = [
                        'student_id' => $row['student_id'],
                        'student_name' => $row['student_name'],
                        'student_number' => $row['student_number'],
                        'recorded_on' => $row['recorded_on'],
                        'from_page' => $row['page_no'],
                        'to_page' => $row['page_no'],
                        'pages' => [$row['page_no']],
                        'source_record_nos' => [$row['record_no']],
                    ];
                    continue;
                }

                if ($row['page_no'] === $current['to_page'] + 1) {
                    $current['to_page'] = $row['page_no'];
                    $current['pages'][] = $row['page_no'];
                    $current['source_record_nos'][] = $row['record_no'];
                    continue;
                }

                $sessions[] = $current;
                $current = [
                    'student_id' => $row['student_id'],
                    'student_name' => $row['student_name'],
                    'student_number' => $row['student_number'],
                    'recorded_on' => $row['recorded_on'],
                    'from_page' => $row['page_no'],
                    'to_page' => $row['page_no'],
                    'pages' => [$row['page_no']],
                    'source_record_nos' => [$row['record_no']],
                ];
            }

            if ($current !== null) {
                $sessions[] = $current;
            }
        }

        return $sessions;
    }

    /**
     * @return array{academic_year:AcademicYear, course:Course, group:Group, teacher:Teacher}
     */
    protected function resolveLegacyContext(string $minRecordedOn, string $maxRecordedOn): array
    {
        $academicYear = AcademicYear::query()->firstOrCreate(
            ['name' => (string) $this->option('academic-year-name')],
            [
                'starts_on' => Carbon::parse($minRecordedOn)->startOfYear()->toDateString(),
                'ends_on' => Carbon::parse($maxRecordedOn)->endOfYear()->toDateString(),
                'is_current' => false,
                'is_active' => false,
            ],
        );

        $course = Course::withTrashed()->firstOrNew([
            'name' => (string) $this->option('course-name'),
        ]);
        $course->description = 'Placeholder course for legacy memorization import.';
        $course->is_active = false;
        $course->deleted_at = null;
        $course->save();

        [$teacherFirstName, $teacherLastName] = $this->splitName((string) $this->option('teacher-name'));
        $teacher = Teacher::withTrashed()->firstOrNew([
            'phone' => $this->legacyTeacherPhone((string) $this->option('teacher-name')),
        ]);
        $teacher->first_name = $teacherFirstName;
        $teacher->last_name = $teacherLastName;
        $teacher->job_title = 'Legacy Import Placeholder';
        $teacher->status = 'inactive';
        $teacher->is_helping = false;
        $teacher->notes = $this->appendLegacyNote($teacher->notes, 'legacy_import', 'memorization_entre');
        $teacher->deleted_at = null;
        $teacher->save();

        $group = Group::withTrashed()->firstOrNew([
            'academic_year_id' => $academicYear->id,
            'name' => (string) $this->option('group-name'),
        ]);
        $group->course_id = $course->id;
        $group->teacher_id = $teacher->id;
        $group->assistant_teacher_id = null;
        $group->grade_level_id = null;
        $group->capacity = 0;
        $group->starts_on = Carbon::parse($minRecordedOn)->toDateString();
        $group->ends_on = Carbon::parse($maxRecordedOn)->toDateString();
        $group->monthly_fee = null;
        $group->is_active = false;
        $group->deleted_at = null;
        $group->save();

        return [
            'academic_year' => $academicYear,
            'course' => $course,
            'group' => $group,
            'teacher' => $teacher,
        ];
    }

    protected function resolveLegacyEnrollment(Student $student, Group $group, string $enrolledAt, string $leftAt): Enrollment
    {
        $enrollment = Enrollment::withTrashed()
            ->where('student_id', $student->id)
            ->where('group_id', $group->id)
            ->orderBy('id')
            ->first();

        if (! $enrollment) {
            $enrollment = new Enrollment([
                'student_id' => $student->id,
                'group_id' => $group->id,
            ]);
        }

        $existingEnrolledAt = $enrollment->enrolled_at?->toDateString();
        $existingLeftAt = $enrollment->left_at?->toDateString();

        $enrollment->enrolled_at = $existingEnrolledAt === null
            ? $enrolledAt
            : min($existingEnrolledAt, $enrolledAt);
        $enrollment->left_at = $existingLeftAt === null
            ? $leftAt
            : max($existingLeftAt, $leftAt);
        $enrollment->status = 'inactive';
        $enrollment->notes = $this->appendLegacyNote($enrollment->notes, 'legacy_import', 'memorization_entre');
        $enrollment->deleted_at = null;
        $enrollment->save();

        return $enrollment->fresh();
    }

    protected function renderSummary(string $reportDir): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy rows', $this->summary['rows_total']],
                ['Valid page/date rows', $this->summary['rows_valid_shape']],
                ['Duplicate legacy rows', $this->summary['duplicate_legacy_rows']],
                ['Invalid rows', $this->summary['invalid_rows']],
                ['Unmatched students', $this->summary['unmatched_students']],
                ['Ambiguous students', $this->summary['ambiguous_students']],
                ['Skipped existing live pages', $this->summary['skipped_existing_pages']],
                ['Skipped repeated legacy pages', $this->summary['skipped_repeated_legacy_pages']],
                ['Imported page rows', $this->summary['imported_page_rows']],
                ['Imported sessions', $this->summary['imported_sessions']],
                ['Affected students', $this->summary['affected_students']],
            ],
        );

        $this->line("Reports written to: {$reportDir}");
    }

    protected function writeReports(string $reportDir): void
    {
        if (! is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }

        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'invalid_rows.csv', ['record_no', 'student_name', 'page_no', 'listen_date', 'reason'], $this->reportRows['invalid_rows']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'duplicate_legacy_rows.csv', ['record_no', 'student_name', 'page_no', 'recorded_on', 'first_record_no'], $this->reportRows['duplicate_legacy_rows']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'unmatched_students.csv', ['record_no', 'student_name', 'page_no', 'recorded_on'], $this->reportRows['unmatched_students']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'ambiguous_students.csv', ['record_no', 'student_name', 'page_no', 'recorded_on', 'candidate_students'], $this->reportRows['ambiguous_students']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'skipped_existing_pages.csv', ['record_no', 'student_name', 'student_number', 'page_no', 'recorded_on'], $this->reportRows['skipped_existing_pages']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'skipped_repeated_legacy_pages.csv', ['record_no', 'student_name', 'student_number', 'page_no', 'recorded_on', 'first_record_no'], $this->reportRows['skipped_repeated_legacy_pages']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'imported_sessions.csv', ['student_id', 'student_number', 'student_name', 'enrollment_id', 'recorded_on', 'from_page', 'to_page', 'pages_count', 'source_record_nos'], $this->reportRows['imported_sessions']);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    protected function writeCsvReport(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open report file for writing: {$path}");
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    protected function buildAchievementKey(int $studentId, int $pageNo): string
    {
        return $studentId.'|'.$pageNo;
    }

    protected function parsePageNumber(mixed $value): ?int
    {
        $value = $this->cleanString($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $pageNo = (int) $value;

        return $pageNo >= 1 && $pageNo <= 604 ? $pageNo : null;
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

    protected function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

    protected function resolveReportDirectory(string $reportDir): string
    {
        if ($reportDir !== '') {
            if (! str_starts_with($reportDir, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:\\\\/', $reportDir)) {
                return base_path($reportDir);
            }

            return $reportDir;
        }

        return storage_path('app/legacy-memorization-import/'.now()->format('Ymd-His').'-'.Str::random(6));
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? $fullName);
        $parts = preg_split('/\s+/u', $fullName) ?: [$fullName];

        if (count($parts) <= 1) {
            return [$fullName, 'Import'];
        }

        $lastName = array_pop($parts);

        return [implode(' ', $parts), (string) $lastName];
    }

    protected function legacyTeacherPhone(string $value): string
    {
        $slug = Str::slug($value, '-');
        $slug = $slug !== '' ? $slug : 'teacher';

        return Str::limit('legacy-'.$slug, 30, '');
    }

    protected function appendLegacyNote(?string $existing, string $key, string $value): string
    {
        $line = '['.$key.'] '.$value;
        $existing = trim((string) $existing);

        if ($existing === '') {
            return $line;
        }

        if (Str::contains($existing, $line)) {
            return $existing;
        }

        return $existing.PHP_EOL.$line;
    }
}
