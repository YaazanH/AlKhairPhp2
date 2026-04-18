<?php

namespace Tests\Feature;

use App\Models\IdCardTemplate;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdCardBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_managers_can_create_id_card_templates(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $response = $this->actingAs($manager)->post(route('id-cards.templates.store'), [
            'name' => 'Front Desk Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'is_active' => '1',
            'layout_json' => json_encode([
                [
                    'type' => 'text',
                    'field' => 'full_name',
                    'x' => 6,
                    'y' => 8,
                    'width' => 40,
                    'height' => 8,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.4,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
                [
                    'type' => 'barcode',
                    'field' => 'student_number',
                    'x' => 8,
                    'y' => 32,
                    'width' => 56,
                    'height' => 14,
                    'z_index' => 2,
                    'styling' => [
                        'font_size' => 3,
                        'show_text' => true,
                        'barcode_format' => 'qrcode',
                        'color' => '#102316',
                    ],
                ],
            ]),
        ]);

        $template = IdCardTemplate::query()->firstOrFail();

        $response->assertRedirect(route('id-cards.templates.edit', $template));
        $this->assertSame('Front Desk Card', $template->name);
        $this->assertCount(2, $template->layout_json);
        $this->assertSame('qrcode', $template->layout_json[1]['styling']['barcode_format']);
    }

    public function test_print_preview_renders_selected_students(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = IdCardTemplate::query()->create([
            'name' => 'Preview Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'layout_json' => [
                [
                    'type' => 'text',
                    'field' => 'full_name',
                    'x' => 8,
                    'y' => 8,
                    'width' => 54,
                    'height' => 8,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
                [
                    'type' => 'barcode',
                    'field' => 'student_number',
                    'x' => 8,
                    'y' => 30,
                    'width' => 54,
                    'height' => 14,
                    'z_index' => 2,
                    'styling' => [
                        'font_size' => 3,
                        'show_text' => true,
                        'barcode_format' => 'qrcode',
                        'color' => '#102316',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Maher Hasan',
            'is_active' => true,
        ]);

        $studentA = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Omar',
            'last_name' => 'Hasan',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $studentB = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Aya',
            'last_name' => 'Hasan',
            'birth_date' => '2015-03-03',
            'status' => 'active',
        ]);

        $studentA = $studentA->fresh();
        $studentB = $studentB->fresh();

        $response = $this->actingAs($manager)->post(route('id-cards.print.preview'), [
            'template_id' => $template->id,
            'student_ids' => [$studentA->id, $studentB->id],
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
        ]);

        $response
            ->assertOk()
            ->assertSee(__('id_cards.print.preview.title'))
            ->assertSee('Omar Hasan')
            ->assertSee('Aya Hasan')
            ->assertSee((string) $studentA->id)
            ->assertSee((string) $studentB->id)
            ->assertSee('data-code-type="qrcode"', false)
            ->assertSee('<svg', false);
    }

    public function test_print_preview_warns_when_page_size_cannot_fit_the_card(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = IdCardTemplate::query()->create([
            'name' => 'Large Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'layout_json' => [],
            'is_active' => true,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Ahmad Ali',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Mariam',
            'last_name' => 'Ali',
            'birth_date' => '2016-06-06',
            'status' => 'active',
        ]);

        $response = $this->actingAs($manager)->post(route('id-cards.print.preview'), [
            'template_id' => $template->id,
            'student_ids' => [$student->id],
            'page_width_mm' => 80,
            'page_height_mm' => 80,
            'margin_top_mm' => 20,
            'margin_right_mm' => 20,
            'margin_bottom_mm' => 20,
            'margin_left_mm' => 20,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
        ]);

        $response
            ->assertOk()
            ->assertSee(__('id_cards.print.warnings.page_too_small'));
    }

    public function test_group_name_field_uses_the_latest_active_enrollment_group(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = IdCardTemplate::query()->create([
            'name' => 'Group Name Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'layout_json' => [
                [
                    'type' => 'text',
                    'field' => 'group_name',
                    'x' => 8,
                    'y' => 8,
                    'width' => 54,
                    'height' => 8,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Samer Khaled',
            'is_active' => true,
        ]);

        $teacher = Teacher::query()->create([
            'first_name' => 'Yusuf',
            'last_name' => 'Teacher',
            'phone' => '0999111222',
            'status' => 'active',
        ]);

        $academicYear = AcademicYear::query()->create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
            'is_active' => true,
        ]);

        $course = Course::query()->create([
            'name' => 'Quran Program',
            'is_active' => true,
        ]);

        $oldGroup = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 12,
            'course_id' => $course->id,
            'is_active' => true,
            'monthly_fee' => 20,
            'name' => 'Old Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ]);

        $currentGroup = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 12,
            'course_id' => $course->id,
            'is_active' => true,
            'monthly_fee' => 20,
            'name' => 'Current Group',
            'starts_on' => '2026-10-01',
            'teacher_id' => $teacher->id,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Layan',
            'last_name' => 'Khaled',
            'birth_date' => '2013-04-14',
            'status' => 'active',
        ]);

        Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $oldGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'inactive',
            'left_at' => '2026-09-30',
        ]);

        Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $currentGroup->id,
            'enrolled_at' => '2026-10-02',
            'status' => 'active',
        ]);

        $response = $this->actingAs($manager)->post(route('id-cards.print.preview'), [
            'template_id' => $template->id,
            'student_ids' => [$student->id],
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
        ]);

        $response
            ->assertOk()
            ->assertSee('Current Group')
            ->assertDontSee('Old Group');
    }
}
