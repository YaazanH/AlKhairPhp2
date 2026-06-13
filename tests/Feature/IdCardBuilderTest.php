<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\IdCardTemplate;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ActivityAudienceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_managers_can_open_print_template_edit_page(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = PrintTemplate::query()->create([
            'name' => 'Editable Print Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [
                [
                    'type' => 'custom_text',
                    'content' => 'Preview text',
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

        $this->actingAs($manager)
            ->get(route('print-templates.templates.edit', $template))
            ->assertOk()
            ->assertSee('data-print-template-stage', false)
            ->assertSee('data-print-template-layout-input', false);
    }

    public function test_managers_can_store_static_image_elements_in_print_templates(): void
    {
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $response = $this->actingAs($manager)->post(route('print-templates.templates.store'), [
            'name' => 'Static Logo Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources_json' => json_encode([]),
            'layout_json' => json_encode([
                [
                    'id' => 'logo-element',
                    'type' => 'static_image',
                    'content' => '',
                    'x' => 8,
                    'y' => 8,
                    'width' => 18,
                    'height' => 18,
                    'z_index' => 1,
                    'styling' => [
                        'object_fit' => 'contain',
                        'border_radius' => 0,
                    ],
                ],
            ]),
            'static_images' => [
                'logo-element' => UploadedFile::fake()->image('logo.png', 120, 120),
            ],
            'is_active' => '1',
        ]);

        $template = PrintTemplate::query()->firstOrFail();
        $storedPath = $template->layout_json[0]['content'] ?? null;

        $response->assertRedirect(route('print-templates.templates.edit', $template));
        $this->assertSame('static_image', $template->layout_json[0]['type']);
        $this->assertNotEmpty($storedPath);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_print_template_preview_keeps_right_alignment_on_rtl_text(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = PrintTemplate::query()->create([
            'name' => 'Arabic Alignment Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [],
            'layout_json' => [
                [
                    'type' => 'custom_text',
                    'content' => "مسجد الخير\nالخير مسجد",
                    'x' => 8,
                    'y' => 8,
                    'width' => 54,
                    'height' => 20,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'right',
                        'line_height' => 1.2,
                        'letter_spacing' => 0,
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('print-templates.print.preview'), [
                'template_id' => $template->id,
                'copy_count' => 1,
                'page_width_mm' => 210,
                'page_height_mm' => 297,
                'margin_top_mm' => 10,
                'margin_right_mm' => 10,
                'margin_bottom_mm' => 10,
                'margin_left_mm' => 10,
                'gap_x_mm' => 6,
                'gap_y_mm' => 6,
            ])
            ->assertOk()
            ->assertSee('text-align: right;', false)
            ->assertSee('direction: rtl;', false)
            ->assertDontSee('justify-content: flex-end;', false);
    }

    public function test_print_template_preview_does_not_inject_leading_whitespace_into_text_nodes(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = PrintTemplate::query()->create([
            'name' => 'Whitespace Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [],
            'layout_json' => [
                [
                    'type' => 'custom_text',
                    'content' => 'مسجد الخير',
                    'x' => 8,
                    'y' => 8,
                    'width' => 54,
                    'height' => 12,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'right',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('print-templates.print.preview'), [
                'template_id' => $template->id,
                'copy_count' => 1,
                'page_width_mm' => 210,
                'page_height_mm' => 297,
                'margin_top_mm' => 10,
                'margin_right_mm' => 10,
                'margin_bottom_mm' => 10,
                'margin_left_mm' => 10,
                'gap_x_mm' => 6,
                'gap_y_mm' => 6,
            ])
            ->assertOk()
            ->assertSee('>مسجد الخير</div>', false)
            ->assertDontSee(">\n                مسجد الخير", false);
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

    public function test_print_template_sources_filter_students_and_teachers_by_activity(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

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

        $teacherA = Teacher::query()->create([
            'first_name' => 'Activity',
            'last_name' => 'Teacher',
            'phone' => '0991000001',
            'status' => 'active',
        ]);

        $teacherB = Teacher::query()->create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0991000002',
            'status' => 'active',
        ]);

        $groupA = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 12,
            'course_id' => $course->id,
            'is_active' => true,
            'monthly_fee' => 20,
            'name' => 'Activity Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacherA->id,
        ]);

        $groupB = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 12,
            'course_id' => $course->id,
            'is_active' => true,
            'monthly_fee' => 20,
            'name' => 'Other Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacherB->id,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Activity Parent',
            'is_active' => true,
        ]);

        $eligibleStudent = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Eligible',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $unrelatedStudent = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Unrelated',
            'last_name' => 'Student',
            'birth_date' => '2015-03-03',
            'status' => 'active',
        ]);

        Enrollment::query()->create([
            'student_id' => $eligibleStudent->id,
            'group_id' => $groupA->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        Enrollment::query()->create([
            'student_id' => $unrelatedStudent->id,
            'group_id' => $groupB->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $activity = Activity::query()->create([
            'title' => 'Targeted Activity',
            'activity_date' => '2026-11-01',
            'audience_scope' => ActivityAudienceService::SCOPE_SINGLE_GROUP,
            'group_id' => $groupA->id,
            'fee_amount' => 10,
            'is_active' => true,
        ]);

        $template = PrintTemplate::query()->create([
            'name' => 'Activity Source Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'activity', 'mode' => 'single'],
                ['entity' => 'teacher', 'mode' => 'single'],
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [
                [
                    'type' => 'dynamic_text',
                    'source' => 'student',
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
            ],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get(route('print-templates.print.create', ['template' => $template->id]))
            ->assertOk()
            ->assertSee('data-related-student-ids="'.$eligibleStudent->id.'"', false)
            ->assertSee('data-related-teacher-ids="'.$teacherA->id.'"', false);

        $this->actingAs($manager)
            ->post(route('print-templates.print.preview'), [
                'template_id' => $template->id,
                'sources' => [
                    'activity' => ['single' => $activity->id],
                    'teacher' => ['single' => $teacherA->id],
                    'student' => ['multiple' => [$eligibleStudent->id, $unrelatedStudent->id]],
                ],
                'copy_count' => 1,
                'page_width_mm' => 210,
                'page_height_mm' => 297,
                'margin_top_mm' => 10,
                'margin_right_mm' => 10,
                'margin_bottom_mm' => 10,
                'margin_left_mm' => 10,
                'gap_x_mm' => 6,
                'gap_y_mm' => 6,
            ])
            ->assertOk()
            ->assertSee('Eligible Student')
            ->assertDontSee('Unrelated Student');
    }

    public function test_print_template_setup_shows_student_group_and_status_filters(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

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

        $teacher = Teacher::query()->create([
            'first_name' => 'Filter',
            'last_name' => 'Teacher',
            'phone' => '0991000003',
            'status' => 'active',
        ]);

        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 12,
            'course_id' => $course->id,
            'is_active' => true,
            'monthly_fee' => 20,
            'name' => 'Printable Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Printable Parent',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Printable',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $activity = Activity::query()->create([
            'title' => 'Printable Activity',
            'activity_date' => '2026-11-01',
            'audience_scope' => ActivityAudienceService::SCOPE_SINGLE_GROUP,
            'group_id' => $group->id,
            'fee_amount' => 10,
            'is_active' => true,
        ]);

        $template = PrintTemplate::query()->create([
            'name' => 'Student Filter Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [
                [
                    'type' => 'dynamic_text',
                    'source' => 'student',
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
            ],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get(route('print-templates.print.create', ['template' => $template->id]))
            ->assertOk()
            ->assertSee('data-source-group-filter="student"', false)
            ->assertSee('data-source-status-filter="student"', false)
            ->assertSee('Printable Group')
            ->assertSee('الطلاب النشطون')
            ->assertSee('الطلاب غير النشطين');
    }

    public function test_qr_barcode_preview_accepts_small_square_sizes(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('id-cards.barcode-preview', [
                'format' => 'qrcode',
                'value' => 'STU-1001',
                'width' => 12,
                'height' => 12,
                'show_text' => 0,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('viewBox="0 0 12.000 12.000"', false)
            ->assertSee('data-code-type="qrcode"', false)
            ->assertDontSee('fill="#fff"', false);
    }
}
