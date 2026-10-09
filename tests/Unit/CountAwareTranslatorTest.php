<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CountAwareTranslatorTest extends TestCase
{
    public static function pageCounts(): array
    {
        return [
            [0, '0 صفحة'], [1, 'صفحة واحدة'], [2, 'صفحتين'], [3, '3 صفحات'],
            [10, '10 صفحات'], [11, '11 صفحة'], [21, '21 صفحة'],
            [100, '100 صفحة'], [102, '102 صفحة'], [103, '103 صفحة'],
            ['1,001', '1,001 صفحة'], ['١', 'صفحة واحدة'], ['٢', 'صفحتين'],
            ['٣', '٣ صفحات'], ['١١', '١١ صفحة'], ['١٬٠٠٣', '١٬٠٠٣ صفحة'],
        ];
    }

    #[DataProvider('pageCounts')]
    public function test_count_labels_follow_the_requested_absolute_ranges(int|string $count, string $expected): void
    {
        $this->assertSame($expected, __('counts.pages', ['count' => $count], 'ar'));
        $this->assertSame($expected, __('student_timeline.page_count', ['count' => $count], 'ar'));
    }

    public function test_native_choice_and_regular_translation_agree_for_all_arabic_count_labels(): void
    {
        foreach (glob(lang_path('ar/*.php')) as $path) {
            foreach (Arr::dot(require $path) as $key => $line) {
                if (! is_string($line) || ! str_starts_with($line, '{0}') || ! str_contains($line, ':count')) {
                    continue;
                }
                $key = basename($path, '.php').'.'.$key;
                foreach ([0, 1, 2, 3, 10, 11, 101, 102, 103, 1000] as $count) {
                    $parameters = ['count' => number_format($count), 'status' => 'نشط'];
                    $actual = __($key, $parameters, 'ar');
                    $this->assertSame(trans_choice($key, $count, $parameters, 'ar'), $actual, $key);
                    $this->assertStringNotContainsString('|', $actual, $key);
                    $this->assertStringNotContainsString(':count', $actual, $key);
                    if ($count === 1) {
                        $this->assertMatchesRegularExpression('/واحد(?:ة)?/u', $actual, $key);
                    }
                    if ($count === 1 || $count === 2) {
                        $this->assertDoesNotMatchRegularExpression('/[0-9٠-٩]/u', $actual, $key);
                    }
                }
            }
        }
    }

    public function test_english_and_non_count_translations_remain_usable(): void
    {
        $this->app->setLocale('ar');
        $this->assertSame('1 page', __('counts.pages', ['count' => 1], 'en'));
        $this->assertSame('2 pages', __('counts.pages', ['count' => 2], 'en'));
        $this->assertSame('103 pages', __('counts.pages', ['count' => 103], 'en'));
        $this->assertSame('الجزء 2', __('workflow.common.labels.juz_number', ['number' => 2]));
        $this->assertSame('unknown.key', __('unknown.key', ['count' => 2]));
        $this->assertIsArray(__('counts'));
    }

    public function test_attendance_group_count_uses_arabic_singular_dual_and_plural_forms(): void
    {
        $key = 'workflow.student_attendance.day_details.table.groups_in_view';
        foreach ([0 => '0 حلقة', 1 => 'حلقة واحدة', 2 => 'حلقتان', 3 => '3 حلقات', 10 => '10 حلقات', 11 => '11 حلقة', 103 => '103 حلقة'] as $count => $expected) {
            $this->assertSame($expected, trans_choice($key, $count, ['count' => $count], 'ar'));
        }
        $this->assertSame('٣ حلقات', __($key, ['count' => '٣'], 'ar'));
    }

    public function test_present_student_count_uses_arabic_numerals_and_plural_forms(): void
    {
        foreach ([0 => '٠ طالب حاضر', 1 => 'طالب واحد حاضر', 2 => 'طالبان حاضران', 3 => '٣ طلاب حاضرون', 10 => '١٠ طلاب حاضرون', 17 => '١٧ طالب حاضر'] as $count => $expected) {
            $this->assertSame($expected, trans_choice('workflow.student_attendance.table.present_students', $count, [
                'count' => strtr((string) $count, [
                    '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                    '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
                ]),
            ], 'ar'));
        }
    }

    public function test_adjectives_and_compound_counts_are_translated_independently(): void
    {
        $this->app->setLocale('ar');
        $this->assertSame('صفحتين ناقصتين', __('workflow.student_progress.juz_progress.incomplete', ['count' => 2]));
        $this->assertSame('صفحتين عبر جلسة واحدة', __('reports.rankings.summary.pages_and_sessions', [
            'pages' => __('counts.pages', ['count' => 2]),
            'sessions' => __('counts.sessions', ['count' => 1]),
        ]));
        $this->assertSame('3 صفحات · 11 جلسة', __('reports.rankings.table.pages_and_sessions', [
            'pages' => __('counts.pages', ['count' => 3]),
            'sessions' => __('counts.sessions', ['count' => 11]),
        ]));
    }

    public function test_client_count_templates_preserve_the_placeholder_only_where_needed(): void
    {
        $this->assertSame('طالب واحد محدد', trans_choice('id_cards.print.setup.selected', 1, ['count' => ':count'], 'ar'));
        $this->assertSame('طالبين محددين', trans_choice('id_cards.print.setup.selected', 2, ['count' => ':count'], 'ar'));
        $this->assertSame(':count طلاب محددين', trans_choice('id_cards.print.setup.selected', 3, ['count' => ':count'], 'ar'));
        $this->assertSame(':count طالب محدد', trans_choice('id_cards.print.setup.selected', 11, ['count' => ':count'], 'ar'));
    }
}
