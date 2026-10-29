<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeacherRoleDisplayTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('locales')]
    public function test_mobile_and_desktop_show_actual_account_roles_instead_of_the_profile_role(string $locale): void
    {
        $this->seed(RoleSeeder::class);
        app()->setLocale($locale);
        session(['locale' => $locale]);

        $viewer = User::factory()->create();
        $viewer->assignRole('manager');
        $this->actingAs($viewer);

        $customRole = Role::create(['name' => 'curriculum_supervisor', 'guard_name' => 'web', 'level' => 250]);
        $legacyRoleId = Role::findByName('admin', 'web')->id;
        $expectedLabels = [];

        foreach ([['teacher'], ['teacher', $customRole->name], [], null] as $index => $roles) {
            $user = $roles === null ? null : User::factory()->create();
            $user?->syncRoles($roles ?? []);
            $teacher = Teacher::create([
                'user_id' => $user?->id,
                'first_name' => 'Display',
                'last_name' => 'Teacher '.$index,
                'phone' => '099811100'.$index,
                'status' => 'active',
                'access_role_id' => $index === 0 ? null : $legacyRoleId,
            ]);
            $expectedLabels[$teacher->id] = match ($index) {
                0 => __('ui.roles.teacher'),
                1 => __('ui.roles.teacher').' · Curriculum Supervisor',
                default => __('crud.common.not_available'),
            };
        }

        $html = Volt::test('teachers.index')->html();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);

        foreach ($expectedLabels as $teacherId => $label) {
            $cells = $xpath->query('//*[@data-teacher-roles="'.$teacherId.'"]');
            $this->assertCount(2, $cells, 'Role labels must appear in both mobile and desktop views.');
            foreach ($cells as $cell) {
                $this->assertSame($label, trim($cell->textContent));
            }
        }
    }

    public static function locales(): array
    {
        return ['arabic' => ['ar'], 'english' => ['en']];
    }
}
