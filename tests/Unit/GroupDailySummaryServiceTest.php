<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Group;
use App\Services\GroupDailySummaryService;
use Tests\TestCase;

class GroupDailySummaryServiceTest extends TestCase
{
    public function test_daily_copy_text_uses_whatsapp_headings_and_omits_empty_sections(): void
    {
        app()->setLocale('ar');

        $group = new Group(['name' => 'حلقة أحباب الرحمن']);
        $group->setRelation('course', new Course(['name' => 'دورة شتاء 2026 - 2027 س']));

        $text = app(GroupDailySummaryService::class)->dailyCopyText($group, '2026-10-08', [
            'lessons' => collect(['الصدق والأمانة']),
            'rows' => collect([
                (object) [
                    'student_name' => 'عمر غنام',
                    'is_present' => true,
                    'memorized_pages' => collect(),
                ],
            ]),
            'partial_tests' => collect(),
            'final_tests' => collect(),
        ]);

        $this->assertSame(implode(PHP_EOL, [
            'حلقة أحباب الرحمن',
            'يوم الخميس 8-10-2026',
            'دورة شتاء 2026 - 2027 س',
            '',
            '*عنوان الدرس:*',
            'الصدق والأمانة',
            '',
            '*الحضور:*',
            'عمر غنام',
            '',
            'تقبل الله منا ومنكم 🌺',
        ]), $text);
        $this->assertStringNotContainsString('*التسميع:*', $text);
        $this->assertStringNotContainsString('*السبر:*', $text);

        $emptyText = app(GroupDailySummaryService::class)->dailyCopyText($group, '2026-10-08', [
            'lessons' => collect(),
            'rows' => collect(),
            'partial_tests' => collect(),
            'final_tests' => collect(),
        ]);

        $this->assertStringNotContainsString('*عنوان الدرس:*', $emptyText);
        $this->assertStringNotContainsString('*الحضور:*', $emptyText);
        $this->assertStringNotContainsString('*التسميع:*', $emptyText);
        $this->assertStringNotContainsString('*السبر:*', $emptyText);
    }

    public function test_daily_copy_text_groups_trial_and_final_tests_under_one_saber_heading(): void
    {
        app()->setLocale('ar');

        $group = new Group(['name' => 'حلقة النور']);
        $group->setRelation('course', new Course(['name' => 'الدورة الشتوية']));

        $text = app(GroupDailySummaryService::class)->dailyCopyText($group, '2026-10-08', [
            'lessons' => collect(),
            'rows' => collect(),
            'partial_tests' => collect([
                (object) ['student_name' => 'أحمد خالد', 'juz_number' => 7, 'part_numbers' => [2]],
            ]),
            'final_tests' => collect([
                (object) ['student_name' => 'محمد علي', 'juz_number' => 8],
            ]),
        ]);

        $this->assertSame(1, substr_count($text, '*السبر:*'));
        $this->assertStringContainsString('• أحمد خالد — الجزء 7 — الربع الثاني', $text);
        $this->assertStringContainsString('محمد علي — الجزء 8', $text);
        $this->assertStringNotContainsString('*عنوان الدرس:*', $text);
        $this->assertStringNotContainsString('*الحضور:*', $text);
        $this->assertStringNotContainsString('*التسميع:*', $text);
    }

    public function test_test_sections_are_separate_and_empty_sections_are_omitted(): void
    {
        app()->setLocale('ar');

        $group = new Group(['name' => 'حلقة النور']);
        $group->setRelation('course', new Course(['name' => 'الدورة الصيفية']));
        $group->setRelation('teacher', null);

        $text = app(GroupDailySummaryService::class)->copyText($group, '2026-08-13', [
            'rows' => collect(),
            'partial_tests' => collect([
                (object) ['student_name' => 'أحمد خالد', 'juz_number' => 7, 'part_number' => 2],
            ]),
            'final_tests' => collect(),
        ]);

        $this->assertStringContainsString('سبر تجريبي'.PHP_EOL.'• أحمد خالد — الجزء 7 — الربع الثاني', $text);
        $this->assertStringNotContainsString('سبر نهائي', $text);
    }
}
