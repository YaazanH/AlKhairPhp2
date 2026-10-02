<?php

namespace Tests\Feature;

use App\Models\ReportDefinition;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessScopeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportDesignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_administrator_can_save_and_preview_a_student_report_draft(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'report-admin']);
        $administrator->assignRole('admin');
        Student::query()->create([
            'first_name' => 'Mariam',
            'last_name' => 'Ahmad',
            'student_number' => 'S-100',
            'birth_date' => '2014-01-01',
            'status' => 'active',
            'joined_at' => '2026-09-01',
        ]);

        $this->actingAs($administrator)
            ->get(route('reports.designer', absolute: false))
            ->assertOk()
            ->assertSee(__('report_designer.title'));

        Volt::test('reports.designer')
            ->call('create')
            ->set('name', 'Active students')
            ->set('description', 'Operational active-student list')
            ->set('selectedFields', ['student_number', 'full_name', 'status'])
            ->set('statusFilter', 'active')
            ->set('sortField', 'full_name')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Mariam Ahmad')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('report_definitions', [
            'name' => 'Active students',
            'data_source' => 'students',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
        ]);
    }

    public function test_report_preview_rejects_fields_outside_the_approved_catalog(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'safe-report-admin']);
        $administrator->assignRole('admin');
        $this->actingAs($administrator);

        Volt::test('reports.designer')
            ->call('create')
            ->set('selectedFields', ['full_name', 'password'])
            ->call('preview')
            ->assertHasErrors('selectedFields');
    }

    public function test_preview_respects_the_users_existing_student_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-report-user']);
        $user->givePermissionTo('report-designer.view');
        $visible = Student::query()->create([
            'first_name' => 'Visible',
            'last_name' => 'Student',
            'student_number' => 'S-201',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        Student::query()->create([
            'first_name' => 'Hidden',
            'last_name' => 'Student',
            'student_number' => 'S-202',
            'birth_date' => '2014-01-02',
            'status' => 'active',
        ]);
        app(AccessScopeService::class)->syncUserOverrides($user, ['student' => [$visible->id]]);

        $definition = ReportDefinition::query()->create([
            'name' => 'Scoped students',
            'data_source' => 'students',
            'selected_fields' => ['student_number', 'full_name'],
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user);

        Volt::test('reports.designer')
            ->call('viewDefinition', $definition->id)
            ->set('selectedFields', ['student_number', 'full_name', 'password'])
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Visible Student')
            ->assertDontSee('Hidden Student');
    }

    public function test_teacher_without_designer_permission_cannot_open_the_designer(): void
    {
        $this->seed(RoleSeeder::class);

        $teacher = User::factory()->create(['username' => 'report-teacher']);
        $teacher->assignRole('teacher');

        $this->actingAs($teacher)
            ->get(route('reports.designer', absolute: false))
            ->assertForbidden();
    }
}
