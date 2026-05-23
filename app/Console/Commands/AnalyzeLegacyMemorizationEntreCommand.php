<?php

namespace App\Console\Commands;

use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeLegacyMemorizationEntreCommand extends Command
{
    protected $signature = 'legacy:analyze-memorization-entre
        {path=storage/app/legacy-access-export : Folder containing exported CSV files}
        {--report-dir= : Directory for CSV analysis reports}';

    protected $description = 'Analyze legacy Access memorization page rows from entre.csv against live students, teachers, and enrollments without writing any data.';

    /**
     * @var array<string, list<array{id:int, student_number:?string, full_name:string}>>
     */
    protected array $studentsByFullName = [];

    /**
     * @var array<string, list<array{id:int, full_name:string, status:string}>>
     */
    protected array $teachersByFullName = [];

    /**
     * @var array<string, list<array{id:int, group_name:string, enrolled_at:?string, left_at:?string, deleted_at:?string}>>
     */
    protected array $enrollmentsByStudentAndCourse = [];

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
        'unmatched_teachers' => 0,
        'ambiguous_teachers' => 0,
        'unresolved_enrollments' => 0,
        'overlapping_live_pages' => 0,
        'importable_rows' => 0,
        'proposed_sessions' => 0,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    protected array $reportRows = [
        'invalid_rows' => [],
        'duplicate_legacy_rows' => [],
        'unmatched_students' => [],
        'ambiguous_students' => [],
        'unmatched_teachers' => [],
        'ambiguous_teachers' => [],
        'unresolved_enrollments' => [],
        'overlapping_live_pages' => [],
        'proposed_sessions' => [],
    ];

    public function handle(): int
    {
        $path = $this->resolveImportPath((string) $this->argument('path'));
        $this->info("Analysis source: {$path}");

        $file = $path.DIRECTORY_SEPARATOR.'entre.csv';

        if (! is_file($file)) {
            $this->error("Missing required file: {$file}");

            return self::FAILURE;
        }

        $reportDir = $this->resolveReportDirectory((string) ($this->option('report-dir') ?: ''));
        $rows = $this->readCsv($file);

        $this->bootstrapStudents();
        $this->bootstrapTeachers();
        $this->bootstrapEnrollments();
        $this->bootstrapExistingAchievements();

        $seenLegacyRows = [];
        $importableRows = [];

        foreach ($rows as $row) {
            $this->summary['rows_total']++;

            $recordNo = $this->cleanString($row['record_no'] ?? null) ?? (string) $this->summary['rows_total'];
            $fullName = $this->cleanString($row['full_name'] ?? null);
            $courseName = $this->cleanString($row['Courses_Name'] ?? null);
            $teacherName = $this->cleanString($row['listener_name'] ?? null);
            $pageNo = $this->parsePageNumber($row['page_no'] ?? null);
            $recordedOn = $this->parseDate($row['listen_date'] ?? null);

            if ($fullName === null || $courseName === null || $teacherName === null || $pageNo === null || $recordedOn === null) {
                $this->summary['invalid_rows']++;
                $this->reportRows['invalid_rows'][] = [
                    $recordNo,
                    $fullName ?? '',
                    $courseName ?? '',
                    $teacherName ?? '',
                    (string) ($row['page_no'] ?? ''),
                    (string) ($row['listen_date'] ?? ''),
                    'missing_or_invalid_required_field',
                ];

                continue;
            }

            $this->summary['rows_valid_shape']++;

            $legacyKey = implode('|', [
                $this->normalizeLookupValue($fullName),
                $this->normalizeLookupValue($courseName),
                $this->normalizeLookupValue($teacherName),
                $recordedOn,
                $pageNo,
            ]);

            if (isset($seenLegacyRows[$legacyKey])) {
                $this->summary['duplicate_legacy_rows']++;
                $this->reportRows['duplicate_legacy_rows'][] = [
                    $recordNo,
                    $fullName,
                    $courseName,
                    $teacherName,
                    (string) $pageNo,
                    $recordedOn,
                    (string) $seenLegacyRows[$legacyKey],
                ];

                continue;
            }

            $seenLegacyRows[$legacyKey] = $recordNo;

            $studentMatches = $this->studentsByFullName[$this->normalizeLookupValue($fullName) ?? ''] ?? [];

            if ($studentMatches === []) {
                $this->summary['unmatched_students']++;
                $this->reportRows['unmatched_students'][] = [
                    $recordNo,
                    $fullName,
                    $courseName,
                    $teacherName,
                    (string) $pageNo,
                    $recordedOn,
                ];

                continue;
            }

            if (count($studentMatches) > 1) {
                $this->summary['ambiguous_students']++;
                $this->reportRows['ambiguous_students'][] = [
                    $recordNo,
                    $fullName,
                    $courseName,
                    $teacherName,
                    (string) $pageNo,
                    $recordedOn,
                    collect($studentMatches)->map(fn (array $match) => '#'.$match['id'].' '.$match['student_number'])->implode(' ; '),
                ];

                continue;
            }

            $studentMatch = $studentMatches[0];
            $teacherMatches = $this->teachersByFullName[$this->normalizeLookupValue($teacherName) ?? ''] ?? [];

            if ($teacherMatches === []) {
                $this->summary['unmatched_teachers']++;
                $this->reportRows['unmatched_teachers'][] = [
                    $recordNo,
                    $fullName,
                    $studentMatch['student_number'] ?? '',
                    $teacherName,
                    $courseName,
                    (string) $pageNo,
                    $recordedOn,
                ];

                continue;
            }

            if (count($teacherMatches) > 1) {
                $this->summary['ambiguous_teachers']++;
                $this->reportRows['ambiguous_teachers'][] = [
                    $recordNo,
                    $fullName,
                    $studentMatch['student_number'] ?? '',
                    $teacherName,
                    $courseName,
                    (string) $pageNo,
                    $recordedOn,
                    collect($teacherMatches)->map(fn (array $match) => '#'.$match['id'].' '.$match['full_name'])->implode(' ; '),
                ];

                continue;
            }

            $teacherMatch = $teacherMatches[0];
            $enrollmentMatches = $this->enrollmentsByStudentAndCourse[$this->buildStudentCourseKey($studentMatch['id'], $courseName)] ?? [];

            if (count($enrollmentMatches) !== 1) {
                $this->summary['unresolved_enrollments']++;
                $this->reportRows['unresolved_enrollments'][] = [
                    $recordNo,
                    $fullName,
                    $studentMatch['student_number'] ?? '',
                    $courseName,
                    (string) $pageNo,
                    $recordedOn,
                    count($enrollmentMatches) === 0
                        ? 'no_matching_enrollment'
                        : 'ambiguous: '.collect($enrollmentMatches)->map(fn (array $match) => '#'.$match['id'].' '.$match['group_name'].' enrolled '.$match['enrolled_at'])->implode(' ; '),
                ];

                continue;
            }

            $enrollmentMatch = $enrollmentMatches[0];
            $achievementKey = $this->buildAchievementKey($studentMatch['id'], $pageNo);

            if (isset($this->existingAchievements[$achievementKey])) {
                $this->summary['overlapping_live_pages']++;
                $this->reportRows['overlapping_live_pages'][] = [
                    $recordNo,
                    $fullName,
                    $studentMatch['student_number'] ?? '',
                    $courseName,
                    $teacherName,
                    (string) $pageNo,
                    $recordedOn,
                ];

                continue;
            }

            $this->summary['importable_rows']++;
            $importableRows[] = [
                'record_no' => $recordNo,
                'student_id' => $studentMatch['id'],
                'student_name' => $studentMatch['full_name'],
                'student_number' => $studentMatch['student_number'],
                'enrollment_id' => $enrollmentMatch['id'],
                'course_name' => $courseName,
                'teacher_id' => $teacherMatch['id'],
                'teacher_name' => $teacherMatch['full_name'],
                'recorded_on' => $recordedOn,
                'page_no' => $pageNo,
            ];
        }

        $this->proposeSessions($importableRows);
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

    protected function bootstrapTeachers(): void
    {
        Teacher::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $teachers): void {
                foreach ($teachers as $teacher) {
                    $fullName = trim($teacher->first_name.' '.$teacher->last_name);
                    $key = $this->normalizeLookupValue($fullName);

                    if ($key === null) {
                        continue;
                    }

                    $this->teachersByFullName[$key] ??= [];
                    $this->teachersByFullName[$key][] = [
                        'id' => (int) $teacher->id,
                        'full_name' => $fullName,
                        'status' => (string) $teacher->status,
                    ];
                }
            });
    }

    protected function bootstrapEnrollments(): void
    {
        Enrollment::withTrashed()
            ->with([
                'group' => fn ($query) => $query->withTrashed()->with([
                    'course' => fn ($courseQuery) => $courseQuery->withTrashed(),
                ]),
            ])
            ->orderBy('id')
            ->chunkById(200, function (Collection $enrollments): void {
                foreach ($enrollments as $enrollment) {
                    $courseName = $this->cleanString($enrollment->group?->course?->name);

                    if ($courseName === null) {
                        continue;
                    }

                    $key = $this->buildStudentCourseKey((int) $enrollment->student_id, $courseName);
                    $this->enrollmentsByStudentAndCourse[$key] ??= [];
                    $this->enrollmentsByStudentAndCourse[$key][] = [
                        'id' => (int) $enrollment->id,
                        'group_name' => (string) ($enrollment->group?->name ?? ''),
                        'enrolled_at' => $enrollment->enrolled_at?->toDateString(),
                        'left_at' => $enrollment->left_at?->toDateString(),
                        'deleted_at' => $enrollment->deleted_at?->toDateTimeString(),
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
     * @param  list<array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     enrollment_id:int,
     *     course_name:string,
     *     teacher_id:int,
     *     teacher_name:string,
     *     recorded_on:string,
     *     page_no:int
     * }>  $rows
     */
    protected function proposeSessions(array $rows): void
    {
        $grouped = [];

        foreach ($rows as $row) {
            $groupKey = implode('|', [
                $row['student_id'],
                $row['enrollment_id'],
                $row['teacher_id'],
                $row['recorded_on'],
            ]);

            $grouped[$groupKey] ??= [];
            $grouped[$groupKey][] = $row;
        }

        foreach ($grouped as $groupRows) {
            usort($groupRows, fn (array $left, array $right): int => $left['page_no'] <=> $right['page_no']);

            $current = null;

            foreach ($groupRows as $row) {
                if ($current === null) {
                    $current = $this->startProposedSession($row);
                    continue;
                }

                if ($row['page_no'] === $current['to_page'] + 1) {
                    $current['to_page'] = $row['page_no'];
                    $current['pages_count']++;
                    $current['source_record_nos'][] = $row['record_no'];
                    continue;
                }

                $this->pushProposedSession($current);
                $current = $this->startProposedSession($row);
            }

            if ($current !== null) {
                $this->pushProposedSession($current);
            }
        }
    }

    /**
     * @param  array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     enrollment_id:int,
     *     course_name:string,
     *     teacher_id:int,
     *     teacher_name:string,
     *     recorded_on:string,
     *     page_no:int
     * }  $row
     * @return array{
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     enrollment_id:int,
     *     course_name:string,
     *     teacher_id:int,
     *     teacher_name:string,
     *     recorded_on:string,
     *     from_page:int,
     *     to_page:int,
     *     pages_count:int,
     *     source_record_nos:list<string>
     * }
     */
    protected function startProposedSession(array $row): array
    {
        return [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'student_number' => $row['student_number'],
            'enrollment_id' => $row['enrollment_id'],
            'course_name' => $row['course_name'],
            'teacher_id' => $row['teacher_id'],
            'teacher_name' => $row['teacher_name'],
            'recorded_on' => $row['recorded_on'],
            'from_page' => $row['page_no'],
            'to_page' => $row['page_no'],
            'pages_count' => 1,
            'source_record_nos' => [$row['record_no']],
        ];
    }

    /**
     * @param  array{
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     enrollment_id:int,
     *     course_name:string,
     *     teacher_id:int,
     *     teacher_name:string,
     *     recorded_on:string,
     *     from_page:int,
     *     to_page:int,
     *     pages_count:int,
     *     source_record_nos:list<string>
     * }  $session
     */
    protected function pushProposedSession(array $session): void
    {
        $this->summary['proposed_sessions']++;
        $this->reportRows['proposed_sessions'][] = [
            (string) $session['student_id'],
            $session['student_number'] ?? '',
            $session['student_name'],
            (string) $session['enrollment_id'],
            $session['course_name'],
            (string) $session['teacher_id'],
            $session['teacher_name'],
            $session['recorded_on'],
            (string) $session['from_page'],
            (string) $session['to_page'],
            (string) $session['pages_count'],
            implode(',', $session['source_record_nos']),
        ];
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
                ['Unmatched teachers', $this->summary['unmatched_teachers']],
                ['Ambiguous teachers', $this->summary['ambiguous_teachers']],
                ['Unresolved enrollments', $this->summary['unresolved_enrollments']],
                ['Already in live pages', $this->summary['overlapping_live_pages']],
                ['Importable page rows', $this->summary['importable_rows']],
                ['Proposed sessions', $this->summary['proposed_sessions']],
            ],
        );

        $this->line("Reports written to: {$reportDir}");
    }

    protected function writeReports(string $reportDir): void
    {
        if (! is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }

        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'invalid_rows.csv', ['record_no', 'student_name', 'course_name', 'teacher_name', 'page_no', 'listen_date', 'reason'], $this->reportRows['invalid_rows']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'duplicate_legacy_rows.csv', ['record_no', 'student_name', 'course_name', 'teacher_name', 'page_no', 'recorded_on', 'first_record_no'], $this->reportRows['duplicate_legacy_rows']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'unmatched_students.csv', ['record_no', 'student_name', 'course_name', 'teacher_name', 'page_no', 'recorded_on'], $this->reportRows['unmatched_students']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'ambiguous_students.csv', ['record_no', 'student_name', 'course_name', 'teacher_name', 'page_no', 'recorded_on', 'candidate_students'], $this->reportRows['ambiguous_students']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'unmatched_teachers.csv', ['record_no', 'student_name', 'student_number', 'teacher_name', 'course_name', 'page_no', 'recorded_on'], $this->reportRows['unmatched_teachers']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'ambiguous_teachers.csv', ['record_no', 'student_name', 'student_number', 'teacher_name', 'course_name', 'page_no', 'recorded_on', 'candidate_teachers'], $this->reportRows['ambiguous_teachers']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'unresolved_enrollments.csv', ['record_no', 'student_name', 'student_number', 'course_name', 'page_no', 'recorded_on', 'reason'], $this->reportRows['unresolved_enrollments']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'overlapping_live_pages.csv', ['record_no', 'student_name', 'student_number', 'course_name', 'teacher_name', 'page_no', 'recorded_on'], $this->reportRows['overlapping_live_pages']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'proposed_sessions.csv', ['student_id', 'student_number', 'student_name', 'enrollment_id', 'course_name', 'teacher_id', 'teacher_name', 'recorded_on', 'from_page', 'to_page', 'pages_count', 'source_record_nos'], $this->reportRows['proposed_sessions']);
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

    protected function buildStudentCourseKey(int $studentId, string $courseName): string
    {
        return $studentId.'|'.$this->normalizeLookupValue($courseName);
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

        return storage_path('app/legacy-memorization-analysis/'.now()->format('Ymd-His').'-'.Str::random(6));
    }
}
