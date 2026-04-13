<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\PointTransaction;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use Illuminate\Support\Facades\DB;

class MemorizationService
{
    public function __construct(
        protected PointLedgerService $ledger,
    ) {
    }

    public function saveSession(Enrollment $enrollment, array $validated, ?MemorizationSession $session = null): MemorizationSession
    {
        return DB::transaction(function () use ($enrollment, $validated, $session): MemorizationSession {
            $pageNumbers = range((int) $validated['from_page'], (int) $validated['to_page']);

            $payload = [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'teacher_id' => $validated['teacher_id'],
                'recorded_on' => $validated['recorded_on'],
                'entry_type' => $validated['entry_type'],
                'from_page' => (int) $validated['from_page'],
                'to_page' => (int) $validated['to_page'],
                'pages_count' => count($pageNumbers),
                'notes' => $validated['notes'] ?: null,
            ];

            if ($session) {
                $session->update($payload);
                $session->pages()->delete();
            } else {
                $session = MemorizationSession::query()->create($payload);
            }

            MemorizationSessionPage::query()->insert(
                collect($pageNumbers)->map(fn (int $pageNo) => [
                    'memorization_session_id' => $session->id,
                    'page_no' => $pageNo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );

            $this->rebuildStudentAchievementsAndPoints($enrollment->student()->firstOrFail());

            return $session->fresh(['pages', 'teacher']);
        });
    }

    public function rebuildStudentAchievementsAndPoints(Student $student): void
    {
        DB::transaction(function () use ($student): void {
            StudentPageAchievement::query()
                ->where('student_id', $student->id)
                ->delete();

            PointTransaction::query()
                ->where('student_id', $student->id)
                ->where('source_type', 'memorization_session')
                ->whereNull('voided_at')
                ->update([
                    'voided_at' => now(),
                    'voided_by' => auth()->id(),
                    'void_reason' => __('workflow.memorization.messages.void_reason'),
                ]);

            $sessions = MemorizationSession::query()
                ->with(['pages', 'enrollment.student'])
                ->where('student_id', $student->id)
                ->orderBy('recorded_on')
                ->orderBy('id')
                ->get();

            $seenPages = [];
            $achievementRows = [];
            $dailyRewards = [];

            foreach ($sessions as $session) {
                if ($session->entry_type === 'review') {
                    continue;
                }

                $pageNumbers = $session->pages
                    ->pluck('page_no')
                    ->sort()
                    ->values()
                    ->all();

                $newPages = [];

                foreach ($pageNumbers as $pageNo) {
                    if (isset($seenPages[$pageNo])) {
                        continue;
                    }

                    $seenPages[$pageNo] = true;
                    $newPages[] = $pageNo;
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

                if (! $newPages) {
                    continue;
                }

                $enrollment = $session->enrollment ?? Enrollment::query()->with('student')->find($session->enrollment_id);
                $newPageCount = count($newPages);

                if (! $enrollment) {
                    continue;
                }

                $rewardKey = $session->recorded_on->toDateString().'|'.$enrollment->id;
                $dailyRewards[$rewardKey] ??= [
                    'enrollment' => $enrollment,
                    'new_page_count' => 0,
                    'source_id' => $session->id,
                ];
                $dailyRewards[$rewardKey]['new_page_count'] += $newPageCount;
                $dailyRewards[$rewardKey]['source_id'] = $session->id;
            }

            foreach ($dailyRewards as $reward) {
                /** @var Enrollment $enrollment */
                $enrollment = $reward['enrollment'];
                $newPageCount = $reward['new_page_count'];
                $policy = $this->ledger->resolvePolicy(
                    'memorization',
                    'page',
                    $enrollment?->student?->grade_level_id,
                    $newPageCount,
                );

                if ($policy?->pointType) {
                    $policyHasRange = $policy->from_value !== null || $policy->to_value !== null;

                    $this->ledger->recordAutomaticPoints(
                        $enrollment,
                        'memorization_session',
                        $reward['source_id'],
                        $policy->pointType,
                        $policy,
                        $policyHasRange ? $policy->points : $policy->points * $newPageCount,
                        __('workflow.memorization.messages.automatic_reward', ['count' => $newPageCount]),
                    );
                }
            }

            if ($achievementRows) {
                StudentPageAchievement::query()->insert($achievementRows);
            }

            if (empty($seenPages) && $student->quran_current_juz_id !== null) {
                $student->update(['quran_current_juz_id' => null]);
            }

            Enrollment::query()
                ->with('student')
                ->where('student_id', $student->id)
                ->get()
                ->each(fn (Enrollment $enrollment) => $this->ledger->syncEnrollmentCaches($enrollment));
        });
    }
}
