<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COURSE_NAME = 'الدورات السابقة';

    public function up(): void
    {
        $courses = DB::table('courses')
            ->orderBy('id')
            ->get()
            ->filter(fn (object $course): bool => $this->normalizedName((string) $course->name) === self::COURSE_NAME)
            ->values();

        // This also makes the migration safe on fresh databases and after a manual merge.
        if ($courses->count() < 2) {
            return;
        }

        if ($courses->count() > 2) {
            throw new RuntimeException('Expected exactly two courses named "'.self::COURSE_NAME.'"; no records were changed.');
        }

        $ranked = $courses
            ->map(fn (object $course): array => [
                'course' => $course,
                'counts' => $this->learningRecordCounts((int) $course->id),
            ])
            ->sort(function (array $left, array $right): int {
                $scoreComparison = array_sum($right['counts']) <=> array_sum($left['counts']);

                return $scoreComparison !== 0
                    ? $scoreComparison
                    : ((int) $left['course']->id <=> (int) $right['course']->id);
            })
            ->values();

        $target = $ranked[0]['course'];
        $source = $ranked[1]['course'];
        $expected = $this->sumCounts($ranked[0]['counts'], $ranked[1]['counts']);

        DB::transaction(function () use ($target, $source, $expected): void {
            $targetId = (int) $target->id;
            $sourceId = (int) $source->id;

            $this->mergeAttendanceDays($sourceId, $targetId);

            foreach (['barcode_scan_imports', 'curricula', 'student_card_prints', 'teachers'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'course_id')) {
                    DB::table($table)->where('course_id', $sourceId)->update(['course_id' => $targetId]);
                }
            }

            DB::table('groups')->where('course_id', $sourceId)->update(['course_id' => $targetId]);

            $allGroupIds = DB::table('groups')
                ->where('course_id', $targetId)
                ->pluck('id');

            DB::table('groups')
                ->whereIn('id', $allGroupIds)
                ->update(['is_active' => false]);

            DB::table('enrollments')
                ->whereIn('group_id', $allGroupIds)
                ->where('status', 'active')
                ->update([
                    'status' => 'completed',
                    'left_at' => DB::raw('COALESCE(left_at, CURRENT_DATE)'),
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('assessments')) {
                $assessmentIds = DB::table('assessments')
                    ->whereIn('group_id', $allGroupIds)
                    ->pluck('id');

                if (Schema::hasTable('assessment_groups')) {
                    $assessmentIds = $assessmentIds
                        ->merge(DB::table('assessment_groups')->whereIn('group_id', $allGroupIds)->pluck('assessment_id'))
                        ->unique();
                }

                DB::table('assessments')->whereIn('id', $assessmentIds)->update(['is_active' => false]);
            }

            $actual = $this->learningRecordCounts($targetId);

            if ($actual !== $expected) {
                throw new RuntimeException('Course merge verification failed; the transaction was rolled back.');
            }

            $startsOn = collect([$target->starts_on ?? null, $source->starts_on ?? null])->filter()->min();
            $endsOn = collect([$target->ends_on ?? null, $source->ends_on ?? null])->filter()->max();

            DB::table('courses')->where('id', $targetId)->update([
                'description' => $target->description ?: ($source->description ?? null),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'is_active' => false,
                'is_default' => false,
                'awards_points' => (bool) ($target->awards_points ?? true) || (bool) ($source->awards_points ?? true),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);

            DB::table('courses')->where('id', $sourceId)->update([
                'is_active' => false,
                'is_default' => false,
                'updated_at' => now(),
                'deleted_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // A lossless merge cannot be reliably split back into its former courses.
    }

    private function normalizedName(string $name): string
    {
        $name = str_replace('ـ', '', $name);
        $name = preg_replace('/[\s\p{Cf}]+/u', ' ', trim($name)) ?? trim($name);

        return $name;
    }

    /** @return array<string, int> */
    private function learningRecordCounts(int $courseId): array
    {
        $groupIds = DB::table('groups')->where('course_id', $courseId)->pluck('id');
        $enrollmentIds = DB::table('enrollments')->whereIn('group_id', $groupIds)->pluck('id');

        return [
            'groups' => $groupIds->count(),
            'enrollments' => $enrollmentIds->count(),
            'memorizations' => DB::table('memorization_sessions')->whereIn('enrollment_id', $enrollmentIds)->count(),
            'partial_tests' => DB::table('quran_partial_tests')->whereIn('enrollment_id', $enrollmentIds)->count(),
            'final_tests' => DB::table('quran_final_tests')->whereIn('enrollment_id', $enrollmentIds)->count(),
        ];
    }

    /** @param array<string, int> $left @param array<string, int> $right @return array<string, int> */
    private function sumCounts(array $left, array $right): array
    {
        return collect($left)
            ->mapWithKeys(fn (int $count, string $key): array => [$key => $count + $right[$key]])
            ->all();
    }

    private function mergeAttendanceDays(int $sourceCourseId, int $targetCourseId): void
    {
        if (! Schema::hasTable('student_attendance_days') || ! Schema::hasColumn('student_attendance_days', 'course_id')) {
            return;
        }

        DB::table('student_attendance_days')
            ->where('course_id', $sourceCourseId)
            ->orderBy('id')
            ->get()
            ->each(function (object $sourceDay) use ($targetCourseId): void {
                $targetDayId = DB::table('student_attendance_days')
                    ->where('course_id', $targetCourseId)
                    ->where('attendance_date', $sourceDay->attendance_date)
                    ->value('id');

                if (! $targetDayId) {
                    DB::table('student_attendance_days')
                        ->where('id', $sourceDay->id)
                        ->update(['course_id' => $targetCourseId]);

                    return;
                }

                DB::table('group_attendance_days')
                    ->where('student_attendance_day_id', $sourceDay->id)
                    ->update(['student_attendance_day_id' => $targetDayId]);

                DB::table('student_attendance_days')->where('id', $sourceDay->id)->delete();
            });
    }
};
