<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformLandingEnquiry;
use App\Models\Landlord\PlatformLandingPage;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformReportLibraryItem;
use App\Models\Landlord\PlatformRole;
use App\Services\Landlord\PlatformLandingPageManager;
use App\Support\PlatformLandingContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformContentWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config()->set('tenancy.base_domain', 'localhost');
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_publish_only_website_user_can_review_draft_without_edit_controls_or_enquiry_contact_data(): void
    {
        $this->user();
        $publisher = $this->user(['publish.landing-page']);
        $content = PlatformLandingContent::defaults();
        $content['hero_title']['en'] = 'Private review headline';
        PlatformLandingPage::query()->create(['key' => 'main', 'draft_content' => $content]);
        PlatformLandingEnquiry::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Private lead', 'email' => 'private-lead@example.test', 'organisation_name' => 'Private organisation', 'locale' => 'en']);
        $this->actingAs($publisher, 'platform')->get(route('platform.landing.edit'))->assertOk()->assertSee('Private review headline')->assertSee('readonly', false)->assertDontSee('action="'.route('platform.landing.update').'"', false)->assertDontSee('private-lead@example.test')->assertDontSee('href="'.route('platform.enquiries.index').'"', false);
        $this->get(route('platform.landing.edit', ['view' => 'publishing']))->assertOk()->assertSee('action="'.route('platform.landing.publish').'"', false);
        $this->put(route('platform.landing.update'), $this->websitePayload($content))->assertForbidden();
    }

    public function test_protected_draft_preview_uses_selected_language_and_never_changes_the_public_page(): void
    {
        $owner = $this->user();
        $content = PlatformLandingContent::defaults();
        $content['hero_title'] = ['en' => 'Private preview headline', 'ar' => 'عنوان المعاينة الخاص'];
        PlatformLandingPage::query()->create(['key' => 'main', 'draft_content' => $content]);
        $this->actingAs($owner, 'platform')->get(route('platform.landing.preview', ['language' => 'en']))->assertOk()->assertSee('Private preview headline')->assertSee('fieldset disabled', false)->assertDontSee('action="'.route('platform-site.enquire').'"', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('platform.landing.preview', ['language' => 'ar']))->assertOk()->assertSee('عنوان المعاينة الخاص')->assertSee('dir="rtl"', false);
        $this->withHeader('Accept-Language', 'en')->get('http://localhost/')->assertOk()->assertDontSee('Private preview headline')->assertDontSee('landing-private-preview');
        $this->assertNull(PlatformLandingPage::query()->sole()->published_revision_id);
        $this->assertSame(0, PlatformLandingEnquiry::query()->count());
        $outsider = $this->user([]);
        $this->actingAs($outsider, 'platform')->get(route('platform.landing.preview'))->assertForbidden();
        auth('platform')->logout();
        $this->get(route('platform.landing.preview'))->assertRedirect(route('platform.login'));
    }

    public function test_publication_checks_match_backend_rules_even_for_disabled_sections(): void
    {
        $owner = $this->user();
        $content = PlatformLandingContent::defaults();
        $content['showcase_title']['ar'] = '';
        $content['enabled_sections']['showcase'] = false;
        $page = PlatformLandingPage::query()->create(['key' => 'main', 'draft_content' => $content]);
        $this->actingAs($owner, 'platform')->get(route('platform.landing.edit', ['view' => 'publishing']))->assertOk()->assertViewHas('publicationErrors', fn ($errors) => isset($errors['showcase_title.ar']))->assertSee('text checks need attention');
        $this->post(route('platform.landing.publish'))->assertSessionHasErrors('showcase_title.ar');
        $this->assertSame(0, $page->revisions()->count());
    }

    public function test_historical_image_assets_remain_available_when_new_drafts_replace_them(): void
    {
        Storage::fake('public');
        $owner = $this->user();
        $manager = app(PlatformLandingPageManager::class);
        $content = $this->websitePayload(PlatformLandingContent::defaults());
        $input = $content['content'] + ['section_order' => $content['section_order'], 'enabled_sections' => $content['enabled_sections']];
        $first = $manager->saveDraft($input, [0 => UploadedFile::fake()->image('first.png')], $owner, null);
        $firstPath = $first->draft_content['showcase_items'][0]['image_path'];
        $firstRevision = $manager->publish($owner, null);
        $second = $manager->saveDraft($input, [0 => UploadedFile::fake()->image('second.png')], $owner, null);
        $secondPath = $second->draft_content['showcase_items'][0]['image_path'];
        $manager->publish($owner, null);
        $third = $manager->saveDraft($input, [0 => UploadedFile::fake()->image('third.png')], $owner, null);
        $thirdPath = $third->draft_content['showcase_items'][0]['image_path'];
        $manager->saveDraft($input, [0 => UploadedFile::fake()->image('fourth.png')], $owner, null);
        Storage::disk('public')->assertExists([$firstPath, $secondPath]);
        Storage::disk('public')->assertMissing($thirdPath);
        $this->actingAs($owner, 'platform')->get(route('platform.landing.preview', ['revision' => $firstRevision->id, 'language' => 'en']))->assertOk()->assertSee(route('platform-site.media', ['path' => $firstPath]), false)->assertSee('Private preview of revision 1');
        $this->post(route('platform.landing.restore', $firstRevision))->assertRedirect();
        $this->assertSame($firstPath, $manager->page()->draft_content['showcase_items'][0]['image_path']);
        $this->assertSame(3, $manager->page()->revisions()->count());
    }

    public function test_website_errors_retain_disabled_section_choices_and_draft_text(): void
    {
        $owner = $this->user();
        $payload = $this->websitePayload(PlatformLandingContent::defaults());
        $payload['workspace_form'] = 'website_draft';
        $payload['enabled_sections'] = [];
        $payload['section_order']['features'] = 9;
        $payload['content']['hero_title']['en'] = 'Retained title';
        $this->actingAs($owner, 'platform')->from(route('platform.landing.edit'))->put(route('platform.landing.update'), $payload)->assertSessionHasErrors('section_order.features');
        $this->get(route('platform.landing.edit'))->assertOk()->assertSee('Retained title')->assertDontSee('value="1" checked', false);
    }

    public function test_library_filters_both_languages_and_paginates_without_mutating_definitions(): void
    {
        $owner = $this->user();
        for ($i = 0; $i < 14; $i++) {
            $this->item('Custom '.$i, ['kind' => $i === 13 ? 'widget' : 'both']);
        }
        $this->actingAs($owner, 'platform')->get(route('platform.report-library.index', ['origin' => 'custom']))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 14 && $items->count() === 12);
        $this->get(route('platform.report-library.index', ['search' => 'Custom 13', 'kind' => 'widget', 'state' => 'draft']))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1);
        $this->get(route('platform.report-library.index', ['search' => 'تقرير مخصص', 'origin' => 'custom']))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 14);
        $this->assertSame(14, PlatformReportLibraryItem::query()->where('is_system', false)->count());
    }

    public function test_library_publisher_reviews_the_saved_source_and_has_no_definition_write_controls(): void
    {
        $this->user();
        $publisher = $this->user(['publish.report-library']);
        $item = $this->item('Publish review');
        $this->actingAs($publisher, 'platform')->get(route('platform.report-library.edit', ['libraryItem' => $item, 'source' => 'finance_transactions']))->assertOk()->assertViewHas('source', 'students')->assertDontSee('action="'.route('platform.report-library.update', $item).'"', false);
        $this->get(route('platform.report-library.edit', ['libraryItem' => $item, 'view' => 'publishing']))->assertOk()->assertSee(route('platform.report-library.publish', $item), false)->assertSee('Saved draft summary');
        $this->put(route('platform.report-library.update', $item), $this->reportPayload())->assertForbidden();
        $this->actingAs($this->user(['manage.report-library']), 'platform')->post(route('platform.report-library.publish', $item))->assertForbidden();
    }

    public function test_published_version_views_are_scoped_immutable_and_draft_changes_are_visible(): void
    {
        $owner = $this->user();
        $item = $this->item('Original title');
        $this->actingAs($owner, 'platform')->post(route('platform.report-library.publish', $item))->assertRedirect();
        $item->refresh();
        $revision = $item->publishedRevision;
        $this->assertFalse($item->hasUnpublishedChanges());
        $item->update(['name' => ['en' => 'Changed draft', 'ar' => 'مسودة متغيرة']]);
        $item->refresh()->unsetRelation('publishedRevision');
        $this->assertTrue($item->hasUnpublishedChanges());
        $this->get(route('platform.report-library.index', ['search' => 'Changed draft']))->assertOk()->assertSee('Unpublished draft changes');
        $this->get(route('platform.report-library.revision', [$item, $revision]))->assertOk()->assertSee('Original title')->assertDontSee('Changed draft')->assertDontSee('action="'.route('platform.report-library.publish', $item).'"', false)->assertDontSee('action="'.route('platform.report-library.update', $item).'"', false);
        $other = $this->item('Another item');
        $this->get(route('platform.report-library.revision', [$other, $revision]))->assertNotFound();
        $this->get(route('platform.report-library.edit', ['libraryItem' => $item, 'view' => 'history']))->assertOk()->assertSee(route('platform.report-library.revision', [$item, $revision]), false);
    }

    public function test_report_errors_keep_an_empty_field_selection_and_ui_can_save_no_calculations(): void
    {
        $owner = $this->user();
        $item = $this->item('Fields editor');
        $payload = $this->reportPayload();
        unset($payload['selected_fields']);
        $this->actingAs($owner, 'platform')->from(route('platform.report-library.edit', $item))->put(route('platform.report-library.update', $item), $payload)->assertSessionHasErrors('selected_fields');
        $this->get(route('platform.report-library.edit', $item))->assertOk()->assertDontSee('value="full_name" checked', false);
        $payload = $this->reportPayload();
        unset($payload['calculations']);
        $this->put(route('platform.report-library.update', $item), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], data_get($item->fresh()->draft_definition, 'calculations'));
    }

    public function test_all_builtin_templates_have_readable_definition_and_revision_views_in_arabic(): void
    {
        $owner = $this->user();
        $this->actingAs($owner, 'platform')->withSession(['locale' => 'ar', 'locale_user_selected' => true]);
        foreach (PlatformReportLibraryItem::query()->where('is_system', true)->with('publishedRevision')->get() as $item) {
            $this->get(route('platform.report-library.edit', $item))->assertOk()->assertSee('dir="rtl"', false)->assertDontSee('action="'.route('platform.report-library.update', $item).'"', false);
            $this->get(route('platform.report-library.revision', [$item, $item->publishedRevision]))->assertOk()->assertSee('تعريف منشور ثابت');
        }
        $this->get(route('platform.landing.edit'))->assertOk()->assertSee('استوديو الموقع');
    }

    private function user(?array $permissions = null): PlatformAdministrator
    {
        $user = PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Content staff', 'email' => Str::uuid().'@example.test', 'password' => 'test-password', 'is_active' => true]);
        if ($permissions !== null) {
            $role = PlatformRole::query()->create(['name' => 'Content role '.Str::uuid()]);
            $role->permissions()->sync(PlatformPermission::query()->whereIn('code', $permissions)->pluck('id'));
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    private function websitePayload(array $content): array
    {
        return ['content' => $content, 'section_order' => collect($content['section_order'])->mapWithKeys(fn ($section, $index) => [$section => $index + 1])->all(), 'enabled_sections' => collect($content['enabled_sections'])->filter()->map(fn () => '1')->all()];
    }

    private function item(string $name, array $attributes = []): PlatformReportLibraryItem
    {
        return PlatformReportLibraryItem::query()->create(['uuid' => (string) Str::uuid(), 'kind' => 'both', 'name' => ['en' => $name, 'ar' => 'تقرير مخصص'], 'description' => [], 'required_modules' => ['students'], 'draft_definition' => ['data_source' => 'students', 'selected_fields' => ['full_name'], 'calculations' => [['operation' => 'count', 'field' => null]], 'group_by' => null, 'presentation' => ['type' => 'table', 'density' => 'comfortable'], 'sort_field' => null, 'sort_direction' => 'asc', 'relationship_modes' => []], ...$attributes]);
    }

    private function reportPayload(): array
    {
        return ['workspace_form' => 'report_definition', 'kind' => 'both', 'name' => ['en' => 'Updated report', 'ar' => 'تقرير محدث'], 'data_source' => 'students', 'selected_fields' => ['full_name'], 'calculations' => ['count'], 'presentation_type' => 'table', 'table_density' => 'comfortable', 'sort_direction' => 'asc'];
    }
}
