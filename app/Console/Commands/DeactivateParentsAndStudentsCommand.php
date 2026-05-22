<?php

namespace App\Console\Commands;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeactivateParentsAndStudentsCommand extends Command
{
    protected $signature = 'people:deactivate-parents-students
        {--dry-run : Preview how many parent, student, and linked user records would be deactivated}
        {--profiles-only : Deactivate parent and student profiles only, and leave linked user accounts active}';

    protected $description = 'Mark all non-deleted parents and students inactive, and optionally disable their linked user accounts.';

    public function handle(): int
    {
        $deactivateUsers = ! (bool) $this->option('profiles-only');

        $parentsToDeactivate = ParentProfile::query()
            ->where('is_active', true);

        $studentsToDeactivate = Student::query()
            ->where('status', '!=', 'inactive');

        $linkedUsersToDeactivate = $this->linkedUsersQuery();

        $parentCount = (clone $parentsToDeactivate)->count();
        $studentCount = (clone $studentsToDeactivate)->count();
        $userCount = $deactivateUsers ? (clone $linkedUsersToDeactivate)->count() : 0;

        if ($this->option('dry-run')) {
            $this->renderSummary($parentCount, $studentCount, $userCount, $deactivateUsers);
            $this->warn('Dry run enabled: no records were updated.');

            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $parentsToDeactivate,
            $studentsToDeactivate,
            $linkedUsersToDeactivate,
            $deactivateUsers
        ): void {
            $parentsToDeactivate->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            $studentsToDeactivate->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

            if ($deactivateUsers) {
                $linkedUsersToDeactivate->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            }
        });

        $this->renderSummary($parentCount, $studentCount, $userCount, $deactivateUsers);
        $this->info('Parents and students were marked inactive successfully.');

        return self::SUCCESS;
    }

    protected function renderSummary(int $parentCount, int $studentCount, int $userCount, bool $deactivateUsers): void
    {
        $rows = [
            ['Parents to deactivate', $parentCount],
            ['Students to deactivate', $studentCount],
        ];

        if ($deactivateUsers) {
            $rows[] = ['Linked users to deactivate', $userCount];
        } else {
            $rows[] = ['Linked users to deactivate', 'Skipped (--profiles-only)'];
        }

        $this->table(['Target', 'Count'], $rows);
    }

    protected function linkedUsersQuery()
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereHas('parentProfile')
                    ->orWhereHas('studentProfile');
            });
    }
}
