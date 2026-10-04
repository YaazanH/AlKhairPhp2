<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformReportLibraryItem;
use App\Models\Landlord\PlatformRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformReportLibraryTest extends TestCase
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

    public function test_platform_library_keeps_published_revisions_immutable(): void
    {
        $owner = $this->administrator('owner@example.test');

        $this->actingAs($owner, 'platform')->post(route('platform.report-library.store'), $this->payload())
            ->assertRedirect();

        $item = PlatformReportLibraryItem::query()->where('is_system', false)->latest('id')->firstOrFail();
        $this->assertSame(['students'], $item->required_modules);
        $this->assertNull($item->published_revision_id);

        $this->actingAs($owner, 'platform')->post(route('platform.report-library.publish', $item))
            ->assertRedirect();

        $item->refresh();
        $firstRevision = $item->publishedRevision;
        $this->assertSame(1, $item->latest_version);
        $this->assertSame('Students at risk', $firstRevision->name['en']);
        $this->assertSame('bar', $firstRevision->definition['presentation']['type']);

        $updated = $this->payload(['name' => ['en' => 'Students requiring follow-up', 'ar' => 'طلاب يحتاجون إلى متابعة']]);
        $this->actingAs($owner, 'platform')->put(route('platform.report-library.update', $item), $updated)
            ->assertRedirect();

        $this->assertSame('Students at risk', $firstRevision->fresh()->name['en']);
        $this->assertSame('Students requiring follow-up', $item->fresh()->name['en']);

        $this->actingAs($owner, 'platform')->post(route('platform.report-library.publish', $item))
            ->assertRedirect();

        $item->refresh();
        $this->assertSame(2, $item->latest_version);
        $this->assertCount(2, $item->revisions);
        $this->assertSame('Students requiring follow-up', $item->publishedRevision->name['en']);
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'report_library_item_published'], 'landlord');
    }

    public function test_manage_and_publish_permissions_are_separate(): void
    {
        $this->administrator('owner@example.test');
        $manager = $this->administrator('manager@example.test');
        $publisher = $this->administrator('publisher@example.test');
        $manageRole = PlatformRole::query()->create(['name' => 'Library designer']);
        $manageRole->permissions()->sync([PlatformPermission::query()->where('code', 'manage.report-library')->sole()->id]);
        $publishRole = PlatformRole::query()->create(['name' => 'Library publisher']);
        $publishRole->permissions()->sync([PlatformPermission::query()->where('code', 'publish.report-library')->sole()->id]);
        $manager->roles()->sync([$manageRole->id]);
        $publisher->roles()->sync([$publishRole->id]);

        $this->actingAs($manager, 'platform')->post(route('platform.report-library.store'), $this->payload())
            ->assertRedirect();
        $item = PlatformReportLibraryItem::query()->where('is_system', false)->latest('id')->firstOrFail();

        $this->actingAs($manager, 'platform')->post(route('platform.report-library.publish', $item))->assertForbidden();
        $this->actingAs($publisher, 'platform')->get(route('platform.report-library.edit', $item))
            ->assertOk()
            ->assertSee('Publish new revision');
        $this->actingAs($publisher, 'platform')->put(route('platform.report-library.update', $item), $this->payload())
            ->assertForbidden();
        $this->actingAs($publisher, 'platform')->post(route('platform.report-library.publish', $item))
            ->assertRedirect();
    }

    public function test_predefined_templates_are_published_and_cannot_be_modified_from_platform_management(): void
    {
        $owner = $this->administrator('owner@example.test');
        $items = PlatformReportLibraryItem::query()->where('is_system', true)->with('publishedRevision')->get();

        $this->assertCount(12, $items);
        $this->assertSame([
            'assessment-performance',
            'attendance-activity-trend',
            'attendance-risk',
            'course-completion-overview',
            'curriculum-progress-by-group',
            'expenses-by-category',
            'finance-summary',
            'quarterly-expense-trend',
            'quran-test-outcomes',
            'students-by-grade-level',
            'students-by-group',
            'teacher-workload',
        ], $items->pluck('system_key')->sort()->values()->all());
        $this->assertTrue($items->every(fn (PlatformReportLibraryItem $item): bool => $item->publishedRevision !== null
            && $item->latest_version === $item->publishedRevision->version));

        $systemItem = $items->first();
        $this->actingAs($owner, 'platform')->get(route('platform.report-library.edit', $systemItem))
            ->assertOk()
            ->assertSee('built-in template is maintained by the application');
        $this->actingAs($owner, 'platform')->put(route('platform.report-library.update', $systemItem), $this->payload())->assertForbidden();
        $this->actingAs($owner, 'platform')->post(route('platform.report-library.publish', $systemItem))->assertForbidden();
    }

    private function administrator(string $email): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'kind' => 'both',
            'name' => ['en' => 'Students at risk', 'ar' => 'الطلاب المعرضون للخطر'],
            'description' => ['en' => 'Groups students by status.', 'ar' => 'يجمع الطلاب حسب الحالة.'],
            'data_source' => 'students',
            'selected_fields' => ['student_number', 'full_name', 'status'],
            'calculations' => ['count'],
            'group_by' => 'status',
            'presentation_type' => 'bar',
            'table_density' => 'compact',
            'sort_field' => 'full_name',
            'sort_direction' => 'asc',
        ], $overrides);
    }
}
