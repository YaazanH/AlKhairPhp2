<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\PointTransaction;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemorizationService
{
    public function __construct(
        protected PointLedgerService $ledger,
    ) {}

    public function saveSession(
        Enrollment $enrollment,
        array $validated,
        ?MemorizationSession $session = null,
        bool $skipDuplicatePages = false,
    ): MemorizationSession {
        return DB::transaction(function () use ($enrollment, $validated, $session, $skipDuplicatePages): MemorizationSession {
            $isEditing = $session !== null;
            $previousPages = $session?->pages()->pluck('page_no')->map(fn ($page) => (int) $page)->all() ?? [];
            $previousRewardKey = $session ? [$session->enrollment_id, $session->recorded_on?->toDateString()] : null;
            $previousEntryType = $session?->entry_type;
            $previousEnrollmentId = $session?->enrollment_id;
            $previousRecordedOn = $session?->recorded_on?->toDateString();
            $recordedByUserId = $validated['recorded_by_user_id'] ?? $session?->recorded_by_user_id ?? auth()->id();
            $pageNumbers = range((int) $validated['from_page'], (int) $validated['to_page']);
            $duplicatePages = $this->findDuplicatePages($enrollment, $pageNumbers, $validated['entry_type'], $session);

            if ($skipDuplicatePages) {
                $pageNumbers = array_values(array_diff($pageNumbers, $duplicatePages));
            } else {
                $this->ensurePagesAreNotDuplicated($duplicatePages);
            }

            if ($pageNumbers === []) {
                throw ValidationException::withMessages([
                    'from_page' => __('workflow.memorization.errors.all_duplicate_pages'),
                ]);
            }

            $payload = [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'teacher_id' => $validated['teacher_id'],
                'recorded_by_user_id' => $recordedByUserId,
                'recorded_on' => $validated['recorded_on'],
                'entry_type' => $validated['entry_type'],
                'from_page' => min($pageNumbers),
                'to_page' => max($pageNumbers),
                'pages_count' => count($pageNumbers),
                'notes' => ($validated['notes'] ?? null) ?: null,
            ];

            if ($session) {
                $session->update($payload);
                $session->pages()->whereNotIn('page_no', $pageNumbers)->delete();
            } else {
                $session = MemorizationSession::query()->create($payload);
            }

            MemorizationSessionPage::query()->insertOrIgnore(
                collect($pageNumbers)->map(fn (int $pageNo) => [
                    'memorization_session_id' => $session->id,
                    'page_no' => $pageNo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );

            $student = $enrollment->student()->firstOrFail();

            if ($isEditing) {
                $achievementPages = $previousEntryType !== $session->entry_type
                    || (int) $previousEnrollmentId !== (int) $session->enrollment_id
                    || $previousRecordedOn !== $session->recorded_on?->toDateString()
                        ? array_values(array_unique([...$previousPages, ...$pageNumbers]))
                        : array_values(array_merge(array_diff($previousPages, $pageNumbers), array_diff($pageNumbers, $previousPages)));
                $this->reconcileAffectedPagesAndRewards(
                    $student,
                    $achievementPages,
                    array_filter([$previousRewardKey, [$enrollment->id, $session->recorded_on?->toDateString()]]),
                );
            } else {
                $this->recordNewSessionAchievementsAndPoints($enrollment, $session, $student, $pageNumbers);
            }

            return $session->fresh(['pages', 'teacher']);
        });
    }

    public function deleteSession(MemorizationSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->loadMissing(['pages', 'student']);
            $student = $session->student;
            $pages = $session->pages->pluck('page_no')->map(fn ($page) => (int) $page)->all();
            $rewardKeys = [[$session->enrollment_id, $session->recorded_on?->toDateString()]];

            $session->pages()->delete();
            $session->delete();

            if ($student) {
                $this->reconcileAffectedPagesAndRewards($student, $pages, $rewardKeys);
            }
        });
    }

    protected function reconcileAffectedPagesAndRewards(Student $student, array $pageNumbers, array $rewardKeys): void
    {
        $pageNumbers = collect($pageNumbers)->map(fn ($page) => (int) $page)->unique()->values();
        $previousAchievements = StudentPageAchievement::query()
            ->where('student_id', $student->id)
            ->whereIn('page_no', $pageNumbers)
            ->get(['first_enrollment_id', 'first_recorded_on']);

        StudentPageAchievement::query()
            ->where('student_id', $student->id)
            ->whereIn('page_no', $pageNumbers)
            ->delete();

        $externalPages = $student->externalMemorizedJuzs()->get(['from_page', 'to_page'])
            ->flatMap(fn (QuranJuz $juz) => range($juz->from_page, $juz->to_page))->flip();

        foreach ($pageNumbers as $pageNo) {
            if ($externalPages->has($pageNo)) {
                continue;
            }

            $firstSession = MemorizationSession::query()
                ->where('student_id', $student->id)
                ->where('entry_type', '!=', 'review')
                ->whereHas('pages', fn ($query) => $query->where('page_no', $pageNo))
                ->orderBy('recorded_on')->orderBy('id')->first();

            if (! $firstSession) {
                continue;
            }

            StudentPageAchievement::query()->create([
                'student_id' => $student->id,
                'page_no' => $pageNo,
                'first_enrollment_id' => $firstSession->enrollment_id,
                'first_session_id' => $firstSession->id,
                'first_recorded_on' => $firstSession->recorded_on,
            ]);
            $rewardKeys[] = [$firstSession->enrollment_id, $firstSession->recorded_on?->toDateString()];
        }

        foreach ($previousAchievements as $achievement) {
            $rewardKeys[] = [$achievement->first_enrollment_id, $achievement->first_recorded_on?->toDateString()];
        }

        collect($rewardKeys)->filter(fn ($key) => filled($key[0] ?? null) && filled($key[1] ?? null))->unique(fn ($key) => $key[0].'|'.$key[1])
            ->each(function ($key) use ($student): void {
                $enrollment = Enrollment::query()->with('student')->find($key[0]);
                if ($enrollment) {
                    $this->recalculateDailyReward($enrollment, $student, $key[1]);
                }
            });

        Enrollment::query()->with('student')->where('student_id', $student->id)->get()
            ->each(fn (Enrollment $enrollment) => $this->ledger->syncEnrollmentCaches($enrollment));
    }

    public function findDuplicatePages(Enrollment $enrollment, array $pageNumbers, string $entryType, ?MemorizationSession $session = null): array
    {
        if ($entryType === 'review') {
            return [];
        }

        $recordedPages = MemorizationSessionPage::query()
            ->whereIn('page_no', $pageNumbers)
            ->whereHas('session', function ($query) use ($enrollment, $session) {
                $query
                    ->where('student_id', $enrollment->student_id)
                    ->where('entry_type', '!=', 'review')
                    ->when($session, fn ($builder) => $builder->whereKeyNot($session->id));
            })
            ->pluck('page_no')
            ->all();

        $externalPages = QuranJuz::query()
            ->whereHas('externallyMemorizedByStudents', fn ($query) => $query->whereKey($enrollment->student_id))
            ->get(['from_page', 'to_page'])
            ->flatMap(fn (QuranJuz $juz) => range($juz->from_page, $juz->to_page))
            ->intersect($pageNumbers)
            ->all();

        return collect([...$recordedPages, ...$externalPages])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function ensurePagesAreNotDuplicated(array $existingPages): void
    {
        if ($existingPages === []) {
            return;
        }

        throw ValidationException::withMessages([
            'from_page' => __('workflow.memorization.errors.duplicate_pages', ['pages' => implode(', ', $existingPages)]),
        ]);
    }

    protected function recordNewSessionAchievementsAndPoints(
        Enrollment $enrollment,
        MemorizationSession $session,
        Student $student,
        array $pageNumbers,
    ): void {
        if ($session->entry_type !== 'review') {
            $timestamp = now();

            StudentPageAchievement::query()->insert(
                collect($pageNumbers)->map(fn (int $pageNo) => [
                    'student_id' => $student->id,
                    'page_no' => $pageNo,
                    'first_enrollment_id' => $enrollment->id,
                    'first_session_id' => $session->id,
                    'first_recorded_on' => $session->recorded_on,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all()
            );

            if (! $this->isLegacyImportSession($session)) {
                $this->recalculateDailyReward($enrollment, $student, $session->recorded_on->toDateString());
            }
        }

        $this->ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
    }

    protected function recalculateDailyReward(Enrollment $enrollment, Student $student, string $recordedOn): void
    {
        $sessions = MemorizationSession::query()
            ->with('enrollment')
            ->where('student_id', $student->id)
            ->where('enrollment_id', $enrollment->id)
            ->whereDate('recorded_on', $recordedOn)
            ->where('entry_type', '!=', 'review')
            ->orderBy('id')
            ->get()
            ->reject(fn (MemorizationSession $session) => $this->isLegacyImportSession($session));

        if ($sessions->isEmpty()) {
            return;
        }

        PointTransaction::query()
            ->where('student_id', $student->id)
            ->where('enrollment_id', $enrollment->id)
            ->where('source_type', 'memorization_session')
            ->whereIn('source_id', $sessions->pluck('id'))
            ->whereNull('voided_at')
            ->update([
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => __('workflow.memorization.messages.void_reason'),
            ]);

        $newPageCount = StudentPageAchievement::query()
            ->where('student_id', $student->id)
            ->where('first_enrollment_id', $enrollment->id)
            ->whereDate('first_recorded_on', $recordedOn)
            ->count();

        if ($newPageCount === 0) {
            return;
        }

        $policy = $this->ledger->resolvePolicy(
            'memorization',
            'page',
            $student->grade_level_id,
            $newPageCount,
            $recordedOn,
        );

        if (! $policy?->pointType) {
            return;
        }

        $policyHasRange = $policy->from_value !== null || $policy->to_value !== null;

        $this->ledger->recordAutomaticPoints(
            $enrollment,
            'memorization_session',
            $sessions->last()->id,
            $policy->pointType,
            $policy,
            $policyHasRange ? $policy->points : $policy->points * $newPageCount,
            __('workflow.memorization.messages.automatic_reward', ['count' => $newPageCount]),
        );
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

            $seenPages = $student->externalMemorizedJuzs()
                ->get(['from_page', 'to_page'])
                ->flatMap(fn (QuranJuz $juz) => range($juz->from_page, $juz->to_page))
                ->mapWithKeys(fn (int $pageNo) => [$pageNo => true])
                ->all();
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

                if ($this->isLegacyImportSession($session)) {
                    continue;
                }

                $rewardKey = $session->recorded_on->toDateString().'|'.$enrollment->id;
                $dailyRewards[$rewardKey] ??= [
                    'enrollment' => $enrollment,
                    'new_page_count' => 0,
                    'recorded_on' => $session->recorded_on?->toDateString() ?? now()->toDateString(),
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
                    $reward['recorded_on'],
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

    protected function isLegacyImportSession(MemorizationSession $session): bool
    {
        return Str::contains((string) $session->notes, 'Legacy import from Entre records:')
            || Str::contains((string) $session->enrollment?->notes, '[legacy_import] memorization_entre');
    }
}
