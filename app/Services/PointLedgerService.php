<?php

namespace App\Services;

use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\PointPolicy;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PointLedgerService
{
    public const ATTENDANCE_POINT_TYPE_CODE = 'system-attendance';

    public function resolvePolicy(string $sourceType, string $triggerKey, ?int $gradeLevelId = null, ?float $value = null): ?PointPolicy
    {
        return PointPolicy::query()
            ->where('source_type', $sourceType)
            ->where('trigger_key', $triggerKey)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('grade_level_id')
                ->orWhere('grade_level_id', $gradeLevelId))
            ->when($value !== null, fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('from_value')
                    ->orWhere('from_value', '<=', $value))
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('to_value')
                    ->orWhere('to_value', '>=', $value)))
            ->orderByRaw('case when grade_level_id is null then 1 else 0 end')
            ->orderByDesc('priority')
            ->first();
    }

    public function recordAutomaticPoints(Enrollment $enrollment, string $sourceType, int $sourceId, PointType $pointType, ?PointPolicy $policy, int $points, ?string $notes = null): ?PointTransaction
    {
        if ($points === 0) {
            return null;
        }

        return PointTransaction::query()->create([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'point_type_id' => $pointType->id,
            'policy_id' => $policy?->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'points' => $points,
            'entered_by' => auth()->id(),
            'entered_at' => now(),
            'notes' => $notes,
        ]);
    }

    public function recordAttendanceStatusPoints(Enrollment $enrollment, string $sourceType, int $sourceId, AttendanceStatus $status, ?string $notes = null): ?PointTransaction
    {
        $points = (int) $status->default_points;

        if ($points === 0) {
            return null;
        }

        return $this->recordAutomaticPoints(
            $enrollment,
            $sourceType,
            $sourceId,
            $this->attendancePointType(),
            null,
            $points,
            $notes,
        );
    }

    public function voidSourceTransactions(string $sourceType, int $sourceId, ?string $reason = null): void
    {
        PointTransaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('voided_at')
            ->update([
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
            ]);
    }

    public function syncEnrollmentCaches(Enrollment $enrollment): void
    {
        $points = PointTransaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereNull('voided_at')
            ->sum('points');

        $memorizedPages = DB::table('memorization_session_pages')
            ->join('memorization_sessions', 'memorization_sessions.id', '=', 'memorization_session_pages.memorization_session_id')
            ->where('memorization_sessions.enrollment_id', $enrollment->id)
            ->distinct()
            ->count('memorization_session_pages.page_no');

        $enrollment->update([
            'final_points_cached' => (int) $points,
            'memorized_pages_cached' => (int) $memorizedPages,
        ]);

        $this->syncStudentCurrentJuz($enrollment->student);
    }

    protected function attendancePointType(): PointType
    {
        return PointType::query()->firstOrCreate(
            ['code' => self::ATTENDANCE_POINT_TYPE_CODE],
            [
                'name' => 'Attendance',
                'category' => 'system',
                'default_points' => 0,
                'allow_manual_entry' => false,
                'allow_negative' => true,
                'is_active' => true,
            ],
        );
    }

    public function syncStudentCurrentJuz(Student $student): void
    {
        $maxPage = $student->pageAchievements()->max('page_no');

        if (! $maxPage) {
            return;
        }

        $juz = QuranJuz::query()
            ->where('from_page', '<=', $maxPage)
            ->where('to_page', '>=', $maxPage)
            ->first();

        if ($juz && $student->quran_current_juz_id !== $juz->id) {
            $student->update([
                'quran_current_juz_id' => $juz->id,
            ]);
        }
    }
}
