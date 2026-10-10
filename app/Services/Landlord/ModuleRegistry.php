<?php

namespace App\Services\Landlord;

use DomainException;

class ModuleRegistry
{
    public function definitions(): array
    {
        return config('modules.definitions', []);
    }

    public function expandLegacy(array $codes, bool $legacyPackage): array
    {
        $expanded = [];
        foreach ($codes as $code) {
            $modules = match ($code) {
                'core' => config('modules.legacy_core'),
                'custom_printing' => ['custom_templates', 'id_cards'],
                'finance' => $legacyPackage ? ['finance', 'student_billing'] : ['finance'],
                default => [$code],
            };
            $expanded = array_merge($expanded, $modules);
        }

        return array_values(array_unique($expanded));
    }

    public function resolve(array $package, array $extras = [], array $unavailable = []): array
    {
        $definitions = $this->definitions();
        $sources = [];
        $visiting = [];
        $visit = function (string $code, string $source) use (&$visit, &$sources, &$visiting, $definitions, $unavailable): void {
            if (! isset($definitions[$code])) {
                throw new DomainException("Unknown module: {$code}");
            }
            if (in_array($code, $unavailable, true)) {
                throw new DomainException("Unavailable module: {$code}");
            }
            if (isset($visiting[$code])) {
                throw new DomainException("Cyclic module dependency: {$code}");
            }
            $sources[$code][] = $source;
            $visiting[$code] = true;
            foreach ($definitions[$code]['requires'] as $required) {
                $visit($required, 'dependency:'.$code);
            }
            unset($visiting[$code]);
        };
        $visit('foundation', 'foundation');
        foreach (['package' => $package, 'extra' => $extras] as $source => $codes) {
            foreach (array_unique($codes) as $code) {
                $visit($code, $source);
            }
        }
        foreach (array_keys($sources) as $code) {
            $any = $definitions[$code]['requires_any'] ?? [];
            if ($any && ! array_intersect($any, array_keys($sources))) {
                throw new DomainException($code.' requires at least one of: '.implode(', ', $any));
            }
            $sources[$code] = array_values(array_unique($sources[$code]));
            sort($sources[$code]);
        }
        ksort($sources);

        return $sources;
    }
}
