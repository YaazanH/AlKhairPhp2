<?php

namespace App\Services\PrintTemplates;

use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\FinanceRequest;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ActivityAudienceService;
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
                'relations' => ['user', 'jobTitle', 'course', 'assignedGroups', 'assistedGroups'],
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
                'relations' => ['group', 'targetGroups'],
            ],
            'finance_request' => [
                'label' => __('print_templates.entities.finance_request'),
                'model' => FinanceRequest::class,
                'relations' => ['activity', 'cashBox', 'category', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency'],
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
                'parent_number' => $this->field('parent_number', ['text', 'barcode'], fn (ParentProfile $parent) => $parent->parent_number ?: __('print_templates.common.not_available')),
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
            'finance_request' => [
                'request_no' => $this->field('request_no', ['text', 'barcode'], fn (FinanceRequest $request) => $request->request_no),
                'type' => $this->field('type', ['text'], fn (FinanceRequest $request) => ucfirst($request->type)),
                'requested_amount' => $this->field('requested_amount', ['text'], fn (FinanceRequest $request) => number_format((float) $request->requested_amount, 2).' '.$request->requestedCurrency?->code),
                'accepted_amount' => $this->field('accepted_amount', ['text'], fn (FinanceRequest $request) => $request->accepted_amount !== null ? number_format((float) $request->accepted_amount, 2).' '.$request->acceptedCurrency?->code : __('print_templates.common.not_available')),
                'cash_box' => $this->field('cash_box', ['text'], fn (FinanceRequest $request) => $request->cashBox?->name ?: __('print_templates.common.not_available')),
                'activity' => $this->field('activity', ['text'], fn (FinanceRequest $request) => $request->activity?->title ?: __('print_templates.common.not_available')),
                'requested_by' => $this->field('requested_by', ['text'], fn (FinanceRequest $request) => $request->requestedBy?->name ?: __('print_templates.common.not_available')),
                'reviewed_by' => $this->field('reviewed_by', ['text'], fn (FinanceRequest $request) => $request->reviewedBy?->name ?: __('print_templates.common.not_available')),
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

    public function relationIdsFor(string $entity, Model $model, string $targetEntity): ?array
    {
        $key = $targetEntity.'_ids';
        $meta = $this->recordMeta($entity, $model);

        if (! array_key_exists($key, $meta)) {
            return null;
        }

        return collect($meta[$key])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function modelsAreRelated(string $sourceEntity, Model $sourceModel, string $targetEntity, Model $targetModel): bool
    {
        $sourceAllowedIds = $this->relationIdsFor($sourceEntity, $sourceModel, $targetEntity);

        if ($sourceAllowedIds !== null && ! in_array((int) $targetModel->getKey(), $sourceAllowedIds, true)) {
            return false;
        }

        $targetAllowedIds = $this->relationIdsFor($targetEntity, $targetModel, $sourceEntity);

        if ($targetAllowedIds !== null && ! in_array((int) $sourceModel->getKey(), $targetAllowedIds, true)) {
            return false;
        }

        return true;
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
            'finance_request' => trim($model->request_no.' '.ucfirst((string) $model->type)),
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
            'finance_request' => trim($model->request_no.' '.$model->type.' '.$model->requestedBy?->name.' '.$model->activity?->title),
            default => (string) $model->getKey(),
        };
    }

    protected function recordMeta(string $entity, Model $model): array
    {
        return match ($entity) {
            'student' => [
                'activity_ids' => $this->studentActivityIds($model),
                'group_ids' => $this->studentGroupIds($model),
                'parent_ids' => [(int) $model->parent_id],
                'teacher_ids' => $this->studentTeacherIds($model),
            ],
            'teacher' => [
                'activity_ids' => $this->teacherActivityIds($model),
                'group_ids' => $this->teacherGroupIds($model),
                'student_ids' => $this->teacherStudentIds($model),
            ],
            'parent' => [
                'student_ids' => Student::query()
                    ->where('parent_id', $model->getKey())
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ],
            'activity' => [
                'group_ids' => $this->activityGroupIds($model),
                'student_ids' => app(ActivityAudienceService::class)->eligibleStudentIds($model),
                'teacher_ids' => $this->activityTeacherIds($model),
            ],
            default => [],
        };
    }

    protected function activityGroupIds(Activity $activity): array
    {
        $groupIds = app(ActivityAudienceService::class)->targetedGroupIds($activity);

        if ($groupIds !== []) {
            return $groupIds;
        }

        return Enrollment::query()
            ->where('status', 'active')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function activityTeacherIds(Activity $activity): array
    {
        return $this->teacherIdsForGroups($this->activityGroupIds($activity));
    }

    protected function studentActivityIds(Student $student): array
    {
        $groupIds = $this->studentGroupIds($student);

        if ($groupIds === []) {
            return [];
        }

        $allGroupActivityIds = Activity::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('audience_scope')
                ->orWhere('audience_scope', ActivityAudienceService::SCOPE_ALL_GROUPS))
            ->pluck('id');

        $singleGroupActivityIds = Activity::query()
            ->where('audience_scope', ActivityAudienceService::SCOPE_SINGLE_GROUP)
            ->whereIn('group_id', $groupIds)
            ->pluck('id');

        $multipleGroupActivityIds = Activity::query()
            ->where('audience_scope', ActivityAudienceService::SCOPE_MULTIPLE_GROUPS)
            ->whereHas('targetGroups', fn (Builder $query) => $query->whereIn('groups.id', $groupIds))
            ->pluck('id');

        return $allGroupActivityIds
            ->merge($singleGroupActivityIds)
            ->merge($multipleGroupActivityIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function studentGroupIds(Student $student): array
    {
        if ($student->relationLoaded('enrollments')) {
            return $student->enrollments
                ->filter(fn (Enrollment $enrollment) => $enrollment->status === 'active')
                ->pluck('group_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('status', 'active')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function studentTeacherIds(Student $student): array
    {
        return $this->teacherIdsForGroups($this->studentGroupIds($student));
    }

    protected function teacherActivityIds(Teacher $teacher): array
    {
        $groupIds = $this->teacherGroupIds($teacher);

        if ($groupIds === []) {
            return [];
        }

        $allGroupActivityIds = Activity::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('audience_scope')
                ->orWhere('audience_scope', ActivityAudienceService::SCOPE_ALL_GROUPS))
            ->pluck('id');

        $singleGroupActivityIds = Activity::query()
            ->where('audience_scope', ActivityAudienceService::SCOPE_SINGLE_GROUP)
            ->whereIn('group_id', $groupIds)
            ->pluck('id');

        $multipleGroupActivityIds = Activity::query()
            ->where('audience_scope', ActivityAudienceService::SCOPE_MULTIPLE_GROUPS)
            ->whereHas('targetGroups', fn (Builder $query) => $query->whereIn('groups.id', $groupIds))
            ->pluck('id');

        return $allGroupActivityIds
            ->merge($singleGroupActivityIds)
            ->merge($multipleGroupActivityIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function teacherGroupIds(Teacher $teacher): array
    {
        $assigned = $teacher->relationLoaded('assignedGroups')
            ? $teacher->assignedGroups->pluck('id')
            : Group::query()->where('teacher_id', $teacher->getKey())->pluck('id');

        $assisted = $teacher->relationLoaded('assistedGroups')
            ? $teacher->assistedGroups->pluck('id')
            : Group::query()->where('assistant_teacher_id', $teacher->getKey())->pluck('id');

        return $assigned
            ->merge($assisted)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function teacherStudentIds(Teacher $teacher): array
    {
        $groupIds = $this->teacherGroupIds($teacher);

        if ($groupIds === []) {
            return [];
        }

        return Enrollment::query()
            ->where('status', 'active')
            ->whereIn('group_id', $groupIds)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function teacherIdsForGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        return Group::query()
            ->whereIn('id', $groupIds)
            ->get(['teacher_id', 'assistant_teacher_id'])
            ->flatMap(fn (Group $group) => [$group->teacher_id, $group->assistant_teacher_id])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function storageUrl(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }
}
