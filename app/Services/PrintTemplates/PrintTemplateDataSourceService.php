<?php

namespace App\Services\PrintTemplates;

class PrintTemplateDataSourceService
{
    public function normalize(array $sources): array
    {
        $normalized = collect($sources)
            ->filter(fn (mixed $source) => is_array($source))
            ->map(function (array $source) {
                $entity = (string) ($source['entity'] ?? $source['key'] ?? '');

                if (! array_key_exists($entity, app(PrintTemplateFieldRegistry::class)->entities())) {
                    return null;
                }

                $mode = in_array(($source['mode'] ?? 'single'), ['single', 'multiple'], true)
                    ? (string) ($source['mode'] ?? 'single')
                    : 'single';

                return [
                    'key' => $entity,
                    'entity' => $entity,
                    'mode' => $mode,
                ];
            })
            ->filter()
            ->unique('entity')
            ->values();

        $hasRepeatingSource = false;

        return $normalized
            ->map(function (array $source) use (&$hasRepeatingSource) {
                if ($source['mode'] === 'multiple') {
                    if ($hasRepeatingSource) {
                        $source['mode'] = 'single';
                    } else {
                        $hasRepeatingSource = true;
                    }
                }

                return $source;
            })
            ->values()
            ->all();
    }

    public function repeatingSource(array $sources): ?array
    {
        return collect($this->normalize($sources))->firstWhere('mode', 'multiple');
    }
}
