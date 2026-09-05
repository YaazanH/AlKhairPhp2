<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as AuditActivity;

class DataAuditObserver
{
    private const CONSECUTIVE_MODULE_WINDOW_SECONDS = 300;

    public function created(Model $model): void
    {
        $this->record($model, 'created', [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = collect(Arr::except($model->getChanges(), ['updated_at']))
            ->reject(fn (mixed $value, string $field): bool => $this->valuesAreEquivalent(
                $model->getRawOriginal($field),
                $value,
            ))
            ->all();

        if ($changes === []) {
            return;
        }

        $before = collect(array_keys($changes))
            ->mapWithKeys(fn (string $field): array => [$field => $model->getRawOriginal($field)])
            ->all();

        $this->record($model, 'updated', $before, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $model->getAttributes(), []);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', [], $model->getAttributes());
    }

    protected function record(Model $model, string $event, array $before, array $after): void
    {
        if (! Auth::check() || ! Schema::hasTable('activity_log')) {
            return;
        }

        $before = $this->sanitise($before);
        $after = $this->sanitise($after);
        $actor = Auth::user();
        $entry = $this->movementEntry($model, $before, $after);
        $properties = [
            'before' => $before,
            'after' => $after,
            'entries' => $event === 'updated' ? [$entry] : [],
            'subject_label' => $entry['subject_label'],
            'subject_type_label' => class_basename($model),
            'route' => request()->route()?->getName(),
            'ip_address' => request()->ip(),
        ];

        if ($event === 'updated' && $this->mergeConsecutiveModuleUpdate($model, $actor, $entry, $properties)) {
            return;
        }

        activity('data-audit')
            ->causedBy($actor)
            ->performedOn($model)
            ->event($event)
            ->withProperties($properties)
            ->log($event.' '.class_basename($model));
    }

    protected function mergeConsecutiveModuleUpdate(Model $model, Model $actor, array $entry, array $properties): bool
    {
        $latest = AuditActivity::query()
            ->inLog('data-audit')
            ->latest('id')
            ->first();

        if (
            ! $latest
            || $latest->event !== 'updated'
            || $latest->subject_type !== $model->getMorphClass()
            || $latest->causer_type !== $actor->getMorphClass()
            || (string) $latest->causer_id !== (string) $actor->getKey()
            || $latest->created_at?->lt(now()->subSeconds(self::CONSECUTIVE_MODULE_WINDOW_SECONDS))
        ) {
            return false;
        }

        $existingProperties = $latest->properties?->toArray() ?? [];
        $entries = Arr::get($existingProperties, 'entries', []);

        if (! is_array($entries) || $entries === []) {
            $entries = [[
                'subject_type' => $latest->subject_type,
                'subject_id' => $latest->subject_id,
                'subject_label' => Arr::get($existingProperties, 'subject_label', class_basename((string) $latest->subject_type).' #'.$latest->subject_id),
                'before' => Arr::get($existingProperties, 'before', []),
                'after' => Arr::get($existingProperties, 'after', []),
            ]];
        }

        $entryIndex = collect($entries)->search(fn (mixed $candidate): bool => is_array($candidate)
            && ($candidate['subject_type'] ?? null) === $entry['subject_type']
            && (string) ($candidate['subject_id'] ?? '') === (string) $entry['subject_id']);

        if ($entryIndex === false) {
            $entries[] = $entry;
        } else {
            $existingEntry = $entries[$entryIndex];
            $existingBefore = is_array($existingEntry['before'] ?? null) ? $existingEntry['before'] : [];
            $existingAfter = is_array($existingEntry['after'] ?? null) ? $existingEntry['after'] : [];
            $entries[$entryIndex] = array_replace($existingEntry, $entry, [
                'before' => array_replace($entry['before'], $existingBefore),
                'after' => array_replace($existingAfter, $entry['after']),
            ]);
        }

        $firstEntry = $entries[0];
        $mergedAt = now();
        $latest->forceFill([
            'properties' => array_replace($existingProperties, $properties, [
                'before' => $firstEntry['before'],
                'after' => $firstEntry['after'],
                'entries' => array_values($entries),
                'subject_label' => $firstEntry['subject_label'],
                'merged_updates' => max(1, (int) Arr::get($existingProperties, 'merged_updates', 1)) + 1,
            ]),
            'created_at' => $mergedAt,
            'updated_at' => $mergedAt,
        ])->saveQuietly();

        return true;
    }

    protected function movementEntry(Model $model, array $before, array $after): array
    {
        return [
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'subject_label' => $this->subjectLabel($model),
            'before' => $before,
            'after' => $after,
        ];
    }

    protected function sanitise(array $values): array
    {
        return collect($values)
            ->reject(fn (mixed $value, string $key): bool => Str::contains(Str::lower($key), [
                'password', 'remember_token', 'token', 'secret', 'signature',
            ]))
            ->map(fn (mixed $value): mixed => $this->sanitiseValue($value))
            ->all();
    }

    protected function sanitiseValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitise($value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = $this->decodeStructuredValue($value);

        if ($decoded !== null) {
            return $this->sanitise($decoded);
        }

        return mb_strlen($value) > 1000
            ? mb_substr($value, 0, 1000).'…'
            : $value;
    }

    protected function valuesAreEquivalent(mixed $before, mixed $after): bool
    {
        if ($before === $after) {
            return true;
        }

        $decodedBefore = $this->decodeStructuredValue($before);
        $decodedAfter = $this->decodeStructuredValue($after);

        return $decodedBefore !== null
            && $decodedAfter !== null
            && $decodedBefore == $decodedAfter;
    }

    protected function decodeStructuredValue(mixed $value): ?array
    {
        if (! is_string($value) || ! Str::startsWith(trim($value), ['{', '['])) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : null;
    }

    protected function subjectLabel(Model $model): string
    {
        foreach (['name', 'title', 'student_number', 'parent_number', 'invoice_number', 'transaction_number', 'number'] as $field) {
            $value = $model->getAttribute($field);

            if (filled($value)) {
                return (string) $value;
            }
        }

        if (filled($model->getAttribute('first_name')) || filled($model->getAttribute('last_name'))) {
            return trim($model->getAttribute('first_name').' '.$model->getAttribute('last_name'));
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
