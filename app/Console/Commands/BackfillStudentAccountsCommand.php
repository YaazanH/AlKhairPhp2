<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Group;
use App\Models\Student;
use App\Services\ManagedUserService;
use App\Services\StudentNumberService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BackfillStudentAccountsCommand extends Command
{
    protected $signature = 'students:backfill-accounts
        {--all : Target all students}
        {--student-number-from= : Start of the student number range}
        {--student-number-to= : End of the student number range}
        {--course-id= : Target students with an active enrollment in this course}
        {--group-id= : Target students with an active enrollment in this group}
        {--include-inactive : Also create accounts for inactive, blocked, or graduated students}
        {--dry-run : Preview the missing student accounts without creating users}';

    protected $description = 'Create missing linked user accounts for selected students after legacy imports.';

    public function handle(StudentNumberService $studentNumbers, ManagedUserService $managedUsers): int
    {
        $scope = $this->resolveScope($studentNumbers);

        if (! $scope['valid']) {
            return self::FAILURE;
        }

        $targetStudentsQuery = $this->targetStudentsQuery($scope);

        if (! $this->option('include-inactive')) {
            $targetStudentsQuery->where('status', 'active');
        }

        $selectedStudentIds = $targetStudentsQuery->pluck('id')->all();

        $studentsMissingAccounts = Student::query()
            ->whereIn('id', $selectedStudentIds)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('user_id')
                    ->orWhereDoesntHave('user');
            });

        $selectedCount = count($selectedStudentIds);
        $missingCount = (clone $studentsMissingAccounts)->count();
        $existingCount = $selectedCount - $missingCount;

        $this->table(['Target', 'Count'], [
            ['Scope', $scope['label']],
            ['Student statuses', $this->option('include-inactive') ? 'All statuses' : 'Active only'],
            ['Selected students', $selectedCount],
            ['Students missing accounts', $missingCount],
            ['Students already linked', $existingCount],
            ['Mode', $this->option('dry-run') ? 'Dry run' : 'Create accounts'],
        ]);

        if ($selectedCount === 0) {
            $this->warn('No students matched the selected scope.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run enabled: no user accounts were created.');

            return self::SUCCESS;
        }

        $createdAccounts = 0;

        DB::transaction(function () use ($managedUsers, $studentNumbers, $studentsMissingAccounts, &$createdAccounts): void {
            $studentsMissingAccounts
                ->with('user')
                ->orderBy('id')
                ->chunkById(100, function ($students) use ($managedUsers, $studentNumbers, &$createdAccounts): void {
                    foreach ($students as $student) {
                        $studentNumber = $student->student_number ?: $studentNumbers->formatForId((int) $student->id);

                        if ($student->student_number !== $studentNumber) {
                            $student->forceFill([
                                'student_number' => $studentNumber,
                            ])->saveQuietly();
                        }

                        $result = $managedUsers->syncLinkedUser(
                            $student->user,
                            [
                                'name' => trim($student->first_name.' '.$student->last_name),
                                'username' => $studentNumber,
                                'phone' => null,
                                'is_active' => ! in_array($student->status, ['inactive', 'blocked'], true),
                            ],
                            'student',
                        );

                        $student->user()->associate($result['user']);
                        $student->save();
                        $createdAccounts++;
                    }
                });
        });

        $this->info("Created {$createdAccounts} student accounts successfully.");
        $this->line('Passwords were stored in each linked user record as issued_password.');

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
}
