<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\IdCardTemplate;
use App\Models\ParentProfile;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\StudentCardPrint;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ActivityAudienceService;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdCardBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_template_background_uses_a_printable_image_layer(): void
    {
        $template = new PrintTemplate([
            'name' => 'Expense receipt',
            'width_mm' => 80,
            'height_mm' => 50,
            'background_image' => '/images/expense-receipt-background.png',
            'layout_json' => [],
        ]);

        $html = view('print-templates.partials.item', [
            'item' => [
                'template' => $template,
                'elements' => [],
            ],
        ])->render();

        $this->assertStringContainsString('class="print-template-render__background"', $html);
        $this->assertStringContainsString('src="/images/expense-receipt-background.png"', $html);
        $this->assertStringContainsString('print-color-adjust: exact', $html);
        $this->assertStringNotContainsString('background-image: url(', $html);
    }

    public function test_report_card_field_picker_includes_course_performance_metrics(): void
    {
        $courseStudentFields = collect(app(PrintTemplateFieldRegistry::class)->selectableFields('dynamic_text'))
            ->firstWhere('entity', 'course_student')['fields'];
        $fieldKeys = collect($courseStudentFields)->pluck('key');

        $this->assertEqualsCanonicalizing([
            'attendance_average',
            'days_attended',
            'memorized_pages',
            'passed_final_juz_count',
            'daily_memorization_average',
            'weekly_memorization_average',
            'worship_assessment_average',
            'final_exam_score',
            'total_points',
            'cheques_count',
            'leaderboard_count',
            'special_note',
        ], $fieldKeys->intersect([
            'attendance_average',
            'days_attended',
            'memorized_pages',
            'passed_final_juz_count',
            'daily_memorization_average',
            'weekly_memorization_average',
            'worship_assessment_average',
            'final_exam_score',
            'total_points',
            'cheques_count',
            'leaderboard_count',
            'special_note',
        ])->values()->all());
    }

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

        $template = PrintTemplate::query()->create([
            'name' => 'Preview Card',
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
                [
                    'type' => 'barcode',
                    'source' => 'student',
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
            'is_student_card' => true,
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
            'sources' => [
                'student' => ['multiple' => [$studentA->id, $studentB->id]],
            ],
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'copy_count' => 1,
        ]);

        $response
            ->assertOk()
            ->assertSee(__('id_cards.print.preview.title'))
            ->assertSee('Omar Hasan')
            ->assertSee('Aya Hasan')
            ->assertSee((string) ($studentA->student_number ?: $studentA->id))
            ->assertSee((string) ($studentB->student_number ?: $studentB->id))
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
            ->assertSee('app-back-link', false)
            ->assertSee('id="print-template-editor-form"', false)
            ->assertSee('form="print-template-editor-form"', false)
            ->assertSee('data-print-template-save', false)
            ->assertSee('print-template-title-row', false)
            ->assertSee('data-print-template-symbol-action="settings"', false)
            ->assertSee('data-print-template-symbol-action="data-sources"', false)
            ->assertSeeInOrder([
                'data-print-template-save',
                'data-print-template-symbol-action="copy"',
                'data-print-template-symbol-action="data-sources"',
                'data-print-template-symbol-action="settings"',
            ], false)
            ->assertSee('form="print-template-delete-form"', false)
            ->assertSee('admin-modal__close print-template-symbol-button print-template-settings-delete', false)
            ->assertSee('print-template-settings-delete', false)
            ->assertSee('print-template-symbol-button', false)
            ->assertDontSee('>'.__('crud.common.actions.cancel').'</a>', false)
            ->assertSee('data-print-template-stage', false)
            ->assertSee('data-print-template-layout-input', false)
            ->assertSee('print-template-studio__layers', false)
            ->assertSee('print-template-panel__header--layers', false)
            ->assertDontSee('print-template-layer-toggle', false)
            ->assertSee('print-template-color-input', false)
            ->assertSee('print-template-placeholder-field', false)
            ->assertSee('data-keyboard-move-step="0.1"', false)
            ->assertSee('data-keyboard-move-step-large="0.5"', false)
            ->assertSee("const usesContent = element.type === 'custom_text';", false)
            ->assertSee('const moveStep = event.shiftKey ? keyboardMoveStepLarge : keyboardMoveStep;', false)
            ->assertSee('ArrowLeft: [-moveStep, 0]', false)
            ->assertSee('data-layer-duplicate', false);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression(
            '/\.print-template-symbol-button\s*\{[^}]*border-radius:\s*0\.85rem !important;/s',
            $styles,
        );
    }

    public function test_generic_templates_are_printed_only_from_their_editor_with_a_locked_setup(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = PrintTemplate::query()->create([
            'name' => 'Locked Generic Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
        ]);

        $studentCard = PrintTemplate::query()->create([
            'name' => 'Editor Student Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
            'is_student_card' => true,
        ]);

        $reportCard = PrintTemplate::query()->create([
            'name' => 'Editor Report Card',
            'width_mm' => 210,
            'height_mm' => 297,
            'data_sources' => [
                ['entity' => 'course_student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
            'is_report_card' => true,
        ]);

        $printUrl = route('print-templates.print.create', ['template' => $template->id]);

        $this->actingAs($manager)
            ->get(route('print-templates.templates.index'))
            ->assertOk()
            ->assertDontSee($printUrl, false);

        $this->actingAs($manager)
            ->get(route('print-templates.templates.edit', $template))
            ->assertOk()
            ->assertSee($printUrl, false)
            ->assertSee('print-template-data-sources', false)
            ->assertSee("document.getElementById('print-template-data-sources')?.showModal()", false)
            ->assertDontSee('print-template-source-card', false)
            ->assertDontSee('admin-form-field admin-form-field--full"><label for="print-template-name"', false);

        foreach ([$studentCard, $reportCard] as $specialTemplate) {
            $this->actingAs($manager)
                ->get(route('print-templates.templates.edit', $specialTemplate))
                ->assertOk()
                ->assertDontSee(route('print-templates.print.create', ['template' => $specialTemplate->id]), false);
        }

        $this->actingAs($manager)
            ->get(route('print-templates.print.create'))
            ->assertRedirect(route('print-templates.templates.index'));

        $this->actingAs($manager)
            ->get($printUrl)
            ->assertOk()
            ->assertSee('print-template-print-details', false)
            ->assertSee('Locked Generic Template')
            ->assertSee('<input id="print-template-print-template" type="hidden"', false)
            ->assertDontSee('<select id="print-template-print-template"', false)
            ->assertSee('id-card-student-grid', false);
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

    public function test_managers_can_copy_print_templates_with_their_uploaded_assets(): void
    {
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $backgroundPath = UploadedFile::fake()->image('background.png', 300, 200)->store('print-templates/backgrounds', 'public');
        $logoPath = UploadedFile::fake()->image('logo.png', 120, 120)->store('print-templates/elements', 'public');

        $template = PrintTemplate::query()->create([
            'name' => 'Copy Me',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'background_image' => $backgroundPath,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [
                [
                    'id' => 'logo',
                    'type' => 'static_image',
                    'content' => $logoPath,
                    'x' => 8,
                    'y' => 8,
                    'width' => 12,
                    'height' => 12,
                    'z_index' => 1,
                    'styling' => [
                        'object_fit' => 'contain',
                        'border_radius' => 0,
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $response = $this->actingAs($manager)->post(route('print-templates.templates.copy', $template));

        $duplicate = PrintTemplate::query()
            ->whereKeyNot($template->id)
            ->firstOrFail();

        $response->assertRedirect(route('print-templates.templates.edit', $duplicate));
        $this->assertNotSame($template->background_image, $duplicate->background_image);
        $this->assertNotSame($template->layout_json[0]['content'], $duplicate->layout_json[0]['content']);
        Storage::disk('public')->assertExists($duplicate->background_image);
        Storage::disk('public')->assertExists($duplicate->layout_json[0]['content']);
    }

    public function test_student_card_print_setup_shows_printed_filter_and_status(): void
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
            'first_name' => 'Card',
            'last_name' => 'Teacher',
            'phone' => '0991000008',
            'status' => 'active',
        ]);

        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 12,
            'course_id' => $course->id,
            'is_active' => true,
            'monthly_fee' => 20,
            'name' => 'Card Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Card Parent',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Printed',
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

        $template = PrintTemplate::query()->create([
            'name' => 'Student Card Template',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
            'is_student_card' => true,
        ]);

        StudentCardPrint::query()->create([
            'student_id' => $student->id,
            'print_template_id' => $template->id,
            'course_id' => $course->id,
            'printed_by' => $manager->id,
            'printed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('id-cards.print.create', ['template' => $template->id, 'course_id' => $course->id]))
            ->assertOk()
            ->assertSee('data-source-printed-filter="student"', false)
            ->assertSee('<option value="" selected>'.e(__('print_templates.print.setup.fields.all_print_states')).'</option>', false)
            ->assertDontSee('<option value="not_printed" selected>', false)
            ->assertSee('data-toggle-selected-print-status', false)
            ->assertSee('data-print-preview-action', false)
            ->assertSee('data-id-card-print-status-icon="printed"', false)
            ->assertSee('data-id-card-print-status-icon="unprinted"', false)
            ->assertSee('data-supplied-id-card-print-status="mark-as-printed"', false)
            ->assertSee('data-supplied-id-card-print-status="mark-as-not-printed"', false)
            ->assertSee('M147 48h178l60 60v50H147Z', false)
            ->assertSee('M355 326l78 78M433 326l-78 78', false)
            ->assertSee('data-mark-printed-icon', false)
            ->assertSee('data-mark-unprinted-icon', false)
            ->assertSee('data-card-printed="1"', false)
            ->assertDontSee(__('print_templates.print.setup.sections.selected_students'))
            ->assertSee('Card Group')
            ->assertSee(__('print_templates.print.setup.fields.printed_flag'))
            ->assertSee(__('print_templates.print.setup.buttons.mark_printed'))
            ->assertSee('markUnprintedDefaultLabel', false)
            ->assertDontSee('printStatusButton.textContent', false);
    }

    public function test_student_card_print_record_endpoint_creates_history_rows(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $parent = ParentProfile::query()->create([
            'father_name' => 'Record Parent',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Record',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $template = PrintTemplate::query()->create([
            'name' => 'Track Cards',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
            'is_student_card' => true,
        ]);

        $this->actingAs($manager)
            ->postJson(route('id-cards.print.record'), [
                'template_id' => $template->id,
                'student_ids' => [$student->id],
            ])
            ->assertOk()
            ->assertJson(['recorded' => true]);

        $this->assertDatabaseHas('student_card_prints', [
            'student_id' => $student->id,
            'print_template_id' => $template->id,
            'printed_by' => $manager->id,
        ]);
    }

    public function test_student_card_print_clear_endpoint_removes_history_rows(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $parent = ParentProfile::query()->create([
            'father_name' => 'Clear Parent',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Clear',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $template = PrintTemplate::query()->create([
            'name' => 'Clear Cards',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
            'is_student_card' => true,
        ]);

        StudentCardPrint::query()->create([
            'student_id' => $student->id,
            'print_template_id' => $template->id,
            'printed_by' => $manager->id,
            'printed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->deleteJson(route('id-cards.print.clear'), [
                'template_id' => $template->id,
                'student_ids' => [$student->id],
            ])
            ->assertOk()
            ->assertJson(['cleared' => true]);

        $this->assertDatabaseMissing('student_card_prints', [
            'student_id' => $student->id,
        ]);
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

    public function test_print_preview_hides_layout_warnings(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = PrintTemplate::query()->create([
            'name' => 'Large Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'paper_size' => 'a6',
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [],
            'is_active' => true,
            'is_student_card' => true,
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
            'sources' => [
                'student' => ['multiple' => [$student->id]],
            ],
            'page_width_mm' => 80,
            'page_height_mm' => 80,
            'margin_top_mm' => 20,
            'margin_right_mm' => 20,
            'margin_bottom_mm' => 20,
            'margin_left_mm' => 20,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'copy_count' => 1,
        ]);

        $response
            ->assertOk()
            ->assertDontSee(__('id_cards.print.warnings.page_too_small'))
            ->assertDontSee(__('id_cards.print.preview.subtitle'));
    }

    public function test_group_name_field_uses_the_latest_active_enrollment_group(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $template = PrintTemplate::query()->create([
            'name' => 'Group Name Card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => [
                [
                    'type' => 'dynamic_text',
                    'source' => 'student',
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
            'is_student_card' => true,
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
            'sources' => [
                'student' => ['multiple' => [$student->id]],
            ],
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'copy_count' => 1,
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
