<?php

namespace App\Services;

use App\Models\GradeLevel;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class StudentGradePromotionService
{
    public function promoteAll(): array
    {
        $activeGradeLevels = GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id']);

        $unassignedStudents = Student::query()->whereNull('grade_level_id')->count();

        if ($activeGradeLevels->count() < 2) {
            return [
                'active_grade_levels' => $activeGradeLevels->count(),
                'promoted' => 0,
                'retained' => Student::query()->whereNotNull('grade_level_id')->count(),
                'unassigned' => $unassignedStudents,
            ];
        }

        $activeGradeIds = $activeGradeLevels->pluck('id');
        $highestGradeId = (int) $activeGradeIds->last();
        $highestGradeStudents = Student::query()->where('grade_level_id', $highestGradeId)->count();
        $outsideActiveLevels = Student::query()
            ->whereNotNull('grade_level_id')
            ->whereNotIn('grade_level_id', $activeGradeIds)
            ->count();

        $promoted = 0;

        DB::transaction(function () use ($activeGradeLevels, &$promoted): void {
            for ($index = $activeGradeLevels->count() - 2; $index >= 0; $index--) {
                $fromId = (int) $activeGradeLevels[$index]->id;
                $toId = (int) $activeGradeLevels[$index + 1]->id;

                $promoted += Student::query()
                    ->where('grade_level_id', $fromId)
                    ->update(['grade_level_id' => $toId]);
            }
        });

        return [
            'active_grade_levels' => $activeGradeLevels->count(),
            'promoted' => $promoted,
            'retained' => $highestGradeStudents + $outsideActiveLevels,
            'unassigned' => $unassignedStudents,
        ];
    }
}
