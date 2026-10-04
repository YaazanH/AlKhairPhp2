<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformReportLibraryItem;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\ReportDesignerCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReportLibraryInstallationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        config()->set('tenancy.base_domain', 'localhost');
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_authorized_tenant_user_installs_an_independent_copy_of_the_published_revision(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(collect([
            'report-library.install',
            'report-designer.view',
            'report-designer.update',
        ])->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')));
        $item = $this->publishedItem();

        $this->actingAs($user)->get(route('reports.library.index'))
            ->assertOk()
            ->assertSee('الطلاب المعرضون للخطر')
            ->assertSee('مكتبة التقارير')
            ->assertSee(__('report_library.labels.ready'));

        $this->actingAs($user)->post(route('reports.library.install', $item))->assertRedirect(route('reports.library.index'));

        $copy = ReportDefinition::query()->sole();
        $this->assertSame(ReportDefinition::STATUS_DRAFT, $copy->status);
        $this->assertSame($item->uuid, $copy->library_item_uuid);
        $this->assertSame(1, $copy->library_revision);
        $this->assertSame(['student_number', 'full_name', 'status'], $copy->selected_fields);
        $this->assertSame('bar', $copy->presentation['type']);
        $this->actingAs($user)->get(route('reports.designer'))
            ->assertOk()
            ->assertSee('الطلاب المعرضون للخطر')
            ->assertSee(__('report_library.labels.installed_revision', ['version' => 1]));

        $item->update(['name' => ['en' => 'Changed draft', 'ar' => 'مسودة معدلة']]);
        $this->actingAs($user)->post(route('reports.library.install', $item))->assertRedirect();

        $this->assertSame(['الطلاب المعرضون للخطر', 'الطلاب المعرضون للخطر (2)'], ReportDefinition::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame([1, 1], ReportDefinition::query()->orderBy('id')->pluck('library_revision')->all());
    }

    public function test_library_permission_is_required_for_browsing_and_installing(): void
    {
        $user = User::factory()->create();
        $item = $this->publishedItem();

        $this->actingAs($user)->get(route('reports.library.index'))->assertForbidden();
        $this->actingAs($user)->post(route('reports.library.install', $item))->assertForbidden();
        $this->assertDatabaseCount('report_definitions', 0);
    }

    public function test_template_is_blocked_when_the_user_cannot_access_its_data_source(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('report-library.install', 'web'));
        $item = $this->publishedItem('finance_transactions', [
            'selected_fields' => ['transaction_date', 'transaction_type', 'amount'],
            'group_by' => 'transaction_type',
        ], ['finance']);

        $this->actingAs($user)->get(route('reports.library.index'))
            ->assertOk()
            ->assertSee(__('report_library.compatibility.source_permission'));

        $this->actingAs($user)->post(route('reports.library.install', $item))
            ->assertSessionHasErrors('library_item');
        $this->assertDatabaseCount('report_definitions', 0);
    }

    public function test_predefined_templates_are_ready_for_compatible_tenants_and_install_as_editable_copies(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(collect([
            'report-library.install',
            'finance.reports.view',
            'report-designer.view',
            'report-designer.update',
        ])->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')));

        $response = $this->actingAs($user)->get(route('reports.library.index'))->assertOk();
        foreach (['الطلاب حسب المجموعة', 'مخاطر حضور الطلاب', 'نتائج اختبارات القرآن', 'أداء التقييمات', 'عبء عمل المعلمين', 'نظرة عامة على إنجاز الدورات', 'ملخص الحركات المالية', 'اتجاه نشاط الحضور', 'الطلاب حسب الصف الدراسي', 'المصروفات حسب التصنيف', 'اتجاه المصروفات ربع السنوي', 'تقدم المنهاج حسب المجموعة'] as $name) {
            $response->assertSee($name);
        }
        $this->assertSame(12, substr_count($response->getContent(), __('report_library.labels.ready')));

        $trendTemplate = PlatformReportLibraryItem::query()->where('system_key', 'attendance-activity-trend')->firstOrFail();
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_LINE, data_get($trendTemplate->publishedRevision->definition, 'presentation.type'));
        $this->assertSame('attendance_date', data_get($trendTemplate->publishedRevision->definition, 'group_by'));

        $gradeTemplate = PlatformReportLibraryItem::query()->where('system_key', 'students-by-grade-level')->firstOrFail();
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_TREEMAP, data_get($gradeTemplate->publishedRevision->definition, 'presentation.type'));
        $this->assertSame('grade_level', data_get($gradeTemplate->publishedRevision->definition, 'group_by'));

        $expenseTemplate = PlatformReportLibraryItem::query()->where('system_key', 'expenses-by-category')->firstOrFail();
        $this->assertSame('report_calculation_1', data_get($expenseTemplate->publishedRevision->definition, 'presentation.metric'));
        $this->assertSame(['operation' => 'absolute_sum', 'field' => 'local_amount'], data_get($expenseTemplate->publishedRevision->definition, 'calculations.1'));

        $quarterlyTemplate = PlatformReportLibraryItem::query()->where('system_key', 'quarterly-expense-trend')->firstOrFail();
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_LINE, data_get($quarterlyTemplate->publishedRevision->definition, 'presentation.type'));
        $this->assertSame('transaction_quarter', data_get($quarterlyTemplate->publishedRevision->definition, 'group_by'));
        $this->assertSame('report_calculation_1', data_get($quarterlyTemplate->publishedRevision->definition, 'presentation.metric'));

        $curriculumTemplate = PlatformReportLibraryItem::query()->where('system_key', 'curriculum-progress-by-group')->firstOrFail();
        $this->assertSame(['classes', 'curriculum'], $curriculumTemplate->required_modules);
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_HOTBAR, data_get($curriculumTemplate->publishedRevision->definition, 'presentation.type'));
        $this->assertSame('report_calculation_2', data_get($curriculumTemplate->publishedRevision->definition, 'presentation.total_metric'));

        $template = PlatformReportLibraryItem::query()->where('system_key', 'students-by-group')->firstOrFail();
        $this->actingAs($user)->post(route('reports.library.install', $template))->assertRedirect();

        $copy = ReportDefinition::query()->sole();
        $this->assertSame('الطلاب حسب المجموعة', $copy->name);
        $this->assertSame($template->uuid, $copy->library_item_uuid);
        $this->assertSame(2, $copy->library_revision);
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_LOLLIPOP, $copy->presentation['type']);
        $this->assertSame(ReportDefinition::STATUS_DRAFT, $copy->status);

        Volt::test('reports.designer')
            ->call('edit', $copy->id)
            ->assertSet('presentationType', ReportDesignerCatalog::PRESENTATION_LOLLIPOP)
            ->set('name', 'Editable group distribution')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('report_definitions', [
            'id' => $copy->id,
            'name' => 'Editable group distribution',
        ]);

        $this->actingAs($user)->post(route('reports.library.install', $curriculumTemplate))->assertRedirect();
        $curriculumCopy = ReportDefinition::query()->where('library_item_uuid', $curriculumTemplate->uuid)->sole();
        $this->assertSame('report_calculation_2', data_get($curriculumCopy->presentation, 'total_metric'));
        Volt::test('reports.designer')
            ->call('edit', $curriculumCopy->id)
            ->assertSet('presentationType', ReportDesignerCatalog::PRESENTATION_HOTBAR)
            ->assertSet('presentationTotalMetric', 'report_calculation_2')
            ->set('name', 'Editable curriculum progress')
            ->call('save')
            ->assertHasNoErrors();
    }

    private function publishedItem(string $source = 'students', array $definitionOverrides = [], array $requiredModules = ['students']): PlatformReportLibraryItem
    {
        $definition = array_replace([
            'data_source' => $source,
            'selected_fields' => ['student_number', 'full_name', 'status'],
            'calculations' => [['operation' => 'count', 'field' => null]],
            'group_by' => 'status',
            'presentation' => ['type' => 'bar', 'density' => 'compact'],
            'filters' => [],
            'sort_field' => 'full_name',
            'sort_direction' => 'asc',
        ], $definitionOverrides);

        $item = PlatformReportLibraryItem::query()->create([
            'uuid' => (string) Str::uuid(),
            'kind' => 'both',
            'name' => ['en' => 'Changed draft', 'ar' => 'مسودة معدلة'],
            'description' => ['en' => 'Draft description', 'ar' => 'وصف المسودة'],
            'draft_definition' => $definition,
            'required_modules' => $requiredModules,
            'latest_version' => 1,
        ]);
        $revision = $item->revisions()->create([
            'version' => 1,
            'kind' => 'both',
            'name' => ['en' => 'Students at risk', 'ar' => 'الطلاب المعرضون للخطر'],
            'description' => ['en' => 'Groups students by status.', 'ar' => 'يجمع الطلاب حسب الحالة.'],
            'definition' => $definition,
            'required_modules' => $requiredModules,
            'published_at' => now(),
        ]);
        $item->update(['published_revision_id' => $revision->id]);

        return $item->fresh('publishedRevision');
    }
}
