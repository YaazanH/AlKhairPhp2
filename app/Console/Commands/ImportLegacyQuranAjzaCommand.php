<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportLegacyQuranAjzaCommand extends Command
{
    protected $signature = 'legacy:import-quran-ajza
        {path=storage/app/legacy-quran-import : Folder containing ajza.csv}
        {--dry-run : Roll back the import after validation}
        {--report-dir= : Directory for CSV import reports}
        {--teacher-name=Import Teacher : Placeholder teacher name for imported legacy tests}
        {--course-name=Import Course : Placeholder course name for imported legacy tests}
        {--group-name=Import Group : Placeholder group name for imported legacy tests}
        {--academic-year-name=Legacy Import : Placeholder academic year name if one must be created}';

    protected $description = 'Import legacy final Quran test rows from ajza.csv, creating a passed partial cycle first and then the passed final attempt without creating points.';

    /**
     * @var array<string, list<array{id:int, student_number:?string, full_name:string}>>
     */
    protected array $studentsByFullName = [];

    /**
     * @var array<int, QuranJuz>
     */
    protected array $juzByNumber = [];

    /**
     * @var array<string, int>
     */
    protected array $summary = [
        'rows_total' => 0,
        'rows_valid_shape' => 0,
        'duplicate_legacy_rows' => 0,
        'invalid_rows' => 0,
        'invalid_juz_numbers' => 0,
        'unmatched_students' => 0,
        'ambiguous_students' => 0,
        'skipped_existing_final_tests' => 0,
        'skipped_unresolved_partial_tests' => 0,
        'imported_partial_tests' => 0,
        'reused_partial_tests' => 0,
        'imported_final_tests' => 0,
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
        'skipped_existing_final_tests' => [],
        'skipped_unresolved_partial_tests' => [],
        'imported_tests' => [],
    ];

    public function handle(): int
    {
        $path = $this->resolveImportPath((string) $this->argument('path'));
        $this->info("Import source: {$path}");

        $file = $path.DIRECTORY_SEPARATOR.'ajza.csv';

        if (! is_file($file)) {
            $this->error("Missing required file: {$file}");

            return self::FAILURE;
        }

        $reportDir = $this->resolveReportDirectory((string) ($this->option('report-dir') ?: ''));
        $rows = $this->readCsv($file);

        $this->bootstrapStudents();
        $this->bootstrapJuzs();

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

    protected function bootstrapJuzs(): void
    {
        QuranJuz::query()
            ->orderBy('juz_number')
            ->get()
            ->each(function (QuranJuz $juz): void {
                $this->juzByNumber[(int) $juz->juz_number] = $juz;
            });
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return list<array{
     *     record_no:string,
     *     student_name:string,
     *     lookup_name:string,
     *     juz_number:int,
     *     tested_on:string,
     *     score:?float,
     *     listener_name:?string,
     *     course_name:?string,
     *     evaluation:?string,
     *     awqaf_exam_name:?string,
     *     awqaf_exam_result:?string
     * }>
     */
    protected function normalizeLegacyRows(array $rows): array
    {
        $seenLegacyRows = [];
        $normalizedRows = [];

        foreach ($rows as $row) {
            $this->summary['rows_total']++;

            $recordNo = $this->cleanString($this->rowValue($row, ['record_no'])) ?? (string) $this->summary['rows_total'];
            $studentName = $this->cleanString($this->rowValue($row, ['student_name', 'الاسم']));
            $juzNumber = $this->parsePositiveInt($this->rowValue($row, ['juz_number', 'رقم الجزء']));
            $testedOn = $this->parseDate($this->rowValue($row, ['tested_on', 'تاريخ التسميع']));
            $listenerName = $this->cleanString($this->rowValue($row, ['listener_name', 'اسم المسمع']));
            $courseName = $this->cleanString($this->rowValue($row, ['course_name', 'اسم الدورة']));
            $evaluation = $this->cleanString($this->rowValue($row, ['evaluation', 'التقييم']));
            $score = $this->parseScore($this->rowValue($row, ['score', 'mark_ajza']));
            $awqafExamName = $this->cleanString($this->rowValue($row, ['awqaf_exam_name']));
            $awqafExamResult = $this->cleanString($this->rowValue($row, ['awqaf_exam_result']));

            if ($studentName === null || $juzNumber === null || $testedOn === null) {
                $this->summary['invalid_rows']++;
                $this->reportRows['invalid_rows'][] = [
                    $recordNo,
                    $studentName ?? '',
                    (string) ($this->rowValue($row, ['juz_number', 'رقم الجزء']) ?? ''),
                    (string) ($this->rowValue($row, ['tested_on', 'تاريخ التسميع']) ?? ''),
                    'missing_or_invalid_required_field',
                ];

                continue;
            }

            if (! isset($this->juzByNumber[$juzNumber])) {
                $this->summary['invalid_juz_numbers']++;
                $this->reportRows['invalid_rows'][] = [
                    $recordNo,
                    $studentName,
                    (string) $juzNumber,
                    $testedOn,
                    'unknown_juz_number',
                ];

                continue;
            }

            $this->summary['rows_valid_shape']++;
            $lookupName = $this->normalizeLookupValue($studentName) ?? '';
            $legacyKey = $lookupName.'|'.$juzNumber;

            if (isset($seenLegacyRows[$legacyKey])) {
                $this->summary['duplicate_legacy_rows']++;
                $this->reportRows['duplicate_legacy_rows'][] = [
                    $recordNo,
                    $studentName,
                    (string) $juzNumber,
                    $testedOn,
                    (string) $seenLegacyRows[$legacyKey],
                ];

                continue;
            }

            $seenLegacyRows[$legacyKey] = $recordNo;
            $normalizedRows[] = [
                'record_no' => $recordNo,
                'student_name' => $studentName,
                'lookup_name' => $lookupName,
                'juz_number' => $juzNumber,
                'tested_on' => $testedOn,
                'score' => $score,
                'listener_name' => $listenerName,
                'course_name' => $courseName,
                'evaluation' => $evaluation,
                'awqaf_exam_name' => $awqafExamName,
                'awqaf_exam_result' => $awqafExamResult,
            ];
        }

        usort($normalizedRows, function (array $left, array $right): int {
            $dateComparison = strcmp($left['tested_on'], $right['tested_on']);

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
     *     student_name:string,
     *     lookup_name:string,
     *     juz_number:int,
     *     tested_on:string,
     *     score:?float,
     *     listener_name:?string,
     *     course_name:?string,
     *     evaluation:?string,
     *     awqaf_exam_name:?string,
     *     awqaf_exam_result:?string
     * }>  $rows
     * @return list<array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     juz_id:int,
     *     juz_number:int,
     *     tested_on:string,
     *     score:?float,
     *     listener_name:?string,
     *     course_name:?string,
     *     evaluation:?string,
     *     awqaf_exam_name:?string,
     *     awqaf_exam_result:?string
     * }>
     */
    protected function matchAcceptedRows(array $rows): array
    {
        $acceptedRows = [];

        foreach ($rows as $row) {
            $studentMatches = $this->studentsByFullName[$row['lookup_name']] ?? [];

            if ($studentMatches === []) {
                $this->summary['unmatched_students']++;
                $this->reportRows['unmatched_students'][] = [
                    $row['record_no'],
                    $row['student_name'],
                    (string) $row['juz_number'],
                    $row['tested_on'],
                ];

                continue;
            }

            if (count($studentMatches) > 1) {
                $this->summary['ambiguous_students']++;
                $this->reportRows['ambiguous_students'][] = [
                    $row['record_no'],
                    $row['student_name'],
                    (string) $row['juz_number'],
                    $row['tested_on'],
                    collect($studentMatches)->map(fn (array $match) => '#'.$match['id'].' '.$match['student_number'])->implode(' ; '),
                ];

                continue;
            }

            $studentMatch = $studentMatches[0];
            $acceptedRows[] = [
                'record_no' => $row['record_no'],
                'student_id' => $studentMatch['id'],
                'student_name' => $studentMatch['full_name'],
                'student_number' => $studentMatch['student_number'],
                'juz_id' => $this->juzByNumber[$row['juz_number']]->id,
                'juz_number' => $row['juz_number'],
                'tested_on' => $row['tested_on'],
                'score' => $row['score'],
                'listener_name' => $row['listener_name'],
                'course_name' => $row['course_name'],
                'evaluation' => $row['evaluation'],
                'awqaf_exam_name' => $row['awqaf_exam_name'],
                'awqaf_exam_result' => $row['awqaf_exam_result'],
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
     *     juz_id:int,
     *     juz_number:int,
     *     tested_on:string,
     *     score:?float,
     *     listener_name:?string,
     *     course_name:?string,
     *     evaluation:?string,
     *     awqaf_exam_name:?string,
     *     awqaf_exam_result:?string
     * }>  $rows
     */
    protected function importAcceptedRows(array $rows): void
    {
        $groupedByStudent = collect($rows)
            ->groupBy('student_id')
            ->map(fn (Collection $studentRows) => $studentRows->values()->all())
            ->all();

        $minTestedOn = collect($rows)->min('tested_on');
        $maxTestedOn = collect($rows)->max('tested_on');
        $context = $this->resolveLegacyContext((string) $minTestedOn, (string) $maxTestedOn);
        $enrollmentsByStudent = [];

        foreach ($groupedByStudent as $studentId => $studentRows) {
            $student = Student::query()->findOrFail((int) $studentId);
            $studentDateWindow = collect($studentRows);
            $enrollmentsByStudent[(int) $studentId] = $this->resolveLegacyEnrollment(
                $student,
                $context['group'],
                (string) $studentDateWindow->min('tested_on'),
                (string) $studentDateWindow->max('tested_on'),
            );
        }

        $affectedStudents = [];

        foreach ($rows as $row) {
            $enrollment = $enrollmentsByStudent[$row['student_id']];
            $partialTest = QuranPartialTest::query()
                ->with('parts.attempts')
                ->where('student_id', $row['student_id'])
                ->where('juz_id', $row['juz_id'])
                ->first();

            $finalTest = QuranFinalTest::query()
                ->where('student_id', $row['student_id'])
                ->where('juz_id', $row['juz_id'])
                ->first();

            if ($finalTest) {
                $this->summary['skipped_existing_final_tests']++;
                $this->reportRows['skipped_existing_final_tests'][] = [
                    $row['record_no'],
                    $row['student_name'],
                    $row['student_number'] ?? '',
                    (string) $row['juz_number'],
                    $row['tested_on'],
                    '#'.$finalTest->id,
                ];

                continue;
            }

            if ($partialTest && $partialTest->status !== 'passed') {
                $this->summary['skipped_unresolved_partial_tests']++;
                $this->reportRows['skipped_unresolved_partial_tests'][] = [
                    $row['record_no'],
                    $row['student_name'],
                    $row['student_number'] ?? '',
                    (string) $row['juz_number'],
                    $row['tested_on'],
                    '#'.$partialTest->id.' '.$partialTest->status,
                ];

                continue;
            }

            $notes = $this->buildImportNotes($row);

            if (! $partialTest) {
                $partialTest = QuranPartialTest::query()->create([
                    'created_by' => null,
                    'enrollment_id' => $enrollment->id,
                    'juz_id' => $row['juz_id'],
                    'passed_on' => $row['tested_on'],
                    'status' => 'passed',
                    'student_id' => $row['student_id'],
                ]);

                foreach (range(1, 4) as $partNumber) {
                    $part = $partialTest->parts()->create([
                        'part_number' => $partNumber,
                        'passed_on' => $row['tested_on'],
                        'status' => 'passed',
                    ]);

                    $part->attempts()->create([
                        'attempt_no' => 1,
                        'mistake_count' => 0,
                        'notes' => $notes,
                        'score' => null,
                        'status' => 'passed',
                        'teacher_id' => $context['teacher']->id,
                        'tested_on' => $row['tested_on'],
                    ]);
                }

                $this->summary['imported_partial_tests']++;
            } else {
                $this->summary['reused_partial_tests']++;
            }

            $finalTest = QuranFinalTest::query()->create([
                'created_by' => null,
                'enrollment_id' => $enrollment->id,
                'juz_id' => $row['juz_id'],
                'passed_on' => $row['tested_on'],
                'status' => 'passed',
                'student_id' => $row['student_id'],
            ]);

            $finalTest->attempts()->create([
                'attempt_no' => 1,
                'notes' => $notes,
                'score' => $row['score'],
                'status' => 'passed',
                'teacher_id' => $context['teacher']->id,
                'tested_on' => $row['tested_on'],
            ]);

            $this->summary['imported_final_tests']++;
            $affectedStudents[$row['student_id']] = true;

            $this->reportRows['imported_tests'][] = [
                (string) $row['student_id'],
                $row['student_number'] ?? '',
                $row['student_name'],
                (string) $enrollment->id,
                (string) $row['juz_number'],
                $row['tested_on'],
                $row['score'] !== null ? number_format($row['score'], 2, '.', '') : '',
                $partialTest->wasRecentlyCreated ? 'created' : 'reused',
                (string) $finalTest->id,
                $row['record_no'],
            ];
        }

        $this->summary['affected_students'] = count($affectedStudents);
    }

    /**
     * @return array{academic_year:AcademicYear, course:Course, group:Group, teacher:Teacher}
     */
    protected function resolveLegacyContext(string $minTestedOn, string $maxTestedOn): array
    {
        $academicYear = AcademicYear::query()->firstOrCreate(
            ['name' => (string) $this->option('academic-year-name')],
            [
                'starts_on' => Carbon::parse($minTestedOn)->startOfYear()->toDateString(),
                'ends_on' => Carbon::parse($maxTestedOn)->endOfYear()->toDateString(),
                'is_current' => false,
                'is_active' => false,
            ],
        );

        $course = Course::withTrashed()->firstOrNew([
            'name' => (string) $this->option('course-name'),
        ]);
        $course->description = 'Placeholder course for legacy Quran final-test import.';
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
        $teacher->notes = $this->appendLegacyNote($teacher->notes, 'legacy_import', 'quran_ajza');
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
        $existingStartsOn = $group->starts_on?->toDateString();
        $existingEndsOn = $group->ends_on?->toDateString();
        $group->starts_on = $existingStartsOn === null
            ? Carbon::parse($minTestedOn)->toDateString()
            : min($existingStartsOn, Carbon::parse($minTestedOn)->toDateString());
        $group->ends_on = $existingEndsOn === null
            ? Carbon::parse($maxTestedOn)->toDateString()
            : max($existingEndsOn, Carbon::parse($maxTestedOn)->toDateString());
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
        $enrollment->notes = $this->appendLegacyNote($enrollment->notes, 'legacy_import', 'quran_ajza');
        $enrollment->deleted_at = null;
        $enrollment->save();

        return $enrollment->fresh();
    }

    /**
     * @param  array{
     *     record_no:string,
     *     student_id:int,
     *     student_name:string,
     *     student_number:?string,
     *     juz_id:int,
     *     juz_number:int,
     *     tested_on:string,
     *     score:?float,
     *     listener_name:?string,
     *     course_name:?string,
     *     evaluation:?string,
     *     awqaf_exam_name:?string,
     *     awqaf_exam_result:?string
     * }  $row
     */
    protected function buildImportNotes(array $row): string
    {
        $lines = [
            'Legacy import from ajza.csv',
            'source_record_no: '.$row['record_no'],
        ];

        if ($row['listener_name']) {
            $lines[] = 'legacy_listener: '.$row['listener_name'];
        }

        if ($row['course_name']) {
            $lines[] = 'legacy_course: '.$row['course_name'];
        }

        if ($row['evaluation']) {
            $lines[] = 'legacy_evaluation: '.$row['evaluation'];
        }

        if ($row['awqaf_exam_name']) {
            $lines[] = 'legacy_awqaf_exam_name: '.$row['awqaf_exam_name'];
        }

        if ($row['awqaf_exam_result']) {
            $lines[] = 'legacy_awqaf_exam_result: '.$row['awqaf_exam_result'];
        }

        return implode(PHP_EOL, $lines);
    }

    protected function renderSummary(string $reportDir): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy rows', $this->summary['rows_total']],
                ['Valid rows', $this->summary['rows_valid_shape']],
                ['Duplicate legacy rows', $this->summary['duplicate_legacy_rows']],
                ['Invalid rows', $this->summary['invalid_rows']],
                ['Invalid juz numbers', $this->summary['invalid_juz_numbers']],
                ['Unmatched students', $this->summary['unmatched_students']],
                ['Ambiguous students', $this->summary['ambiguous_students']],
                ['Skipped existing final tests', $this->summary['skipped_existing_final_tests']],
                ['Skipped unresolved partial tests', $this->summary['skipped_unresolved_partial_tests']],
                ['Imported partial tests', $this->summary['imported_partial_tests']],
                ['Reused passed partial tests', $this->summary['reused_partial_tests']],
                ['Imported final tests', $this->summary['imported_final_tests']],
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

        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'invalid_rows.csv', ['record_no', 'student_name', 'juz_number', 'tested_on', 'reason'], $this->reportRows['invalid_rows']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'duplicate_legacy_rows.csv', ['record_no', 'student_name', 'juz_number', 'tested_on', 'first_record_no'], $this->reportRows['duplicate_legacy_rows']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'unmatched_students.csv', ['record_no', 'student_name', 'juz_number', 'tested_on'], $this->reportRows['unmatched_students']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'ambiguous_students.csv', ['record_no', 'student_name', 'juz_number', 'tested_on', 'candidate_students'], $this->reportRows['ambiguous_students']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'skipped_existing_final_tests.csv', ['record_no', 'student_name', 'student_number', 'juz_number', 'tested_on', 'existing_final_test'], $this->reportRows['skipped_existing_final_tests']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'skipped_unresolved_partial_tests.csv', ['record_no', 'student_name', 'student_number', 'juz_number', 'tested_on', 'existing_partial_test'], $this->reportRows['skipped_unresolved_partial_tests']);
        $this->writeCsvReport($reportDir.DIRECTORY_SEPARATOR.'imported_tests.csv', ['student_id', 'student_number', 'student_name', 'enrollment_id', 'juz_number', 'tested_on', 'score', 'partial_test', 'final_test_id', 'source_record_no'], $this->reportRows['imported_tests']);
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

    protected function rowValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    protected function parsePositiveInt(mixed $value): ?int
    {
        $value = $this->cleanString($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    protected function parseScore(mixed $value): ?float
    {
        $value = $this->cleanString($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $score = (float) $value;

        return $score >= 0 && $score <= 100 ? $score : null;
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

        return storage_path('app/legacy-quran-ajza-import/'.now()->format('Ymd-His').'-'.Str::random(6));
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
