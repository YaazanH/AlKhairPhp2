<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Services\Landlord\TenantContext;
use App\Support\OperationalFeatureSettings;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class LearningProgressionService
{
    public const GROUP = 'learning_progression';

    public function settings(): array
    {
        $settings = AppSetting::groupValues(self::GROUP);

        return [
            'profile' => $settings->get('profile') ?? 'quran',
            'configured' => (bool) ($settings->get('configured') ?? false),
            'partial_test_enabled' => (bool) ($settings->get('partial_test_enabled') ?? true),
            'partial_test_required_for_final' => (bool) ($settings->get('partial_test_required_for_final') ?? true),
            'final_test_enabled' => (bool) ($settings->get('final_test_enabled') ?? true),
            'final_test_required_for_awqaf' => (bool) ($settings->get('final_test_required_for_awqaf') ?? true),
            'awqaf_test_enabled' => (bool) ($settings->get('awqaf_test_enabled') ?? true),
        ];
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

        AppSetting::storeValue(self::GROUP, 'profile', 'quran');
        AppSetting::storeValue(self::GROUP, 'configured', true, 'boolean');

        foreach ($settings as $key => $value) {
            AppSetting::storeValue(self::GROUP, $key, $value, 'boolean');
        }
    }

    public function isLocked(): bool
    {
        return StudentPageAchievement::query()->exists()
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
