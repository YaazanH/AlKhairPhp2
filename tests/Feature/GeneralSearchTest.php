<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Support\ArabicSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_word_search_matches_all_words_in_any_order_across_student_and_father_names(): void
    {
        $parent = ParentProfile::create(['father_name' => 'محمد بسام الشعار']);
        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'حمزة',
            'last_name' => 'الشعار',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        Student::create([
            'parent_id' => ParentProfile::create(['father_name' => 'خالد إبراهيم الشعار'])->id,
            'first_name' => 'حمزة',
            'last_name' => 'الشعار',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        foreach (['حمزة الشعار', 'محمد الشعار', 'حمزة بسام', 'الشعار حمزة'] as $search) {
            $query = Student::query();
            ArabicSearch::whereAllTokens(
                $query,
                $search,
                fn (Builder $tokenQuery, string $token) => $tokenQuery->whereMatchesSearchToken($token),
            );

            $this->assertTrue($query->pluck('id')->contains($student->id), $search);
        }

        $query = Student::query();
        ArabicSearch::whereAllTokens(
            $query,
            'محمد إبراهيم',
            fn (Builder $tokenQuery, string $token) => $tokenQuery->whereMatchesSearchToken($token),
        );

        $this->assertFalse($query->pluck('id')->contains($student->id));
    }

    public function test_multi_word_search_can_match_words_across_different_record_fields(): void
    {
        $matching = Course::create([
            'name' => 'الدورة الصيفية',
            'description' => 'برنامج مكثف للطلاب',
        ]);
        Course::create([
            'name' => 'الدورة الشتوية',
            'description' => 'برنامج أسبوعي',
        ]);

        $query = Course::query();
        ArabicSearch::whereAllTokens($query, 'مكثف الدورة', function (Builder $builder, string $token): void {
            $pattern = '%'.$token.'%';
            $builder->where('name', 'like', $pattern)->orWhere('description', 'like', $pattern);
        });

        $this->assertSame([$matching->id], $query->pluck('id')->all());
    }

    public function test_in_memory_search_uses_the_same_all_words_rule_and_arabic_normalization(): void
    {
        $this->assertTrue(ArabicSearch::matchesAllTokens('حمزة محمد بسام الشعار', 'الشعار حمزة'));
        $this->assertTrue(ArabicSearch::matchesAllTokens('أحمد محمد', 'محمد احمد'));
        $this->assertFalse(ArabicSearch::matchesAllTokens('حمزة محمد بسام الشعار', 'حمزة خالد'));
    }
}
