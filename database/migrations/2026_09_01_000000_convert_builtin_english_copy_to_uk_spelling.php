<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameBuiltInPointLabels([
            'Memorization Page' => 'Memorisation Page',
        ], [
            'Memorization 1 Page Tier' => 'Memorisation 1 Page Tier',
            'Memorization 2 Pages Tier' => 'Memorisation 2 Pages Tier',
            'Memorization 3 Pages Tier' => 'Memorisation 3 Pages Tier',
            'Memorization Page Reward' => 'Memorisation Page Reward',
        ]);

        $this->translateStoredWebsiteCopy($this->usToUk());
    }

    public function down(): void
    {
        $this->renameBuiltInPointLabels([
            'Memorisation Page' => 'Memorization Page',
        ], [
            'Memorisation 1 Page Tier' => 'Memorization 1 Page Tier',
            'Memorisation 2 Pages Tier' => 'Memorization 2 Pages Tier',
            'Memorisation 3 Pages Tier' => 'Memorization 3 Pages Tier',
            'Memorisation Page Reward' => 'Memorization Page Reward',
        ]);

        $this->translateStoredWebsiteCopy(array_flip($this->usToUk()));
    }

    private function renameBuiltInPointLabels(array $pointTypes, array $pointPolicies): void
    {
        if (Schema::hasTable('point_types')) {
            foreach ($pointTypes as $from => $to) {
                DB::table('point_types')
                    ->where('code', 'memorization-page')
                    ->where('name', $from)
                    ->update(['name' => $to]);
            }
        }

        if (Schema::hasTable('point_policies')) {
            foreach ($pointPolicies as $from => $to) {
                DB::table('point_policies')
                    ->where('source_type', 'memorization')
                    ->where('name', $from)
                    ->update(['name' => $to]);
            }
        }
    }

    private function translateStoredWebsiteCopy(array $replacements): void
    {
        $this->translateJsonColumns('website_pages', [
            'title',
            'navigation_label',
            'excerpt',
            'body',
            'sections',
            'settings',
            'seo_title',
            'seo_description',
        ], $replacements);
        $this->translateJsonColumns('website_menus', ['title', 'settings'], $replacements);
        $this->translateJsonColumns('website_menu_items', ['label'], $replacements);

        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')
            ->where('group', 'website')
            ->where('type', 'array')
            ->orderBy('id')
            ->get(['id', 'value'])
            ->each(function (object $row) use ($replacements): void {
                $translated = $this->translateJson($row->value, $replacements);

                if ($translated !== null && $translated !== $row->value) {
                    DB::table('app_settings')->where('id', $row->id)->update(['value' => $translated]);
                }
            });
    }

    private function translateJsonColumns(string $table, array $columns, array $replacements): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($columns === []) {
            return;
        }

        DB::table($table)
            ->orderBy('id')
            ->get(array_merge(['id'], $columns))
            ->each(function (object $row) use ($table, $columns, $replacements): void {
                $updates = [];

                foreach ($columns as $column) {
                    $original = $row->{$column};

                    if (! is_string($original) || $original === '') {
                        continue;
                    }

                    $translated = $this->translateJson($original, $replacements);

                    if ($translated !== null && $translated !== $original) {
                        $updates[$column] = $translated;
                    }
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });
    }

    private function translateJson(string $json, array $replacements): ?string
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $translated = $this->translateValue($value, $replacements);

            return json_encode($translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function translateValue(mixed $value, array $replacements, bool $english = false): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->translateValue($child, $replacements, $english || $key === 'en');
            }

            return $value;
        }

        if (! $english || ! is_string($value)) {
            return $value;
        }

        $pattern = '/\b('.implode('|', array_map(
            fn (string $word): string => preg_quote($word, '/'),
            array_keys($replacements),
        )).')\b/i';

        return preg_replace_callback($pattern, function (array $match) use ($replacements): string {
            $replacement = $replacements[strtolower($match[0])];

            if ($match[0] === strtoupper($match[0])) {
                return strtoupper($replacement);
            }

            if ($match[0] === ucfirst(strtolower($match[0]))) {
                return ucfirst($replacement);
            }

            return $replacement;
        }, $value);
    }

    private function usToUk(): array
    {
        return [
            'organizations' => 'organisations',
            'organizational' => 'organisational',
            'organization' => 'organisation',
            'enrollments' => 'enrolments',
            'enrollment' => 'enrolment',
            'memorizing' => 'memorising',
            'memorized' => 'memorised',
            'memorization' => 'memorisation',
            'colors' => 'colours',
            'color' => 'colour',
            'centering' => 'centring',
            'centered' => 'centred',
            'center' => 'centre',
            'customizations' => 'customisations',
            'customization' => 'customisation',
            'customized' => 'customised',
            'customizing' => 'customising',
            'customize' => 'customise',
            'unauthorized' => 'unauthorised',
            'authorization' => 'authorisation',
            'authorized' => 'authorised',
            'optimizing' => 'optimising',
            'optimized' => 'optimised',
            'optimize' => 'optimise',
            'programs' => 'programmes',
            'program' => 'programme',
            'catalogs' => 'catalogues',
            'catalog' => 'catalogue',
            'uncategorized' => 'uncategorised',
            'categorized' => 'categorised',
            'categorizing' => 'categorising',
            'categorize' => 'categorise',
            'itemized' => 'itemised',
            'finalized' => 'finalised',
            'analyzed' => 'analysed',
            'analyzing' => 'analysing',
            'analyze' => 'analyse',
            'recognized' => 'recognised',
            'recognizing' => 'recognising',
            'recognize' => 'recognise',
            'canceled' => 'cancelled',
            'canceling' => 'cancelling',
            'traveled' => 'travelled',
            'traveling' => 'travelling',
            'travelers' => 'travellers',
            'traveler' => 'traveller',
            'labeled' => 'labelled',
            'labeling' => 'labelling',
            'modeled' => 'modelled',
            'modeling' => 'modelling',
            'behavior' => 'behaviour',
            'favorites' => 'favourites',
            'favorite' => 'favourite',
            'gray' => 'grey',
            'fulfillment' => 'fulfilment',
            'fulfilled' => 'fulfilled',
            'fulfill' => 'fulfil',
            'neighborhoods' => 'neighbourhoods',
            'neighborhood' => 'neighbourhood',
            'toward' => 'towards',
        ];
    }
};
