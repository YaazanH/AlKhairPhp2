<?php

namespace App\Console\Commands;

use App\Models\ParentProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BackfillParentFamilyNamesCommand extends Command
{
    protected $signature = 'legacy:backfill-parent-family-names
        {--dry-run : Preview the parent names that would be updated without saving them}';

    protected $description = 'Append the shared student family name to imported parent father names when it is missing.';

    public function handle(): int
    {
        $rows = [];
        $updated = 0;
        $skipped = 0;

        ParentProfile::query()
            ->with(['students:id,parent_id,last_name'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $parents) use (&$rows, &$updated, &$skipped): void {
                foreach ($parents as $parent) {
                    $currentName = $this->cleanString($parent->father_name);

                    if ($currentName === null) {
                        $skipped++;
                        continue;
                    }

                    $studentLastNames = $parent->students
                        ->pluck('last_name')
                        ->map(fn ($name) => $this->cleanString($name))
                        ->filter()
                        ->unique()
                        ->values();

                    if ($studentLastNames->count() !== 1) {
                        $skipped++;
                        continue;
                    }

                    $familyName = (string) $studentLastNames->first();
                    $updatedName = $this->appendFamilyName($currentName, $familyName);

                    if ($updatedName === $currentName) {
                        continue;
                    }

                    $rows[] = [$parent->id, $currentName, $updatedName];
                    $updated++;

                    if (! $this->option('dry-run')) {
                        $parent->father_name = $updatedName;
                        $parent->save();
                    }
                }
            });

        if ($rows !== []) {
            $this->table(['Parent ID', 'Current name', 'Updated name'], array_slice($rows, 0, 20));

            if (count($rows) > 20) {
                $this->line('Showing first 20 updates only.');
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated parents', $updated],
                ['Skipped parents', $skipped],
            ],
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run enabled: no parent names were updated.');
        }

        return self::SUCCESS;
    }

    protected function appendFamilyName(string $fatherName, string $familyName): string
    {
        if ($this->nameContainsPart($fatherName, $familyName)) {
            return $fatherName;
        }

        return trim($fatherName.' '.$familyName);
    }

    protected function extractLastName(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];

        return $parts === [] ? $fullName : (string) end($parts);
    }

    protected function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalize(?string $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    protected function nameContainsPart(string $name, string $part): bool
    {
        $nameParts = preg_split('/\s+/u', trim($name)) ?: [];
        $normalizedPart = $this->normalize($part);

        foreach ($nameParts as $namePart) {
            if ($this->normalize($namePart) === $normalizedPart) {
                return true;
            }
        }

        return false;
    }
}
