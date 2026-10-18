<?php

namespace Tests\Feature;

use App\Models\AttendanceStatus;
use App\Models\GradeLevel;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\QuranJuzSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            MasterDataSeeder::class,
            QuranJuzSeeder::class,
        ]);
    }

    public function test_manager_can_load_every_phase_two_reference_catalog(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $endpoints = [
            'academic-years',
            'grade-levels',
            'attendance-statuses',
            'assessment-types',
            'assessment-score-bands',
            'quran-juz',
            'quran-test-types',
            'point-types',
            'payment-methods',
            'expense-categories',
            'currencies',
            'teacher-job-titles',
            'schools',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson('/api/v1/reference/'.$endpoint)
                ->assertOk()
                ->assertJsonStructure(['data']);
        }

        $this->getJson('/api/v1/reference/academic-years')
            ->assertJsonStructure(['data' => [['id', 'name', 'starts_on', 'ends_on', 'is_current', 'is_active']]]);

        $this->getJson('/api/v1/reference/quran-juz')
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('data.0.juz_number', 1);
    }

    public function test_reference_catalogs_support_active_search_and_domain_filters(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $inactive = GradeLevel::query()->create([
            'name' => 'Archived Mobile Level',
            'sort_order' => 500,
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/reference/grade-levels?q=Archived')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/reference/grade-levels?q=Archived&include_inactive=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);

        AttendanceStatus::query()->create([
            'name' => 'Teacher Mobile Status',
            'code' => 'teacher-mobile-status',
            'scope' => 'teacher',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/reference/attendance-statuses?scope=student')
            ->assertOk()
            ->assertJsonMissing(['code' => 'teacher-mobile-status']);

        $this->getJson('/api/v1/reference/attendance-statuses?scope=teacher')
            ->assertOk()
            ->assertJsonFragment(['code' => 'teacher-mobile-status']);

        $this->getJson('/api/v1/reference/point-types?manual_only=1')
            ->assertOk()
            ->assertJsonMissing(['allow_manual_entry' => false]);
    }

    public function test_reference_catalogs_require_a_relevant_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reference/grade-levels')->assertForbidden();
        $this->getJson('/api/v1/reference/payment-methods')->assertForbidden();
    }
}
