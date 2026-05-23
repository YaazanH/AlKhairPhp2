<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use Illuminate\Support\Facades\DB;

class LegacyMemorizationImportService
{
    public function rebuildStudentAchievementsAndCaches(Student $student): void
    {
        DB::transaction(function () use ($student): void {
            StudentPageAchievement::query()
                ->where('student_id', $student->id)
                ->delete();

            $sessions = MemorizationSession::query()
                ->with('pages')
                ->where('student_id', $student->id)
                ->orderBy('recorded_on')
                ->orderBy('id')
                ->get();

            $seenPages = [];
            $achievementRows = [];

            foreach ($sessions as $session) {
                if ($session->entry_type === 'review') {
                    continue;
                }

                $pageNumbers = $session->pages
                    ->pluck('page_no')
                    ->sort()
                    ->values()
                    ->all();

                foreach ($pageNumbers as $pageNo) {
                    if (isset($seenPages[$pageNo])) {
                        continue;
                    }

                    $seenPages[$pageNo] = true;
                    $achievementRows[] = [
                        'student_id' => $student->id,
                        'page_no' => $pageNo,
                        'first_enrollment_id' => $session->enrollment_id,
                        'first_session_id' => $session->id,
                        'first_recorded_on' => $session->recorded_on,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($achievementRows !== []) {
                StudentPageAchievement::query()->insert($achievementRows);
            } elseif ($student->quran_current_juz_id !== null) {
                $student->update(['quran_current_juz_id' => null]);
            }

            Enrollment::query()
                ->with('student')
                ->where('student_id', $student->id)
                ->get()
                ->each(fn (Enrollment $enrollment) => app(PointLedgerService::class)->syncEnrollmentCaches($enrollment));
        });
    }
}
