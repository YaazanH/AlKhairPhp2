<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Group;
use App\Services\GroupDailySummaryService;
use Tests\TestCase;

class GroupDailySummaryServiceTest extends TestCase
{
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
