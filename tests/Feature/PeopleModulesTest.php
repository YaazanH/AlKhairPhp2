<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PeopleModulesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
        $this->plan = Plan::create(['code' => 'modular', 'name' => 'Modular', 'is_active' => true]);
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'slug' => 'noor', 'name' => 'Noor', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $this->plan->id, 'status' => 'active']);
        app(TenantContext::class)->set($tenant);
        $this->modules(['students']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    private function modules(array $codes): void
    {
        $ids = [];
        foreach ($codes as $code) {
            $ids[] = Feature::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true])->id;
        }
        $this->plan->features()->sync($ids);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Sanctum::actingAs($user, ['*'], 'web');

        return $user;
    }

    private function studentData(): array
    {
        return ['first_name' => 'New', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active'];
    }

    public function test_api_creates_student_without_parent_and_preserves_hidden_link_on_update(): void
    {
        $this->admin();
        $response = $this->postJson('/api/v1/students', $this->studentData())->assertCreated()->assertJsonMissingPath('parent_id')->assertJsonMissingPath('parent');
        $student = Student::findOrFail($response->json('id'));
        $this->assertNull($student->parent_id);
        $parent = ParentProfile::create(['father_name' => 'Hidden father', 'is_active' => true]);
        $student->update(['parent_id' => $parent->id]);
        $this->putJson('/api/v1/students/'.$student->id, $this->studentData())->assertOk()->assertJsonMissingPath('parent_id');
        $this->assertSame($parent->id, $student->fresh()->parent_id);
        $this->putJson('/api/v1/students/'.$student->id, $this->studentData() + ['parent_id' => $parent->id])->assertUnprocessable();
    }

    public function test_disabled_parents_block_web_livewire_and_permission_assignment_even_for_super_admin(): void
    {
        $user = $this->admin();
        $this->assertFalse($user->can('parents.view'));
        $this->assertFalse(Gate::allows('parents.view'));
        $this->get('/parents')->assertForbidden();
        Volt::test('parents.index')->assertForbidden();
        Volt::test('students.index')->call('openQuickParentForm')->assertForbidden();
        Volt::test('settings.access-control')->set('selected_role', 'teacher')->set('selected_permissions', ['parents.create'])->call('save')->assertForbidden();
    }

    public function test_parent_portal_requires_entitlement_for_existing_sessions_and_hides_disabled_sections(): void
    {
        $this->modules(['parents']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('parent');
        ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent', 'is_active' => true]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/parent/profile')->assertForbidden();
        $this->modules(['parent_portal']);
        $this->getJson('/api/v1/parent/profile')->assertOk();
        $this->getJson('/api/v1/parent/summary')->assertOk()->assertJsonMissingPath('data.points')->assertJsonMissingPath('data.invoice_total')->assertJsonMissingPath('data.memorized_pages');
        $this->getJson('/api/v1/parent/invoices')->assertForbidden();
        $this->modules(['parents']);
        $this->getJson('/api/v1/parent/profile')->assertForbidden();
    }

    public function test_capabilities_change_with_modules_and_permissions_and_never_reveal_unavailable_permissions(): void
    {
        $this->admin();
        $first = $this->getJson('/api/v1/capabilities')->assertOk();
        $this->assertContains('students', $first->json('data.modules'));
        $this->assertNotContains('parents.view', $first->json('data.permissions'));
        $this->modules(['parents']);
        $second = $this->getJson('/api/v1/capabilities')->assertOk();
        $this->assertNotSame($first->json('data.version'), $second->json('data.version'));
        $this->assertContains('parents.view', $second->json('data.permissions'));
    }

    public function test_web_student_form_hides_parent_and_preserves_existing_link(): void
    {
        $this->admin();
        $parent = ParentProfile::create(['father_name' => 'PrivateParentName', 'is_active' => true]);
        $student = Student::create($this->studentData() + ['parent_id' => $parent->id]);
        Volt::test('students.index')->call('edit', $student->id)
            ->assertDontSee('PrivateParentName')->assertDontSee('data-student-parent-row', false)
            ->assertSet('parent_id', null)->set('last_name', 'Updated')->call('save')->assertHasNoErrors();
        $this->assertSame($parent->id, $student->fresh()->parent_id);
        $this->assertSame('Updated', $student->fresh()->last_name);
    }

    public function test_parent_cannot_access_another_familys_child(): void
    {
        $this->modules(['parent_portal']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('parent');
        ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Owner', 'is_active' => true]);
        $other = ParentProfile::create(['father_name' => 'Other', 'is_active' => true]);
        $student = Student::create($this->studentData() + ['parent_id' => $other->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/parent/children/'.$student->id)->assertNotFound();
    }
}
