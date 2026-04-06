<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = CarbonImmutable::now();
        $academicYearStart = $now->month >= 8
            ? CarbonImmutable::create($now->year, 8, 1)
            : CarbonImmutable::create($now->year - 1, 8, 1);
        $academicYearEnd = $academicYearStart->addYear()->subDay();

        DB::table('academic_years')->upsert([
            [
                'name' => sprintf('%d/%d', $academicYearStart->year, $academicYearEnd->year),
                'starts_on' => $academicYearStart->toDateString(),
                'ends_on' => $academicYearEnd->toDateString(),
                'is_current' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['name'], ['starts_on', 'ends_on', 'is_current', 'is_active', 'updated_at']);

        DB::table('grade_levels')->upsert([
            ['name' => 'Pre-K', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Kindergarten', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 1', 'sort_order' => 11, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 2', 'sort_order' => 12, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 3', 'sort_order' => 13, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 4', 'sort_order' => 14, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 5', 'sort_order' => 15, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 6', 'sort_order' => 16, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 7', 'sort_order' => 17, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 8', 'sort_order' => 18, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 9', 'sort_order' => 19, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 10', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 11', 'sort_order' => 21, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Grade 12', 'sort_order' => 22, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'University', 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Other', 'sort_order' => 99, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['name'], ['sort_order', 'is_active', 'updated_at']);

        DB::table('attendance_statuses')->upsert([
            ['name' => 'Present', 'code' => 'present', 'scope' => 'both', 'default_points' => 0, 'color' => 'green', 'is_present' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Absent', 'code' => 'absent', 'scope' => 'both', 'default_points' => 0, 'color' => 'red', 'is_present' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Late', 'code' => 'late', 'scope' => 'both', 'default_points' => 0, 'color' => 'amber', 'is_present' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Excused', 'code' => 'excused', 'scope' => 'both', 'default_points' => 0, 'color' => 'blue', 'is_present' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Early Leave', 'code' => 'early-leave', 'scope' => 'student', 'default_points' => 0, 'color' => 'orange', 'is_present' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'scope', 'default_points', 'color', 'is_present', 'is_active', 'updated_at']);

        DB::table('assessment_types')->upsert([
            ['name' => 'Exam', 'code' => 'exam', 'is_scored' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Quiz', 'code' => 'quiz', 'is_scored' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Worksheet', 'code' => 'worksheet', 'is_scored' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'is_scored', 'is_active', 'updated_at']);

        DB::table('quran_test_types')->upsert([
            ['name' => 'Partial', 'code' => 'partial', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Final', 'code' => 'final', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Awqaf', 'code' => 'awqaf', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'sort_order', 'is_active', 'updated_at']);

        DB::table('point_types')->upsert([
            ['name' => 'Memorization Page', 'code' => 'memorization-page', 'category' => 'memorization', 'default_points' => 0, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Attendance Present', 'code' => 'attendance-present', 'category' => 'attendance', 'default_points' => 0, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Attendance Late', 'code' => 'attendance-late', 'category' => 'attendance', 'default_points' => 0, 'allow_manual_entry' => false, 'allow_negative' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Quiz Score', 'code' => 'quiz-score', 'category' => 'assessment', 'default_points' => 0, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Worksheet Score', 'code' => 'worksheet-score', 'category' => 'assessment', 'default_points' => 0, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Exam Score', 'code' => 'exam-score', 'category' => 'assessment', 'default_points' => 0, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Bonus', 'code' => 'bonus', 'category' => 'manual', 'default_points' => 0, 'allow_manual_entry' => true, 'allow_negative' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Penalty', 'code' => 'penalty', 'category' => 'manual', 'default_points' => 0, 'allow_manual_entry' => true, 'allow_negative' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'category', 'default_points', 'allow_manual_entry', 'allow_negative', 'is_active', 'updated_at']);

        $pointTypeIds = DB::table('point_types')
            ->whereIn('code', ['memorization-page', 'attendance-present', 'attendance-late'])
            ->pluck('id', 'code');

        foreach ([
            [
                'point_type_id' => $pointTypeIds['memorization-page'] ?? null,
                'name' => 'Memorization Page Reward',
                'source_type' => 'memorization',
                'trigger_key' => 'page',
                'grade_level_id' => null,
                'from_value' => null,
                'to_value' => null,
                'points' => 1,
                'priority' => 100,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'point_type_id' => $pointTypeIds['attendance-present'] ?? null,
                'name' => 'Attendance Present Reward',
                'source_type' => 'attendance',
                'trigger_key' => 'present',
                'grade_level_id' => null,
                'from_value' => null,
                'to_value' => null,
                'points' => 2,
                'priority' => 100,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'point_type_id' => $pointTypeIds['attendance-late'] ?? null,
                'name' => 'Attendance Late Reward',
                'source_type' => 'attendance',
                'trigger_key' => 'late',
                'grade_level_id' => null,
                'from_value' => null,
                'to_value' => null,
                'points' => 1,
                'priority' => 100,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ] as $policy) {
            DB::table('point_policies')->updateOrInsert(
                [
                    'source_type' => $policy['source_type'],
                    'trigger_key' => $policy['trigger_key'],
                    'priority' => $policy['priority'],
                ],
                $policy,
            );
        }

        $assessmentTypeIds = DB::table('assessment_types')
            ->whereIn('code', ['exam', 'quiz', 'worksheet'])
            ->pluck('id', 'code');

        $assessmentPointTypeIds = DB::table('point_types')
            ->whereIn('code', ['exam-score', 'quiz-score', 'worksheet-score'])
            ->pluck('id', 'code');

        foreach ([
            ['assessment_type_id' => $assessmentTypeIds['exam'] ?? null, 'name' => 'Exam Excellent', 'from_mark' => 90, 'to_mark' => 100, 'point_type_id' => $assessmentPointTypeIds['exam-score'] ?? null, 'points' => 10, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['exam'] ?? null, 'name' => 'Exam Good', 'from_mark' => 75, 'to_mark' => 89.99, 'point_type_id' => $assessmentPointTypeIds['exam-score'] ?? null, 'points' => 6, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['exam'] ?? null, 'name' => 'Exam Pass', 'from_mark' => 60, 'to_mark' => 74.99, 'point_type_id' => $assessmentPointTypeIds['exam-score'] ?? null, 'points' => 3, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['exam'] ?? null, 'name' => 'Exam Fail', 'from_mark' => 0, 'to_mark' => 59.99, 'point_type_id' => $assessmentPointTypeIds['exam-score'] ?? null, 'points' => 0, 'is_fail' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['quiz'] ?? null, 'name' => 'Quiz Excellent', 'from_mark' => 90, 'to_mark' => 100, 'point_type_id' => $assessmentPointTypeIds['quiz-score'] ?? null, 'points' => 6, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['quiz'] ?? null, 'name' => 'Quiz Good', 'from_mark' => 75, 'to_mark' => 89.99, 'point_type_id' => $assessmentPointTypeIds['quiz-score'] ?? null, 'points' => 4, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['quiz'] ?? null, 'name' => 'Quiz Pass', 'from_mark' => 60, 'to_mark' => 74.99, 'point_type_id' => $assessmentPointTypeIds['quiz-score'] ?? null, 'points' => 2, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['quiz'] ?? null, 'name' => 'Quiz Fail', 'from_mark' => 0, 'to_mark' => 59.99, 'point_type_id' => $assessmentPointTypeIds['quiz-score'] ?? null, 'points' => 0, 'is_fail' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['worksheet'] ?? null, 'name' => 'Worksheet Excellent', 'from_mark' => 90, 'to_mark' => 100, 'point_type_id' => $assessmentPointTypeIds['worksheet-score'] ?? null, 'points' => 4, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['worksheet'] ?? null, 'name' => 'Worksheet Good', 'from_mark' => 75, 'to_mark' => 89.99, 'point_type_id' => $assessmentPointTypeIds['worksheet-score'] ?? null, 'points' => 3, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['worksheet'] ?? null, 'name' => 'Worksheet Pass', 'from_mark' => 60, 'to_mark' => 74.99, 'point_type_id' => $assessmentPointTypeIds['worksheet-score'] ?? null, 'points' => 1, 'is_fail' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['assessment_type_id' => $assessmentTypeIds['worksheet'] ?? null, 'name' => 'Worksheet Fail', 'from_mark' => 0, 'to_mark' => 59.99, 'point_type_id' => $assessmentPointTypeIds['worksheet-score'] ?? null, 'points' => 0, 'is_fail' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ] as $scoreBand) {
            DB::table('assessment_score_bands')->updateOrInsert(
                [
                    'assessment_type_id' => $scoreBand['assessment_type_id'],
                    'name' => $scoreBand['name'],
                ],
                $scoreBand,
            );
        }

        DB::table('payment_methods')->upsert([
            ['name' => 'Cash', 'code' => 'cash', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Bank Transfer', 'code' => 'bank-transfer', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Card', 'code' => 'card', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'is_active', 'updated_at']);

        DB::table('expense_categories')->upsert([
            ['name' => 'Transport', 'code' => 'transport', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Supplies', 'code' => 'supplies', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Prizes', 'code' => 'prizes', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Refreshments', 'code' => 'refreshments', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Facility', 'code' => 'facility', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Other', 'code' => 'other', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'is_active', 'updated_at']);

        DB::table('app_settings')->upsert([
            ['group' => 'app', 'key' => 'school_name', 'value' => 'Alkhair', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'app', 'key' => 'default_currency', 'value' => 'USD', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'app', 'key' => 'timezone', 'value' => 'Asia/Damascus', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'finance', 'key' => 'invoice_prefix', 'value' => 'INV', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
        ], ['group', 'key'], ['value', 'type', 'updated_at']);
    }
}
