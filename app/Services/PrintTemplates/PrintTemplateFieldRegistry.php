<?php

namespace App\Services\PrintTemplates;

use App\Models\Activity;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AvatarDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrintTemplateFieldRegistry
{
    public function entities(): array
    {
        return [
            'student' => [
                'label' => __('print_templates.entities.student'),
                'model' => Student::class,
                'relations' => ['user', 'parentProfile', 'gradeLevel', 'enrollments.group'],
            ],
            'teacher' => [
                'label' => __('print_templates.entities.teacher'),
                'model' => Teacher::class,
                'relations' => ['user', 'jobTitle', 'course'],
            ],
            'parent' => [
                'label' => __('print_templates.entities.parent'),
                'model' => ParentProfile::class,
                'relations' => ['user'],
            ],
            'user' => [
                'label' => __('print_templates.entities.user'),
                'model' => User::class,
                'relations' => [],
            ],
            'activity' => [
                'label' => __('print_templates.entities.activity'),
                'model' => Activity::class,
                'relations' => ['group'],
            ],
        ];
    }

    public function definitions(): array
    {
        return [
            'student' => [
                'full_name' => $this->field('full_name', ['text', 'barcode'], fn (Student $student) => $student->full_name),
                'first_name' => $this->field('first_name', ['text'], fn (Student $student) => $student->first_name),
                'last_name' => $this->field('last_name', ['text'], fn (Student $student) => $student->last_name),
                'student_number' => $this->field('student_number', ['text', 'barcode'], fn (Student $student) => (string) ($student->student_number ?: $student->id)),
                'group_name' => $this->field('group_name', ['text'], fn (Student $student) => $student->currentGroupName() ?: __('print_templates.common.not_available')),
                'parent_name' => $this->field('parent_name', ['text'], fn (Student $student) => $student->parentProfile?->father_name ?: __('print_templates.common.not_available')),
                'username' => $this->field('username', ['text', 'barcode'], fn (Student $student) => $student->user?->username ?: __('print_templates.common.not_available')),
                'password' => $this->field('password', ['text'], fn (Student $student) => $student->user?->issued_password ?: __('print_templates.common.not_available')),
                'photo' => $this->field('photo', ['image'], fn (Student $student) => $this->storageUrl($student->photo_path) ?: AvatarDefaults::url('student')),
            ],
            'teacher' => [
                'full_name' => $this->field('full_name', ['text', 'barcode'], fn (Teacher $teacher) => trim($teacher->first_name.' '.$teacher->last_name)),
                'first_name' => $this->field('first_name', ['text'], fn (Teacher $teacher) => $teacher->first_name),
                'last_name' => $this->field('last_name', ['text'], fn (Teacher $teacher) => $teacher->last_name),
                'phone' => $this->field('phone', ['text', 'barcode'], fn (Teacher $teacher) => $teacher->phone ?: __('print_templates.common.not_available')),
                'job_title' => $this->field('job_title', ['text'], fn (Teacher $teacher) => $teacher->accessRole?->name ?: ($teacher->jobTitle?->name ?: ($teacher->job_title ?: __('print_templates.common.not_available')))),
                'course' => $this->field('course', ['text'], fn (Teacher $teacher) => $teacher->course?->name ?: __('print_templates.common.not_available')),
                'username' => $this->field('username', ['text', 'barcode'], fn (Teacher $teacher) => $teacher->user?->username ?: __('print_templates.common.not_available')),
                'password' => $this->field('password', ['text'], fn (Teacher $teacher) => $teacher->user?->issued_password ?: __('print_templates.common.not_available')),
                'photo' => $this->field('photo', ['image'], fn (Teacher $teacher) => $this->storageUrl($teacher->photo_path) ?: AvatarDefaults::url('teacher')),
            ],
            'parent' => [
                'father_name' => $this->field('father_name', ['text', 'barcode'], fn (ParentProfile $parent) => $parent->father_name),
                'mother_name' => $this->field('mother_name', ['text'], fn (ParentProfile $parent) => $parent->mother_name ?: __('print_templates.common.not_available')),
                'father_phone' => $this->field('father_phone', ['text', 'barcode'], fn (ParentProfile $parent) => $parent->father_phone ?: __('print_templates.common.not_available')),
                'mother_phone' => $this->field('mother_phone', ['text', 'barcode'], fn (ParentProfile $parent) => $parent->mother_phone ?: __('print_templates.common.not_available')),
                'address' => $this->field('address', ['text'], fn (ParentProfile $parent) => $parent->address ?: __('print_templates.common.not_available')),
                'username' => $this->field('username', ['text', 'barcode'], fn (ParentProfile $parent) => $parent->user?->username ?: __('print_templates.common.not_available')),
                'password' => $this->field('password', ['text'], fn (ParentProfile $parent) => $parent->user?->issued_password ?: __('print_templates.common.not_available')),
            ],
            'user' => [
                'name' => $this->field('name', ['text', 'barcode'], fn (User $user) => $user->name),
                'username' => $this->field('username', ['text', 'barcode'], fn (User $user) => $user->username ?: __('print_templates.common.not_available')),
                'email' => $this->field('email', ['text', 'barcode'], fn (User $user) => $user->email ?: __('print_templates.common.not_available')),
                'phone' => $this->field('phone', ['text', 'barcode'], fn (User $user) => $user->phone ?: __('print_templates.common.not_available')),
                'password' => $this->field('password', ['text'], fn (User $user) => $user->issued_password ?: __('print_templates.common.not_available')),
                'photo' => $this->field('photo', ['image'], fn (User $user) => $user->profilePhotoUrl()),
            ],
            'activity' => [
                'title' => $this->field('title', ['text', 'barcode'], fn (Activity $activity) => $activity->title),
                'description' => $this->field('description', ['text'], fn (Activity $activity) => $activity->description ?: __('print_templates.common.not_available')),
                'activity_date' => $this->field('activity_date', ['text'], fn (Activity $activity) => $activity->activity_date?->format('Y-m-d') ?: __('print_templates.common.not_available')),
                'fee_amount' => $this->field('fee_amount', ['text'], fn (Activity $activity) => number_format((float) $activity->fee_amount, 2)),
                'group_name' => $this->field('group_name', ['text'], fn (Activity $activity) => $activity->group?->name ?: __('print_templates.common.not_available')),
            ],
        ];
    }

    public function entityOptions(): array
    {
        return collect($this->entities())
            ->map(fn (array $definition, string $entity) => [
                'key' => $entity,
                'label' => $definition['label'],
            ])
            ->values()
            ->all();
    }

    public function selectableFields(string $elementType): array
    {
        $type = $elementType === 'barcode' ? 'barcode' : ($elementType === 'dynamic_image' ? 'image' : 'text');

        return collect($this->definitions())
            ->map(function (array $fields, string $entity) use ($type) {
                return [
                    'entity' => $entity,
                    'entity_label' => $this->entities()[$entity]['label'],
                    'fields' => collect($fields)
                        ->filter(fn (array $definition) => in_array($type, $definition['element_types'], true))
                        ->map(fn (array $definition, string $field) => [
                            'key' => $field,
                            'label' => $definition['label'],
                            'path' => $entity.'.'.$field,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $group) => $group['fields'] !== [])
            ->values()
            ->all();
    }

    public function firstFieldFor(string $elementType, ?string $entity = null): ?array
    {
        $groups = $this->selectableFields($elementType);
        $group = $entity
            ? collect($groups)->firstWhere('entity', $entity)
            : collect($groups)->first();

        $field = $group['fields'][0] ?? null;

        return $field ? ['source' => $group['entity'], 'field' => $field['key']] : null;
    }

    public function queryFor(string $entity): Builder
    {
        $definition = $this->entities()[$entity] ?? null;

        abort_if(! $definition, 404);

        /** @var class-string<Model> $model */
        $model = $definition['model'];

        return $model::query()->with($definition['relations']);
    }

    public function optionsFor(string $entity): array
    {
        return $this->queryFor($entity)
            ->latest('id')
            ->limit(600)
            ->get()
            ->sortBy(fn (Model $model) => Str::lower($this->recordLabel($entity, $model)))
            ->map(fn (Model $model) => [
                'id' => $model->getKey(),
                'label' => $this->recordLabel($entity, $model),
                'search' => Str::lower($this->recordSearchText($entity, $model)),
                'meta' => $this->recordMeta($entity, $model),
            ])
            ->values()
            ->all();
    }

    public function findMany(string $entity, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $models = $this->queryFor($entity)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $models->get($id))
            ->filter()
            ->values()
            ->all();
    }

    public function resolve(array $context, ?string $source, ?string $field): mixed
    {
        if (! $source || ! $field || ! isset($context[$source])) {
            return null;
        }

        $definition = $this->definitions()[$source][$field] ?? null;

        if (! $definition) {
            return null;
        }

        return value($definition['resolver'], $context[$source]);
    }

    public function replacePlaceholders(string $content, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\.([a-z_]+)\s*\}\}/i', function (array $matches) use ($context) {
            $value = $this->resolve($context, $matches[1], $matches[2]);

            return is_scalar($value) ? (string) $value : __('print_templates.common.not_available');
        }, $content) ?? $content;
    }

    protected function field(string $labelKey, array $elementTypes, callable $resolver): array
    {
        return [
            'label' => __('print_templates.fields.'.$labelKey),
            'element_types' => $elementTypes,
            'resolver' => $resolver,
        ];
    }

    protected function recordLabel(string $entity, Model $model): string
    {
        return match ($entity) {
            'student' => trim($model->first_name.' '.$model->last_name).' #'.($model->student_number ?: $model->id),
            'teacher' => trim($model->first_name.' '.$model->last_name),
            'parent' => (string) $model->father_name,
            'user' => trim($model->name.' '.($model->username ? '('.$model->username.')' : '')),
            'activity' => trim($model->title.' '.($model->activity_date ? '('.$model->activity_date->format('Y-m-d').')' : '')),
            default => (string) $model->getKey(),
        };
    }

    protected function recordSearchText(string $entity, Model $model): string
    {
        return match ($entity) {
            'student' => trim($model->full_name.' '.$model->student_number.' '.$model->parentProfile?->father_name),
            'teacher' => trim($model->first_name.' '.$model->last_name.' '.$model->phone.' '.$model->user?->username),
            'parent' => trim($model->father_name.' '.$model->mother_name.' '.$model->father_phone.' '.$model->user?->username),
            'user' => trim($model->name.' '.$model->username.' '.$model->email.' '.$model->phone),
            'activity' => trim($model->title.' '.$model->description),
            default => (string) $model->getKey(),
        };
    }

    protected function recordMeta(string $entity, Model $model): array
    {
        return match ($entity) {
            'student' => [
                'parent_id' => $model->parent_id,
            ],
            default => [],
        };
    }

    protected function storageUrl(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }
}
