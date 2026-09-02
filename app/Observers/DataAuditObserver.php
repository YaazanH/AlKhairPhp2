<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataAuditObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = Arr::except($model->getChanges(), ['updated_at']);

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

        activity('data-audit')
            ->causedBy($actor)
            ->performedOn($model)
            ->event($event)
            ->withProperties([
                'before' => $before,
                'after' => $after,
                'subject_label' => $this->subjectLabel($model),
                'subject_type_label' => class_basename($model),
                'route' => request()->route()?->getName(),
                'ip_address' => request()->ip(),
            ])
            ->log($event.' '.class_basename($model));
    }

    protected function sanitise(array $values): array
    {
        return collect($values)
            ->reject(fn (mixed $value, string $key): bool => Str::contains(Str::lower($key), [
                'password', 'remember_token', 'token', 'secret', 'signature',
            ]))
            ->map(fn (mixed $value): mixed => is_string($value) && mb_strlen($value) > 1000
                ? mb_substr($value, 0, 1000).'…'
                : $value)
            ->all();
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
