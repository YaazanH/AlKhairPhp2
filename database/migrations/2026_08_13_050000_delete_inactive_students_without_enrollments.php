<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('students') || ! Schema::hasTable('enrollments')) {
            return;
        }

        DB::transaction(function (): void {
            $students = DB::table('students')
                ->whereNull('deleted_at')
                ->where('status', 'inactive')
                ->whereNotExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('enrollments')
                        ->whereColumn('enrollments.student_id', 'students.id');
                })
                ->get(['id', 'user_id']);

            if ($students->isEmpty()) {
                return;
            }

            $studentIds = $students->pluck('id');
            $linkedUserIds = $students->pluck('user_id')->filter()->unique()->values();

            DB::table('students')
                ->whereIn('id', $studentIds)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($linkedUserIds->isEmpty() || ! Schema::hasTable('users')) {
                return;
            }

            DB::table('users')
                ->whereIn('id', $linkedUserIds)
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('students')
                    ->whereNull('students.deleted_at')
                    ->whereColumn('students.user_id', 'users.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('parents')
                    ->whereNull('parents.deleted_at')
                    ->whereColumn('parents.user_id', 'users.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('teachers')
                    ->whereNull('teachers.deleted_at')
                    ->whereColumn('teachers.user_id', 'users.id'))
                ->delete();
        });
    }

    public function down(): void
    {
        // This intentional cleanup cannot safely infer which records should be restored.
    }
};
