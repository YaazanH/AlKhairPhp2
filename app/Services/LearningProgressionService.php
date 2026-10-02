<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\CurriculumLesson;
use App\Models\Group;
use App\Models\LearningProgressionLevel;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentLearningProgression;
use App\Models\StudentPageAchievement;
use App\Services\Landlord\TenantContext;
use App\Support\OperationalFeatureSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class LearningProgressionService
{
    public const GROUP = 'learning_progression';

    public const PROFILE_QURAN = 'quran';

    public const PROFILE_LESSON_LEVEL = 'lesson_level';

    public function settings(): array
    {
        $settings = AppSetting::groupValues(self::GROUP);

        return [
            'profile' => $settings->get('profile') ?? self::PROFILE_QURAN,
            'configured' => (bool) ($settings->get('configured') ?? false),
            'partial_test_enabled' => (bool) ($settings->get('partial_test_enabled') ?? true),
            'partial_test_required_for_final' => (bool) ($settings->get('partial_test_required_for_final') ?? true),
            'final_test_enabled' => (bool) ($settings->get('final_test_enabled') ?? true),
            'final_test_required_for_awqaf' => (bool) ($settings->get('final_test_required_for_awqaf') ?? true),
            'awqaf_test_enabled' => (bool) ($settings->get('awqaf_test_enabled') ?? true),
        ];
    }

    public function selectProfile(string $profile): void
    {
        if ($this->isLocked()) {
            throw new LogicException(__('learning_progression.errors.locked'));
        }

        if (! in_array($profile, [self::PROFILE_QURAN, self::PROFILE_LESSON_LEVEL], true)) {
            throw ValidationException::withMessages([
                'profile' => __('learning_progression.errors.profile_invalid'),
            ]);
        }

        AppSetting::storeValue(self::GROUP, 'profile', $profile);
        AppSetting::storeValue(
            self::GROUP,
            'configured',
            $profile === self::PROFILE_LESSON_LEVEL && LearningProgressionLevel::query()->exists(),
            'boolean',
        );
    }

    public function storeQuranSettings(array $settings): void
    {
        if ($this->isLocked()) {
            throw new LogicException(__('learning_progression.errors.locked'));
        }

        $settings = array_map(fn (mixed $value): bool => (bool) $value, $settings);

        if ($settings['partial_test_required_for_final'] && (! $settings['partial_test_enabled'] || ! $settings['final_test_enabled'])) {
            throw ValidationException::withMessages([
                'partial_test_required_for_final' => __('learning_progression.errors.partial_requirement_invalid'),
            ]);
        }

        if ($settings['final_test_required_for_awqaf'] && (! $settings['final_test_enabled'] || ! $settings['awqaf_test_enabled'])) {
            throw ValidationException::withMessages([
                'final_test_required_for_awqaf' => __('learning_progression.errors.final_requirement_invalid'),
            ]);
        }

        if (! $settings['partial_test_enabled'] && ! $settings['final_test_enabled'] && ! $settings['awqaf_test_enabled']) {
            throw ValidationException::withMessages([
                'partial_test_enabled' => __('learning_progression.errors.one_test_required'),
            ]);
        }

        AppSetting::storeValue(self::GROUP, 'profile', self::PROFILE_QURAN);
        AppSetting::storeValue(self::GROUP, 'configured', true, 'boolean');

        foreach ($settings as $key => $value) {
            AppSetting::storeValue(self::GROUP, $key, $value, 'boolean');
        }
    }

    public function storeLevel(array $data, ?LearningProgressionLevel $level = null): LearningProgressionLevel
    {
        if ($this->isLocked()) {
            throw new LogicException(__('learning_progression.errors.locked'));
        }

        $groupIds = collect($data['group_ids'])->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $lessonIds = collect($data['lesson_ids'])->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $groups = Group::query()->whereKey($groupIds)->get();
        $lessons = CurriculumLesson::query()->with('subject')->whereKey($lessonIds)->get();
        $assessment = Assessment::query()->with('groups')->findOrFail($data['final_assessment_id']);

        if (LearningProgressionLevel::query()
            ->where('final_assessment_id', $assessment->id)
            ->when($level, fn ($query) => $query->whereKeyNot($level->id))
            ->exists()) {
            throw ValidationException::withMessages(['final_assessment_id' => __('learning_progression.errors.assessment_already_used')]);
        }

        if ($groups->count() !== $groupIds->count() || $groupIds->isEmpty()) {
            throw ValidationException::withMessages(['group_ids' => __('learning_progression.errors.groups_required')]);
        }

        if ($lessons->count() !== $lessonIds->count() || $lessonIds->isEmpty()) {
            throw ValidationException::withMessages(['lesson_ids' => __('learning_progression.errors.lessons_required')]);
        }

        $curriculumIds = $groups->pluck('curriculum_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();
        if ($curriculumIds->isEmpty() || $lessons->contains(fn (CurriculumLesson $lesson): bool => ! $curriculumIds->contains((int) $lesson->subject->curriculum_id))) {
            throw ValidationException::withMessages(['lesson_ids' => __('learning_progression.errors.lesson_group_mismatch')]);
        }

        $assessmentGroupIds = $assessment->groups->pluck('id')->push($assessment->group_id)->filter()->map(fn (mixed $id): int => (int) $id)->unique();
        if ($assessmentGroupIds->intersect($groupIds)->isEmpty()) {
            throw ValidationException::withMessages(['final_assessment_id' => __('learning_progression.errors.assessment_group_mismatch')]);
        }

        if ($assessment->total_mark !== null && (float) $data['passing_score'] > (float) $assessment->total_mark) {
            throw ValidationException::withMessages(['passing_score' => __('learning_progression.errors.passing_score_too_high', ['total' => $assessment->total_mark])]);
        }

        return DB::transaction(function () use ($data, $groupIds, $lessonIds, $level): LearningProgressionLevel {
            $level ??= new LearningProgressionLevel;
            $level->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'attendance_threshold' => $data['attendance_threshold'],
                'final_assessment_id' => $data['final_assessment_id'],
                'passing_score' => $data['passing_score'],
            ]);

            if (! $level->exists) {
                $level->sort_order = (int) LearningProgressionLevel::query()->max('sort_order') + 1;
            }

            $level->save();
            $level->groups()->sync($groupIds);
            $level->lessons()->sync($lessonIds);

            AppSetting::storeValue(self::GROUP, 'profile', self::PROFILE_LESSON_LEVEL);
            AppSetting::storeValue(self::GROUP, 'configured', true, 'boolean');

            return $level->refresh();
        });
    }

    public function deleteLevel(LearningProgressionLevel $level): void
    {
        if ($this->isLocked()) {
            throw new LogicException(__('learning_progression.errors.locked'));
        }

        DB::transaction(function () use ($level): void {
            $level->delete();
            $this->normalizeLevelOrder();
            AppSetting::storeValue(self::GROUP, 'configured', LearningProgressionLevel::query()->exists(), 'boolean');
        });
    }

    public function moveLevel(LearningProgressionLevel $level, string $direction): void
    {
        if ($this->isLocked()) {
            throw new LogicException(__('learning_progression.errors.locked'));
        }

        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';
        $neighbor = LearningProgressionLevel::query()
            ->where('sort_order', $operator, $level->sort_order)
            ->orderBy('sort_order', $order)
            ->first();

        if (! $neighbor) {
            return;
        }

        DB::transaction(function () use ($level, $neighbor): void {
            [$levelOrder, $neighborOrder] = [$level->sort_order, $neighbor->sort_order];
            $level->update(['sort_order' => $neighborOrder]);
            $neighbor->update(['sort_order' => $levelOrder]);
        });
    }

    private function normalizeLevelOrder(): void
    {
        LearningProgressionLevel::query()->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn (LearningProgressionLevel $level, int $index) => $level->update(['sort_order' => $index + 1]));
    }

    public function isLocked(): bool
    {
        return StudentLearningProgression::query()->exists()
            || StudentPageAchievement::query()->exists()
            || QuranPartialTest::query()->exists()
            || QuranFinalTest::query()->exists()
            || QuranTest::query()->exists();
    }

    public function ensureTestEnabled(string $test): void
    {
        if ($this->configurationRequired()) {
            throw new LogicException(__('learning_progression.errors.not_configured'));
        }

        $key = match ($test) {
            'partial' => 'partial_test_enabled',
            'final' => 'final_test_enabled',
            'awqaf' => 'awqaf_test_enabled',
            default => null,
        };

        if ($key === null) {
            return;
        }

        if (! $this->settings()[$key]) {
            throw new LogicException(__('learning_progression.errors.test_disabled', [
                'test' => __('learning_progression.tests.'.$test),
            ]));
        }
    }

    public function ensureConfigured(string $errorKey = 'learning_progression'): void
    {
        if ($this->configurationRequired()) {
            throw ValidationException::withMessages([
                $errorKey => __('learning_progression.errors.not_configured'),
            ]);
        }
    }

    public function configurationRequired(): bool
    {
        return app(TenantContext::class)->hasTenant() && ! $this->settings()['configured'];
    }

    public function finalRequiresPartial(): bool
    {
        return $this->settings()['partial_test_required_for_final'];
    }

    public function awqafRequiresFinal(): bool
    {
        return $this->settings()['final_test_required_for_awqaf'];
    }

    public function memorizedJuzIdsForStudent(Student $student): Collection
    {
        if (! OperationalFeatureSettings::quranTestsRequireMemorizationProgress()) {
            return QuranJuz::query()->orderBy('juz_number')->pluck('id')->map(fn ($id): int => (int) $id);
        }

        $completedPages = $student->pageAchievements()
            ->pluck('page_no')
            ->map(fn ($page): int => (int) $page)
            ->flip();
        $externalJuzIds = $student->externalMemorizedJuzs()
            ->pluck('quran_juzs.id')
            ->map(fn ($id): int => (int) $id);

        return QuranJuz::query()
            ->orderBy('juz_number')
            ->get()
            ->filter(function (QuranJuz $juz) use ($completedPages, $externalJuzIds): bool {
                if ($externalJuzIds->contains($juz->id)) {
                    return true;
                }

                foreach (range($juz->from_page, $juz->to_page) as $page) {
                    if (! $completedPages->has($page)) {
                        return false;
                    }
                }

                return true;
            })
            ->pluck('id')
            ->values();
    }
}
