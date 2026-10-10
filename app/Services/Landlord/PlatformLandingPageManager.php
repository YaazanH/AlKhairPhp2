<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformLandingPage;
use App\Models\Landlord\PlatformLandingPageRevision;
use App\Support\PlatformLandingContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformLandingPageManager
{
    public function page(): PlatformLandingPage
    {
        return PlatformLandingPage::query()->firstOrCreate(
            ['key' => 'main'],
            ['draft_content' => PlatformLandingContent::defaults()],
        );
    }

    public function saveDraft(array $input, array $images, PlatformAdministrator $actor, ?string $ipAddress): PlatformLandingPage
    {
        $page = $this->page();
        $content = PlatformLandingContent::normalize($page->draft_content);

        foreach ($this->localizedFields() as $field) {
            foreach (['en', 'ar'] as $locale) {
                Arr::set($content, $field.'.'.$locale, $this->text(Arr::get($input, $field.'.'.$locale)));
            }
        }

        foreach (['feature_items', 'showcase_items', 'faq_items'] as $collection) {
            foreach ($content[$collection] as $index => $item) {
                foreach (array_keys($item) as $field) {
                    if ($field === 'image_path') {
                        continue;
                    }
                    foreach (['en', 'ar'] as $locale) {
                        Arr::set($content, "$collection.$index.$field.$locale", $this->text(Arr::get($input, "$collection.$index.$field.$locale")));
                    }
                }
            }
        }

        $orders = collect(PlatformLandingContent::SECTIONS)
            ->mapWithKeys(fn ($section) => [$section => (int) Arr::get($input, 'section_order.'.$section, 99)])
            ->sort()->keys()->values()->all();
        $content['section_order'] = $orders;
        $content['enabled_sections'] = collect(PlatformLandingContent::SECTIONS)
            ->mapWithKeys(fn ($section) => [$section => (bool) Arr::get($input, 'enabled_sections.'.$section, false)])
            ->all();

        foreach ($images as $index => $image) {
            if (! $image instanceof UploadedFile) {
                continue;
            }
            $oldPath = Arr::get($content, "showcase_items.$index.image_path");
            $path = $image->storePublicly('platform/landing', ['disk' => 'public']);
            Arr::set($content, "showcase_items.$index.image_path", $path);
            if ($oldPath && $oldPath !== $path && ! $this->pathUsedByPublishedRevision($oldPath, $page)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $page->update(['draft_content' => $content]);
        $this->audit($actor, 'platform_landing_draft_updated', ['page_id' => $page->id], $ipAddress);

        return $page->fresh();
    }

    public function publish(PlatformAdministrator $actor, ?string $ipAddress): PlatformLandingPageRevision
    {
        $page = $this->page();
        $content = PlatformLandingContent::normalize($page->draft_content);
        $this->validatePublishable($content);

        return DB::connection('landlord')->transaction(function () use ($page, $content, $actor, $ipAddress): PlatformLandingPageRevision {
            $number = ((int) $page->revisions()->max('revision_number')) + 1;
            $revision = $page->revisions()->create([
                'revision_number' => $number,
                'content' => $content,
                'published_by_platform_administrator_id' => $actor->id,
                'published_at' => now(),
            ]);
            $page->update(['published_revision_id' => $revision->id, 'published_at' => $revision->published_at]);
            $this->audit($actor, 'platform_landing_published', ['page_id' => $page->id, 'revision' => $number], $ipAddress);

            return $revision;
        });
    }

    public function restore(PlatformLandingPageRevision $revision, PlatformAdministrator $actor, ?string $ipAddress): PlatformLandingPageRevision
    {
        $page = $this->page();
        abort_unless($revision->platform_landing_page_id === $page->id, 404);
        $page->update(['draft_content' => PlatformLandingContent::normalize($revision->content)]);

        $restored = $this->publish($actor, $ipAddress);
        $this->audit($actor, 'platform_landing_revision_restored', [
            'page_id' => $page->id,
            'source_revision' => $revision->revision_number,
            'published_revision' => $restored->revision_number,
        ], $ipAddress);

        return $restored;
    }

    private function validatePublishable(array $content): void
    {
        $rules = [];
        $attributes = [];
        foreach ($this->localizedFields() as $field) {
            foreach (['en' => 'English', 'ar' => 'Arabic'] as $locale => $language) {
                $rules[$field.'.'.$locale] = ['required', 'string', 'max:1500'];
                $attributes[$field.'.'.$locale] = "$language ".str($field)->replace('_', ' ')->replace('.', ' ')->headline();
            }
        }
        foreach (['feature_items' => ['title', 'body'], 'showcase_items' => ['title', 'body'], 'faq_items' => ['question', 'answer']] as $collection => $fields) {
            foreach ($content[$collection] as $index => $item) {
                foreach ($fields as $field) {
                    foreach (['en' => 'English', 'ar' => 'Arabic'] as $locale => $language) {
                        $path = "$collection.$index.$field.$locale";
                        $rules[$path] = ['required', 'string', 'max:1500'];
                        $attributes[$path] = "$language ".str($collection.' '.($index + 1).' '.$field)->replace('_', ' ')->headline();
                    }
                }
            }
        }

        $validator = Validator::make($content, $rules, [], $attributes);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function localizedFields(): array
    {
        return [
            'brand', 'meta_title', 'meta_description', 'hero_eyebrow', 'hero_title', 'hero_body',
            'hero_primary_label', 'hero_secondary_label', 'features_kicker', 'features_title', 'features_body',
            'showcase_kicker', 'showcase_title', 'showcase_body', 'packages_kicker', 'packages_title',
            'packages_body', 'faq_kicker', 'faq_title', 'contact_kicker', 'contact_title', 'contact_body',
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function pathUsedByPublishedRevision(string $path, PlatformLandingPage $page): bool
    {
        return str_contains(json_encode($page->publishedRevision?->content ?? [], JSON_THROW_ON_ERROR), $path);
    }

    private function audit(PlatformAdministrator $actor, string $event, array $properties, ?string $ipAddress): void
    {
        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $actor->id,
            'event' => $event,
            'properties' => $properties,
            'ip_address' => $ipAddress,
        ]);
    }
}
