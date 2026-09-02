<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\Student;
use App\Services\PointLedgerService;
use App\Services\StudentNumberService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearStudentDataCommand extends Command
{
    protected $signature = 'students:clear-data
        {--all : Target all students}
        {--student-number-from= : Start of the student number range}
        {--student-number-to= : End of the student number range}
        {--course-id= : Target students with an active enrolment in this course}
        {--group-id= : Target students with an active enrolment in this group}
        {--clear-parents : Remove parent links from the selected students}
        {--delete-parents : Soft delete parent profiles that become detached, and delete their linked user accounts}
        {--clear-points : Delete point transactions for the selected students}
        {--dry-run : Preview the affected records without changing anything}';

    protected $description = 'Clear parent links and/or point transactions for selected students.';

    public function handle(StudentNumberService $studentNumbers, PointLedgerService $ledger): int
    {
        if (! $this->option('clear-parents') && ! $this->option('clear-points') && ! $this->option('delete-parents')) {
            $this->error('Choose at least one action: --clear-parents, --delete-parents, and/or --clear-points.');

            return self::FAILURE;
        }

        if ($this->option('delete-parents') && ! $this->option('clear-parents')) {
            $this->error('Use --delete-parents together with --clear-parents so the selected students are unlinked first.');

            return self::FAILURE;
        }

        if ($this->option('clear-parents') && ! $this->canNullStudentParentLinks()) {
            $this->error('The students.parent_id column is still required in this database. Run `php artisan migrate` first, then rerun this command.');

            return self::FAILURE;
        }

        $scope = $this->resolveScope($studentNumbers);

        if (! $scope['valid']) {
            return self::FAILURE;
        }

        $query = $this->targetStudentsQuery($scope);
        $studentIds = (clone $query)->pluck('id')->all();
        $targetParentIds = $this->targetParentIdsForDeletion($scope, $studentIds);

        $parentLinksToClear = $this->option('clear-parents')
            ? Student::query()->whereIn('id', $studentIds)->whereNotNull('parent_id')->count()
            : 0;
        $studentsToDeactivate = $this->option('clear-parents')
            ? Student::query()->whereIn('id', $studentIds)->where('status', 'active')->count()
            : 0;
        [$detachedParentsToDelete, $parentAccountsToDelete, $parentsKept] = $this->deletableParentSummary($targetParentIds, $studentIds);
        $pointTransactionsToDelete = $this->option('clear-points')
            ? PointTransaction::query()->whereIn('student_id', $studentIds)->count()
            : 0;
        $enrollmentIds = $this->option('clear-points')
            ? Enrollment::query()->whereIn('student_id', $studentIds)->pluck('id')->all()
            : [];

        $this->renderSummary(
            $scope['label'],
            count($studentIds),
            $parentLinksToClear,
            $studentsToDeactivate,
            $detachedParentsToDelete,
            $parentAccountsToDelete,
            $parentsKept,
            $pointTransactionsToDelete,
            count($enrollmentIds),
            (bool) $this->option('dry-run'),
        );

        if ($studentIds === [] && (! $this->option('delete-parents') || $targetParentIds === [])) {
            $this->warn('No students matched the selected scope.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run enabled: no records were updated.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($studentIds, $targetParentIds): void {
            if ($this->option('clear-parents')) {
                if ($studentIds !== []) {
                    Student::query()
                        ->whereIn('id', $studentIds)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'inactive',
                            'updated_at' => now(),
                        ]);

                    Student::query()
                        ->whereIn('id', $studentIds)
                        ->whereNotNull('parent_id')
                        ->update([
                            'parent_id' => null,
                            'updated_at' => now(),
                        ]);
                }

                if ($this->option('delete-parents') && $targetParentIds !== []) {
                    $parents = ParentProfile::query()
                        ->with('user')
                        ->withCount('students')
                        ->whereIn('id', $targetParentIds)
                        ->get();

                    foreach ($parents as $parent) {
                        if ($parent->students_count > 0) {
                            continue;
                        }

                        $user = $parent->user;

                        $parent->delete();
                        $user?->delete();
                    }
                }
            }

            if ($this->option('clear-points')) {
                PointTransaction::query()
                    ->whereIn('student_id', $studentIds)
                    ->delete();
            }
        });

        if ($this->option('clear-points') && $enrollmentIds !== []) {
            Enrollment::query()
                ->with('student')
                ->whereIn('id', $enrollmentIds)
                ->orderBy('id')
                ->chunkById(100, function ($enrollments) use ($ledger): void {
                    foreach ($enrollments as $enrollment) {
                        $ledger->syncEnrollmentCaches($enrollment);
                    }
                });
        }

        $this->info('Student data cleanup completed successfully.');

        return self::SUCCESS;
    }

    protected function resolveScope(StudentNumberService $studentNumbers): array
    {
        $all = (bool) $this->option('all');
        $hasNumberRange = filled($this->option('student-number-from')) || filled($this->option('student-number-to'));
        $hasCourse = filled($this->option('course-id'));
        $hasGroup = filled($this->option('group-id'));

        $selectedScopes = collect([$all, $hasNumberRange, $hasCourse, $hasGroup])
            ->filter(fn (bool $selected) => $selected)
            ->count();

        if ($selectedScopes !== 1) {
            $this->error('Choose exactly one scope: --all, a student number range, --course-id, or --group-id.');

            return ['valid' => false];
        }

        if ($all) {
            return [
                'label' => 'All students',
                'type' => 'all',
                'valid' => true,
            ];
        }

        if ($hasNumberRange) {
            $from = $studentNumbers->parseInputToId($this->option('student-number-from'));
            $to = $studentNumbers->parseInputToId($this->option('student-number-to'));

            if (
                (filled($this->option('student-number-from')) && $from === null)
                || (filled($this->option('student-number-to')) && $to === null)
            ) {
                $this->error('The student number range is invalid.');

                return ['valid' => false];
            }

            if ($from === null && $to === null) {
                $this->error('Provide at least one student number boundary.');

                return ['valid' => false];
            }

            if ($from !== null && $to !== null && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            return [
                'from' => $from,
                'label' => 'Student number range',
                'to' => $to,
                'type' => 'student_number_range',
                'valid' => true,
            ];
        }

        if ($hasCourse) {
            $courseId = (int) $this->option('course-id');

            if ($courseId < 1 || ! Course::query()->whereKey($courseId)->exists()) {
                $this->error('The selected course does not exist.');

                return ['valid' => false];
            }

            return [
                'course_id' => $courseId,
                'label' => 'Course #'.$courseId,
                'type' => 'course',
                'valid' => true,
            ];
        }

        $groupId = (int) $this->option('group-id');

        if ($groupId < 1 || ! Group::query()->whereKey($groupId)->exists()) {
            $this->error('The selected group does not exist.');

            return ['valid' => false];
        }

        return [
            'group_id' => $groupId,
            'label' => 'Group #'.$groupId,
            'type' => 'group',
            'valid' => true,
        ];
    }

    protected function targetStudentsQuery(array $scope): Builder
    {
        $query = Student::query();

        if ($scope['type'] === 'student_number_range') {
            if ($scope['from'] !== null) {
                $query->where('id', '>=', $scope['from']);
            }

            if ($scope['to'] !== null) {
                $query->where('id', '<=', $scope['to']);
            }

            return $query;
        }

        if ($scope['type'] === 'course') {
            return $query->whereHas('enrollments', function (Builder $enrollmentQuery) use ($scope): void {
                $enrollmentQuery
                    ->where('status', 'active')
                    ->whereHas('group', fn (Builder $groupQuery) => $groupQuery->where('course_id', $scope['course_id']));
            });
        }

        if ($scope['type'] === 'group') {
            return $query->whereHas('enrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery
                ->where('status', 'active')
                ->where('group_id', $scope['group_id']));
        }

        return $query;
    }

    protected function renderSummary(
        string $scopeLabel,
        int $targetStudents,
        int $parentLinksToClear,
        int $studentsToDeactivate,
        int $detachedParentsToDelete,
        int $parentAccountsToDelete,
        int $parentsKept,
        int $pointTransactionsToDelete,
        int $enrollmentsToResync,
        bool $dryRun,
    ): void {
        $rows = [
            ['Scope', $scopeLabel],
            ['Target students', $targetStudents],
            ['Parent links to clear', $this->option('clear-parents') ? $parentLinksToClear : 'Skipped'],
            ['Students forced inactive', $this->option('clear-parents') ? $studentsToDeactivate : 'Skipped'],
            ['Detached parents to delete', $this->option('delete-parents') ? $detachedParentsToDelete : 'Skipped'],
            ['Parent accounts to delete', $this->option('delete-parents') ? $parentAccountsToDelete : 'Skipped'],
            ['Parents kept with other students', $this->option('delete-parents') ? $parentsKept : 'Skipped'],
            ['Point transactions to delete', $this->option('clear-points') ? $pointTransactionsToDelete : 'Skipped'],
            ['Enrolments to refresh', $this->option('clear-points') ? $enrollmentsToResync : 'Skipped'],
            ['Mode', $dryRun ? 'Dry run' : 'Apply changes'],
        ];

        $this->table(['Target', 'Count'], $rows);
    }

    protected function deletableParentSummary(array $targetParentIds, array $studentIds): array
    {
        if (! $this->option('delete-parents') || $targetParentIds === []) {
            return [0, 0, 0];
        }

        $remainingCounts = Student::query()
            ->whereIn('parent_id', $targetParentIds)
            ->whereNotIn('id', $studentIds)
            ->selectRaw('parent_id, count(*) as remaining_count')
            ->groupBy('parent_id')
            ->pluck('remaining_count', 'parent_id');

        $parents = ParentProfile::query()
            ->whereIn('id', $targetParentIds)
            ->get(['id', 'user_id']);

        $deletableParents = $parents->filter(fn (ParentProfile $parent) => (int) ($remainingCounts[$parent->id] ?? 0) === 0);
        $keptParents = $parents->count() - $deletableParents->count();

        return [
            $deletableParents->count(),
            $deletableParents->whereNotNull('user_id')->count(),
            $keptParents,
        ];
    }

    protected function targetParentIdsForDeletion(array $scope, array $studentIds): array
    {
        if (! $this->option('clear-parents')) {
            return [];
        }

        if ($this->option('delete-parents') && $scope['type'] === 'all') {
            return ParentProfile::query()
                ->pluck('id')
                ->all();
        }

        return Student::query()
            ->whereIn('id', $studentIds)
            ->whereNotNull('parent_id')
            ->pluck('parent_id')
            ->unique()
            ->values()
            ->all();
    }

    protected function canNullStudentParentLinks(): bool
    {
        $column = collect(Schema::getColumns('students'))
            ->first(fn (array $definition) => strtolower((string) ($definition['name'] ?? '')) === 'parent_id');

        return (bool) ($column['nullable'] ?? false);
    }
}
