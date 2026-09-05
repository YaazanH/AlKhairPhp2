<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AppSetting;
use App\Support\ApplicationTimezone;
use App\Services\SidebarNavigationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity as AuditActivity;

new class extends Component {
    use AuthorizesPermissions;
    use WithPagination;

    public string $search = '';
    public string $eventFilter = 'all';
    public string $moduleFilter = 'all';
    public string $fromDate = '';
    public string $toDate = '';
    public array $selectedActivityIds = [];
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorizePermission('data-audit.view');
    }

    public function render(): mixed
    {
        return parent::render()->title(__('data_governance.audit.title'));
    }

    public function with(): array
    {
        $query = $this->filteredActivitiesQuery();
        $bundleDescriptors = $this->numberActivityBundlesBySubjectType(
            $this->consecutiveActivityBundles(
                (clone $query)->get(['id', 'subject_type', 'event']),
            ),
        );
        $currentPage = max(1, $this->getPage());
        $pageDescriptors = array_slice($bundleDescriptors, ($currentPage - 1) * $this->perPage, $this->perPage);
        $pageActivityIds = collect($pageDescriptors)->flatMap(fn (array $bundle): array => $bundle['ids'])->all();
        $pageActivities = AuditActivity::query()
            ->inLog('data-audit')
            ->with(['causer', 'subject'])
            ->whereIn('id', $pageActivityIds)
            ->get()
            ->keyBy('id');
        $bundles = collect($pageDescriptors)
            ->map(function (array $descriptor) use ($pageActivities): array {
                $activities = collect($descriptor['ids'])
                    ->map(fn (int $id): ?AuditActivity => $pageActivities->get($id))
                    ->filter()
                    ->values();

                return [
                    ...$descriptor,
                    'ids' => $activities->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    'activities' => $activities,
                    'latest' => $activities->first(),
                ];
            })
            ->filter(fn (array $bundle): bool => $bundle['latest'] instanceof AuditActivity)
            ->values();
        $activities = new LengthAwarePaginator(
            $bundles,
            count($bundleDescriptors),
            $this->perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => 'page',
            ],
        );
        $selectedActivities = $this->selectedActivityIds === []
            ? collect()
            : AuditActivity::query()
                ->inLog('data-audit')
                ->with(['causer', 'subject'])
                ->whereIn('id', $this->selectedActivityIds)
                ->latest('id')
                ->get();

        return [
            'activities' => $activities,
            'selectedActivities' => $selectedActivities,
            'selectedActivity' => $selectedActivities->first(),
            'modules' => AuditActivity::query()->inLog('data-audit')->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ];
    }

    protected function filteredActivitiesQuery(): Builder
    {
        return AuditActivity::query()
            ->inLog('data-audit')
            ->when(filled($this->search), function (Builder $query): void {
                $query->where(function (Builder $builder): void {
                    $builder->where('description', 'like', '%'.$this->search.'%')
                        ->orWhere('properties', 'like', '%'.$this->search.'%')
                        ->orWhereHas('causer', fn (Builder $causer) => $causer
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('email', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->eventFilter !== 'all', fn (Builder $query) => $query->where('event', $this->eventFilter))
            ->when($this->moduleFilter !== 'all', fn (Builder $query) => $query->where('subject_type', $this->moduleFilter))
            ->when(filled($this->fromDate), fn (Builder $query) => $query->whereDate('created_at', '>=', $this->fromDate))
            ->when(filled($this->toDate), fn (Builder $query) => $query->whereDate('created_at', '<=', $this->toDate))
            ->latest('id');
    }

    public function consecutiveActivityBundles(iterable $activities): array
    {
        $bundles = [];

        foreach ($activities as $activity) {
            $id = (int) (is_array($activity) ? ($activity['id'] ?? 0) : $activity->id);
            $subjectType = (string) (is_array($activity) ? ($activity['subject_type'] ?? '') : $activity->subject_type);
            $event = (string) (is_array($activity) ? ($activity['event'] ?? '') : $activity->event);
            $lastIndex = count($bundles) - 1;

            if (
                $lastIndex >= 0
                && $bundles[$lastIndex]['subject_type'] === $subjectType
                && $bundles[$lastIndex]['event'] === $event
            ) {
                $bundles[$lastIndex]['ids'][] = $id;
                $bundles[$lastIndex]['key'] .= '-'.$id;
                continue;
            }

            $bundles[] = [
                'key' => (string) $id,
                'ids' => [$id],
                'subject_type' => $subjectType,
                'event' => $event,
            ];
        }

        return $bundles;
    }

    public function numberActivityBundlesBySubjectType(array $bundles): array
    {
        $remainingBySubjectType = [];

        foreach ($bundles as $bundle) {
            $subjectType = (string) ($bundle['subject_type'] ?? '');
            $remainingBySubjectType[$subjectType] = ($remainingBySubjectType[$subjectType] ?? 0) + 1;
        }

        foreach ($bundles as &$bundle) {
            $subjectType = (string) ($bundle['subject_type'] ?? '');
            $bundle['record_number'] = $remainingBySubjectType[$subjectType];
            $remainingBySubjectType[$subjectType]--;
        }
        unset($bundle);

        return $bundles;
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedEventFilter(): void { $this->resetPage(); }
    public function updatedModuleFilter(): void { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->eventFilter = 'all';
        $this->moduleFilter = 'all';
        $this->fromDate = '';
        $this->toDate = '';
        $this->resetPage();
    }

    public function viewActivity(int $activityId): void
    {
        $this->viewActivityBundle([$activityId]);
    }

    public function viewActivityBundle(array $activityIds): void
    {
        $activityIds = collect($activityIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $validIds = AuditActivity::query()
            ->inLog('data-audit')
            ->whereIn('id', $activityIds)
            ->latest('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        abort_if(count($validIds) !== $activityIds->count(), 404);

        $this->selectedActivityIds = $validIds;
    }

    public function closeDetails(): void
    {
        $this->selectedActivityIds = [];
    }

    public function moduleLabel(?string $subjectType): string
    {
        $name = class_basename((string) $subjectType);
        $key = 'data_governance.audit.modules.'.$name;

        return __($key) === $key ? Str::headline($name) : __($key);
    }

    public function eventLabel(?string $event): string
    {
        $key = 'data_governance.audit.events.'.($event ?: 'updated');

        return __($key);
    }

    public function bundleActorLabel(iterable $activities): string
    {
        return collect($activities)
            ->map(fn (AuditActivity $activity): string => $activity->causer?->name ?? __('data_governance.audit.system'))
            ->unique()
            ->implode(app()->isLocale('ar') ? '، ' : ', ');
    }

    public function bundleEventLabel(iterable $activities): string
    {
        $event = collect($activities)->pluck('event')->filter()->first();

        return $this->eventLabel(filled($event) ? (string) $event : 'updated');
    }

    public function bundleEventTone(iterable $activities): string
    {
        $events = collect($activities)->pluck('event')->filter()->unique()->values();

        if ($events->count() === 1 && $events->first() === 'created') return 'created';
        if ($events->count() === 1 && $events->first() === 'deleted') return 'deleted';

        return 'changed';
    }

    public function bundleHasOnlyEvent(iterable $activities, string $event): bool
    {
        $events = collect($activities)->pluck('event')->filter();

        return $events->isNotEmpty() && $events->every(fn (mixed $value): bool => $value === $event);
    }

    public function bundleRecordLabel(string $subjectType, int $bundleNumber): string
    {
        return class_basename($subjectType).' #'.$bundleNumber;
    }

    public function bundleIpAddress(iterable $activities): string
    {
        $addresses = collect($activities)
            ->map(fn (AuditActivity $activity): string => (string) $activity->getProperty('ip_address', ''))
            ->filter()
            ->unique()
            ->values();

        return $addresses->isEmpty() ? '—' : $addresses->implode(app()->isLocale('ar') ? '، ' : ', ');
    }

    public function bundleChangeRows(iterable $activities): array
    {
        $combinedRows = [];
        $recordLabels = [];

        collect($activities)
            ->filter(fn (mixed $activity): bool => $activity instanceof AuditActivity)
            ->sortBy('id')
            ->each(function (AuditActivity $activity) use (&$combinedRows, &$recordLabels): void {
                foreach ($this->activityChangeGroups($activity) as $group) {
                    $recordKey = $group['key'];
                    $recordLabels[$recordKey] ??= $this->bundleEntryLabel($activity, $group);

                    foreach ($group['rows'] as $row) {
                        $row = $this->localizeBundleChangeRow($activity, $group, $row);
                        $rowKey = $recordKey.'|'.$row['key'];

                        if (! array_key_exists($rowKey, $combinedRows)) {
                            $combinedRows[$rowKey] = [
                                ...$row,
                                'key' => sha1($rowKey),
                                'record_key' => $recordKey,
                            ];
                            continue;
                        }

                        $combinedRows[$rowKey]['after'] = $row['after'];
                    }
                }
            });

        $showRecordContext = count($recordLabels) > 1;

        return collect($combinedRows)
            ->map(function (array $row) use ($recordLabels, $showRecordContext): array {
                $recordLabel = $recordLabels[$row['record_key']] ?? null;

                if (filled($recordLabel) && ($row['is_direct_value'] ?? false)) {
                    $row['field'] = $recordLabel;
                } elseif ($showRecordContext && filled($recordLabel)) {
                    $row['field'] = $recordLabel.' · '.$row['field'];
                }

                unset($row['record_key'], $row['is_direct_value'], $row['source_field']);

                return $row;
            })
            ->values()
            ->all();
    }

    protected function bundleEntryLabel(AuditActivity $activity, array $group): ?string
    {
        $subjectType = (string) ($group['subject_type'] ?? $activity->subject_type);
        $subjectId = (int) ($group['subject_id'] ?? 0);
        $label = trim((string) ($group['label'] ?? ''));
        $fallbackPattern = '/^'.preg_quote(class_basename($subjectType), '/').'\s+#\d+$/u';
        $subject = $this->bundleSubject($activity, $subjectType, $subjectId);

        if ($subject instanceof AppSetting) {
            return $this->appSettingLabel($subject);
        }

        if ($subject instanceof Model) {
            if (filled($subject->getAttribute('first_name')) || filled($subject->getAttribute('last_name'))) {
                return trim($subject->getAttribute('first_name').' '.$subject->getAttribute('last_name'));
            }

            foreach (['name', 'title', 'filename', 'invoice_number', 'transaction_number', 'parent_number', 'student_number', 'number'] as $field) {
                if (filled($subject->getAttribute($field))) return (string) $subject->getAttribute($field);
            }
        }

        return $label !== '' && ! is_numeric($label) && ! preg_match($fallbackPattern, $label)
            ? $label
            : null;
    }

    protected function bundleSubject(AuditActivity $activity, string $subjectType, int $subjectId): ?Model
    {
        return $activity->subject instanceof Model
            && $activity->subject->getMorphClass() === $subjectType
            && (int) $activity->subject->getKey() === $subjectId
                ? $activity->subject
                : (class_exists($subjectType) && is_subclass_of($subjectType, Model::class) && $subjectId > 0
                    ? $subjectType::query()->find($subjectId)
                    : null);
    }

    protected function appSettingLabel(AppSetting $setting): string
    {
        foreach ([
            'settings.organization.fields.'.$setting->key,
            'backups.settings.'.$setting->key,
            'data_governance.audit.field_labels.'.$setting->key,
        ] as $translationKey) {
            $translated = __($translationKey);
            if ($translated !== $translationKey) return $translated;
        }

        return Str::headline((string) $setting->key);
    }

    protected function localizeBundleChangeRow(AuditActivity $activity, array $group, array $row): array
    {
        $subjectType = (string) ($group['subject_type'] ?? $activity->subject_type);
        $subjectId = (int) ($group['subject_id'] ?? 0);
        $setting = $this->bundleSubject($activity, $subjectType, $subjectId);

        if (! $setting instanceof AppSetting) return $row;

        foreach (['before', 'after'] as $side) {
            if (($row[$side]['state'] ?? null) !== 'value') continue;

            $value = (string) ($row[$side]['value'] ?? '');
            $translated = $this->localizedAppSettingValue($setting, (string) ($row['source_field'] ?? ''), $value);

            if ($translated !== null) {
                $row[$side]['value'] = $translated;
                $row[$side]['direction'] = 'auto';
            }
        }

        return $row;
    }

    protected function localizedAppSettingValue(AppSetting $setting, string $field, string $value): ?string
    {
        if ($field === 'key') return $this->appSettingLabel($setting);

        if ($field === 'value' && $setting->type === 'boolean') {
            return __(filter_var($value, FILTER_VALIDATE_BOOL)
                ? 'data_governance.audit.yes'
                : 'data_governance.audit.no');
        }

        if ($field === 'value' && $setting->key === 'school_timezone') {
            $option = collect(app(ApplicationTimezone::class)->options())->firstWhere('value', $value);

            return is_array($option) ? (string) $option['label'] : null;
        }

        $translationKeys = $field === 'value' ? match ($setting->key) {
            'frequency' => ['backups.settings.frequencies.'.$value],
            'weekday' => ['backups.settings.weekdays.'.$value],
            default => [],
        } : [];
        $translationKeys[] = 'data_governance.audit.field_values.'.$field.'.'.$value;
        $translationKeys[] = 'data_governance.audit.field_values.'.$setting->key.'.'.$value;

        foreach ($translationKeys as $translationKey) {
            $translated = __($translationKey);
            if ($translated !== $translationKey) return $translated;
        }

        return null;
    }

    public function activityEntries(AuditActivity $activity): array
    {
        $entries = $activity->getProperty('entries', []);

        if (! is_array($entries) || $entries === []) {
            $entries = [[
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'subject_label' => $activity->getProperty('subject_label', $this->moduleLabel($activity->subject_type)),
                'before' => $activity->getProperty('before', []),
                'after' => $activity->getProperty('after', []),
            ]];
        }

        return collect($entries)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): array => [
                'subject_type' => $entry['subject_type'] ?? $activity->subject_type,
                'subject_id' => $entry['subject_id'] ?? null,
                'subject_label' => filled($entry['subject_label'] ?? null)
                    ? (string) $entry['subject_label']
                    : $this->moduleLabel($entry['subject_type'] ?? $activity->subject_type),
                'before' => is_array($entry['before'] ?? null) ? $entry['before'] : [],
                'after' => is_array($entry['after'] ?? null) ? $entry['after'] : [],
            ])
            ->values()
            ->all();
    }

    public function activityRecordLabel(AuditActivity $activity): string
    {
        $entries = $this->activityEntries($activity);

        return count($entries) === 1
            ? $entries[0]['subject_label']
            : __('data_governance.audit.affected_records_count', ['count' => count($entries)]);
    }

    public function activityChangeGroups(AuditActivity $activity): array
    {
        return collect($this->activityEntries($activity))
            ->map(fn (array $entry): array => [
                'key' => sha1(($entry['subject_type'] ?? '').'|'.($entry['subject_id'] ?? '')),
                'subject_type' => $entry['subject_type'] ?? $activity->subject_type,
                'subject_id' => $entry['subject_id'] ?? null,
                'label' => $entry['subject_label'],
                'rows' => $this->changeRowsForValues($activity, $entry['before'], $entry['after']),
            ])
            ->filter(fn (array $group): bool => $activity->event === 'deleted' || $group['rows'] !== [])
            ->values()
            ->all();
    }

    public function changeRows(AuditActivity $activity): array
    {
        $before = $activity->getProperty('before', []);
        $after = $activity->getProperty('after', []);
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];

        return $this->changeRowsForValues($activity, $before, $after);
    }

    protected function changeRowsForValues(AuditActivity $activity, array $before, array $after): array
    {
        $fields = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $groupLabels = $this->navigationGroupLabels();
        $rows = [];

        if ($activity->event === 'deleted') {
            $fields = array_values(array_filter($fields, fn (string $field): bool => $field !== 'deleted_at'));
        }

        foreach ($fields as $field) {
            $hasBefore = array_key_exists($field, $before);
            $hasAfter = array_key_exists($field, $after);
            $beforeValue = $hasBefore ? $before[$field] : null;
            $afterValue = $hasAfter ? $after[$field] : null;
            $beforeStructured = $hasBefore ? $this->structuredAuditState($beforeValue) : null;
            $afterStructured = $hasAfter ? $this->structuredAuditState($afterValue) : null;

            if ($beforeStructured !== null || $afterStructured !== null) {
                $beforeStructured ??= ['values' => [], 'complete' => true];
                $afterStructured ??= ['values' => [], 'complete' => true];
                $beforeFlat = $this->flattenAuditValues($beforeStructured['values']);
                $afterFlat = $this->flattenAuditValues($afterStructured['values']);
                $paths = array_values(array_unique([...array_keys($beforeFlat), ...array_keys($afterFlat)]));

                foreach ($paths as $path) {
                    $hasNestedBefore = array_key_exists($path, $beforeFlat);
                    $hasNestedAfter = array_key_exists($path, $afterFlat);

                    if ((! $hasNestedBefore && ! $beforeStructured['complete']) || (! $hasNestedAfter && ! $afterStructured['complete'])) {
                        continue;
                    }

                    $nestedBefore = $hasNestedBefore ? $beforeFlat[$path] : null;
                    $nestedAfter = $hasNestedAfter ? $afterFlat[$path] : null;

                    if ($hasNestedBefore === $hasNestedAfter && $nestedBefore === $nestedAfter) {
                        continue;
                    }

                    $rows[] = $this->makeChangeRow(
                        $activity,
                        $field,
                        $path,
                        $nestedBefore,
                        $nestedAfter,
                        $hasNestedBefore,
                        $hasNestedAfter,
                        $beforeStructured['values'],
                        $afterStructured['values'],
                        $groupLabels,
                    );
                }

                continue;
            }

            if ($hasBefore === $hasAfter && $beforeValue === $afterValue) {
                continue;
            }

            $rows[] = $this->makeChangeRow(
                $activity,
                $field,
                '',
                $beforeValue,
                $afterValue,
                $hasBefore,
                $hasAfter,
                [],
                [],
                $groupLabels,
            );
        }

        return $rows;
    }

    public function auditFieldLabel(string $field): string
    {
        foreach (['data_governance.audit.field_labels.'.$field, 'data_governance.quality.record_fields.'.$field] as $key) {
            $label = __($key);

            if ($label !== $key) {
                return $label;
            }
        }

        return Str::headline($field);
    }

    public function deletionTimestamp(AuditActivity $activity): string
    {
        $timestamp = Arr::get($activity->getProperty('before', []), 'deleted_at');

        return $this->formatTimestamp(filled($timestamp) ? $timestamp : $activity->created_at);
    }

    public function formatTimestamp(mixed $timestamp): string
    {
        if (blank($timestamp)) return '—';

        try {
            return Carbon::parse($timestamp)->format('d-m-Y H:i');
        } catch (\Throwable) {
            return (string) $timestamp;
        }
    }

    protected function makeChangeRow(
        AuditActivity $activity,
        string $field,
        string $path,
        mixed $before,
        mixed $after,
        bool $hasBefore,
        bool $hasAfter,
        array $beforeStructure,
        array $afterStructure,
        array $groupLabels,
    ): array {
        $segments = $path === '' ? [] : explode('.', $path);
        $scope = null;
        $fieldLabel = $this->auditFieldLabel($field);

        if ($segments !== []) {
            if ($field === 'manifest_summary') {
                $fieldLabel = collect($segments)
                    ->map(fn (string $segment): string => $this->nestedFieldLabel($activity, $segment))
                    ->implode(' · ');
            } else {
                $scopeKey = array_shift($segments);
                $scope = $this->auditScopeLabel($scopeKey, $beforeStructure, $afterStructure);
                $fieldLabel = $segments === []
                    ? $this->auditFieldLabel($field)
                    : collect($segments)->map(fn (string $segment): string => $this->nestedFieldLabel($activity, $segment))->implode(' · ');
            }
        }

        $valuePath = $path === '' ? $field : $path;
        $beforeDisplay = $this->auditDisplayValue($before, $hasBefore, $valuePath, $groupLabels);
        $afterDisplay = $activity->event === 'deleted' && ! $hasAfter
            ? ['value' => __('data_governance.audit.record_deleted'), 'direction' => 'auto', 'state' => 'deleted']
            : $this->auditDisplayValue($after, $hasAfter, $valuePath, $groupLabels);

        return [
            'key' => sha1($field.'|'.$path),
            'source_field' => $field,
            'is_direct_value' => $field === 'value' && $path === '',
            'scope' => $scope,
            'field' => $fieldLabel,
            'before' => $beforeDisplay,
            'after' => $afterDisplay,
        ];
    }

    protected function structuredAuditState(mixed $value): ?array
    {
        if (is_array($value)) {
            return ['values' => $value, 'complete' => true];
        }

        if (! is_string($value) || ! Str::startsWith(trim($value), ['{', '['])) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return ['values' => $decoded, 'complete' => true];
        }

        return [
            'values' => Str::startsWith(trim($value), '{') ? $this->recoverTruncatedJsonObject($value) : [],
            'complete' => false,
        ];
    }

    protected function recoverTruncatedJsonObject(string $json): array
    {
        $json = trim($json);
        $length = strlen($json);
        $offset = 1;
        $result = [];

        while ($offset < $length) {
            while ($offset < $length && (ctype_space($json[$offset]) || $json[$offset] === ',')) $offset++;
            if ($offset >= $length || $json[$offset] !== '"') break;

            $keyEnd = $this->jsonTokenEnd($json, $offset);
            if ($keyEnd === null) break;

            $key = json_decode(substr($json, $offset, $keyEnd - $offset), true);
            $offset = $keyEnd;
            while ($offset < $length && ctype_space($json[$offset])) $offset++;
            if ($offset >= $length || $json[$offset] !== ':') break;

            $offset++;
            while ($offset < $length && ctype_space($json[$offset])) $offset++;
            $valueEnd = $this->jsonTokenEnd($json, $offset);
            if ($valueEnd === null) break;

            $decoded = json_decode(substr($json, $offset, $valueEnd - $offset), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_string($key)) break;

            $result[$key] = $decoded;
            $offset = $valueEnd;
        }

        return $result;
    }

    protected function jsonTokenEnd(string $json, int $offset): ?int
    {
        $length = strlen($json);
        if ($offset >= $length) return null;

        $opening = $json[$offset];

        if ($opening === '"') {
            $escaped = false;
            for ($index = $offset + 1; $index < $length; $index++) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($json[$index] === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($json[$index] === '"') return $index + 1;
            }

            return null;
        }

        if (in_array($opening, ['{', '['], true)) {
            $stack = [$opening];
            $inString = false;
            $escaped = false;

            for ($index = $offset + 1; $index < $length; $index++) {
                $character = $json[$index];
                if ($inString) {
                    if ($escaped) $escaped = false;
                    elseif ($character === '\\') $escaped = true;
                    elseif ($character === '"') $inString = false;
                    continue;
                }
                if ($character === '"') {
                    $inString = true;
                    continue;
                }
                if (in_array($character, ['{', '['], true)) $stack[] = $character;
                if (in_array($character, ['}', ']'], true)) array_pop($stack);
                if ($stack === []) return $index + 1;
            }

            return null;
        }

        for ($index = $offset; $index < $length; $index++) {
            if (in_array($json[$index], [',', '}'], true)) {
                return $index;
            }
        }

        return null;
    }

    protected function flattenAuditValues(array $values, string $prefix = ''): array
    {
        if ($values === []) {
            return $prefix === '' ? [] : [$prefix => []];
        }

        $flattened = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $flattened = array_merge($flattened, is_array($value) ? $this->flattenAuditValues($value, $path) : [$path => $value]);
        }

        return $flattened;
    }

    protected function auditScopeLabel(string $scope, array $before, array $after): string
    {
        foreach ([$after, $before] as $values) {
            $title = is_array($values[$scope] ?? null) ? trim((string) ($values[$scope]['title'] ?? '')) : '';
            if ($title !== '') return $title;
        }

        $translationKey = 'ui.nav.'.$scope;
        $translated = __($translationKey);

        if ($translated !== $translationKey) return $translated;
        if (is_numeric($scope)) return __('data_governance.audit.item_number', ['number' => ((int) $scope) + 1]);

        return Str::headline($scope);
    }

    protected function nestedFieldLabel(AuditActivity $activity, string $field): string
    {
        if ($activity->subject instanceof AppSetting && $activity->subject->group === 'sidebar_navigation') {
            $translationKey = match ([$activity->subject->key, $field]) {
                ['items', 'group_key'] => 'settings.sidebar_navigation.fields.group',
                ['items', 'sort_order'] => 'settings.sidebar_navigation.fields.item_order',
                ['groups', 'sort_order'] => 'settings.sidebar_navigation.fields.group_order',
                default => null,
            };

            if ($translationKey) return __($translationKey);
        }

        $translationKey = 'data_governance.audit.structure_fields.'.$field;
        $translated = __($translationKey);

        return $translated === $translationKey ? $this->auditFieldLabel($field) : $translated;
    }

    protected function auditDisplayValue(mixed $value, bool $exists, string $path, array $groupLabels): array
    {
        if (! $exists || $value === null || $value === '') {
            return ['value' => __('data_governance.audit.not_set'), 'direction' => 'auto', 'state' => 'missing'];
        }

        $field = Str::afterLast($path, '.');

        if (in_array($field, ['is_active', 'is_custom', 'includes_files', 'encrypted'], true) || is_bool($value)) {
            $enabled = filter_var($value, FILTER_VALIDATE_BOOL);

            return ['value' => __($enabled ? 'data_governance.audit.yes' : 'data_governance.audit.no'), 'direction' => 'auto', 'state' => 'value'];
        }

        if ($field === 'group_key') {
            return ['value' => $groupLabels[(string) $value] ?? Str::headline((string) $value), 'direction' => 'auto', 'state' => 'value'];
        }

        if (is_array($value)) {
            return ['value' => __('data_governance.audit.empty_value'), 'direction' => 'auto', 'state' => 'missing'];
        }

        if (is_string($value)) {
            $translationKey = 'data_governance.audit.field_values.'.$field.'.'.$value;
            $translated = __($translationKey);

            if ($translated !== $translationKey) {
                return ['value' => $translated, 'direction' => 'auto', 'state' => 'value'];
            }
        }

        if (is_numeric($value)) {
            $formatted = is_float($value) ? rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.') : number_format((int) $value);

            return ['value' => $formatted, 'direction' => 'ltr', 'state' => 'value'];
        }

        if (preg_match('/(?:_at|_date|_on)$/', $field) && is_string($value)) {
            try {
                $format = str_contains($value, ':') ? 'd-m-Y H:i' : 'd-m-Y';

                return ['value' => Carbon::parse($value)->format($format), 'direction' => 'ltr', 'state' => 'value'];
            } catch (\Throwable) {
                // Fall through and keep the original value visible.
            }
        }

        if ($field === 'status' && is_string($value)) {
            foreach (['crud.common.status_options.'.$value, 'data_governance.quality.'.$value] as $translationKey) {
                $translated = __($translationKey);
                if ($translated !== $translationKey) return ['value' => $translated, 'direction' => 'auto', 'state' => 'value'];
            }
        }

        return ['value' => (string) $value, 'direction' => 'auto', 'state' => 'value'];
    }

    protected function navigationGroupLabels(): array
    {
        $service = app(SidebarNavigationService::class);
        $labels = collect($service->defaultGroups())->mapWithKeys(fn (array $group, string $key): array => [
            $key => __($group['title_key']),
        ])->all();

        foreach ($service->settings()['groups'] as $key => $group) {
            if (filled($group['title'] ?? null)) $labels[$key] = (string) $group['title'];
        }

        return $labels;
    }
}; ?>

<div class="page-stack" data-data-audit-page>
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('data_governance.audit.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('data_governance.audit.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('data_governance.audit.subtitle') }}</p>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('data_governance.audit.table_title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field"><label class="sr-only" for="data-audit-search">{{ __('crud.common.filters.search') }}</label><input id="data-audit-search" wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('data_governance.audit.search_placeholder') }}"></div>
                <div class="admin-filter-field"><label class="sr-only" for="data-audit-event">{{ __('data_governance.audit.all_events') }}</label><select id="data-audit-event" wire:model.live="eventFilter"><option value="all">{{ __('data_governance.audit.all_events') }}</option>@foreach (['created','updated','deleted','restored'] as $event)<option value="{{ $event }}">{{ __('data_governance.audit.events.'.$event) }}</option>@endforeach</select></div>
                <div class="admin-filter-field"><label class="sr-only" for="data-audit-module">{{ __('data_governance.audit.all_modules') }}</label><select id="data-audit-module" wire:model.live="moduleFilter"><option value="all">{{ __('data_governance.audit.all_modules') }}</option>@foreach ($modules as $module)<option value="{{ $module }}">{{ $this->moduleLabel($module) }}</option>@endforeach</select></div>
                <div class="admin-filter-field"><label class="sr-only" for="audit-from-date">{{ __('data_governance.audit.from_date') }}</label><input id="audit-from-date" wire:model.live="fromDate" type="date" title="{{ __('data_governance.audit.from_date') }}"></div>
                <div class="admin-filter-field"><label class="sr-only" for="audit-to-date">{{ __('data_governance.audit.to_date') }}</label><input id="audit-to-date" wire:model.live="toDate" type="date" title="{{ __('data_governance.audit.to_date') }}"></div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="data-audit-log-table text-sm" data-data-audit-table>
                <colgroup>
                    <col class="data-audit-log-table__number-column">
                    <col data-data-audit-content-column>
                    <col data-data-audit-content-column>
                    <col data-data-audit-content-column>
                    <col data-data-audit-content-column>
                    <col data-data-audit-content-column>
                    <col class="data-audit-log-table__details-column">
                </colgroup>
                <thead><tr><th class="w-16 px-5 py-4 text-center">#</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.time') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.actor') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.module') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.record') }}</th><th class="px-5 py-4 text-center">{{ __('data_governance.audit.event') }}</th><th class="admin-actions-column px-5 py-4 text-center">{{ __('data_governance.audit.details') }}</th></tr></thead>
                <tbody class="divide-y divide-white/10">
                @forelse ($activities as $bundle)
                    @php($activity = $bundle['latest'])
                    @php($eventTone = $this->bundleEventTone($bundle['activities']))
                    @php($bundleNumber = $activities->total() - (($activities->firstItem() - 1) + $loop->index))
                    <tr wire:key="data-audit-bundle-{{ $bundle['key'] }}" data-data-audit-bundle-row>
                        <td class="px-5 py-4 text-center tabular-nums" data-data-audit-sequence>{{ $bundleNumber }}</td>
                        <td class="px-5 py-4 whitespace-nowrap text-neutral-300"><span dir="ltr">{{ $this->formatTimestamp($activity->created_at) }}</span></td>
                        <td class="px-5 py-4"><div class="font-semibold text-white">{{ $this->bundleActorLabel($bundle['activities']) }}</div></td>
                        <td class="px-5 py-4 text-neutral-300">{{ $this->moduleLabel($bundle['subject_type']) }}</td>
                        <td class="px-5 py-4"><div class="font-medium text-neutral-100" dir="ltr">{{ $this->bundleRecordLabel($bundle['subject_type'], $bundle['record_number']) }}</div></td>
                        <td class="px-5 py-4 text-center"><span @class([
                            'text-sm font-medium',
                            'text-emerald-200' => $eventTone === 'created',
                            'text-amber-100' => $eventTone === 'changed',
                            'text-red-200' => $eventTone === 'deleted',
                        ]) data-data-audit-event-text="{{ $eventTone }}">{{ $this->bundleEventLabel($bundle['activities']) }}</span></td>
                        <td class="px-5 py-4 text-center"><button type="button" wire:click="viewActivityBundle(@js($bundle['ids']))" class="admin-icon-button" title="{{ __('data_governance.audit.view') }}" aria-label="{{ __('data_governance.audit.view') }}" data-data-audit-view-action><x-admin-action-icon name="search" /></button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-neutral-400">{{ __('data_governance.audit.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($activities->hasPages()) <div class="px-5 py-4">{{ $activities->links() }}</div> @endif
    </section>

    <x-admin.modal :show="(bool) $selectedActivity" :title="__('data_governance.audit.details_title')" close-method="closeDetails" max-width="4xl">
        @if ($selectedActivity)
            <div class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.actor') }}</div><div class="mt-1 text-sm text-white">{{ $this->bundleActorLabel($selectedActivities) }}</div></div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.event') }}</div><div class="mt-1 text-sm text-white">{{ $this->bundleEventLabel($selectedActivities) }}</div></div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.time') }}</div><div @class(['mt-1 text-sm text-white', 'text-right' => app()->isLocale('ar')]) dir="ltr" data-data-audit-time-metric>{{ $this->formatTimestamp($selectedActivity->created_at) }}</div></div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.ip_address') }}</div><div @class(['mt-1 text-sm text-white', 'text-right' => app()->isLocale('ar')]) dir="ltr" data-data-audit-ip-metric>{{ $this->bundleIpAddress($selectedActivities) }}</div></div>
                </div>
                @php($changeRows = $this->bundleChangeRows($selectedActivities))
                @php($changeCount = count($changeRows))
                @php($showCreatedState = $this->bundleHasOnlyEvent($selectedActivities, 'created'))
                @php($showDeletedState = $this->bundleHasOnlyEvent($selectedActivities, 'deleted'))
                <section class="space-y-3" data-data-audit-change-list>
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-white">{{ __('data_governance.audit.changes_title') }}</h3>
                        @if ($changeCount > 0)
                            <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-xs tabular-nums text-neutral-300" dir="ltr">{{ number_format($changeCount) }}</span>
                        @endif
                    </div>

                    @if ($changeRows !== [])
                        <section class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.025]" data-data-audit-change-bundle>
                            <div class="grid min-w-0 gap-4 p-3 lg:grid-cols-2" data-data-audit-comparison>
                                @foreach (['before', 'after'] as $side)
                                    <div class="surface-table settings-record-table flex h-full min-w-0 flex-col border border-white/10" data-data-audit-{{ $side }}-table>
                                        <div @class([
                                            'flex items-center justify-between gap-3 border-b px-4 py-3',
                                            'border-white/10 bg-white/[0.025]' => $side === 'before',
                                            'border-emerald-300/15 bg-emerald-400/[0.07]' => $side === 'after',
                                        ])>
                                            <h4 @class([
                                                'font-semibold',
                                                'text-neutral-200' => $side === 'before',
                                                'data-audit-after-accent' => $side === 'after',
                                            ])>{{ __('data_governance.audit.'.$side) }}</h4>
                                            <span @class([
                                                'h-2.5 w-2.5 rounded-full',
                                                'bg-neutral-500' => $side === 'before',
                                                'bg-emerald-300' => $side === 'after',
                                            ]) aria-hidden="true"></span>
                                        </div>

                                        @if ($side === 'before' && $showCreatedState)
                                            <div class="flex min-h-64 flex-1 flex-col items-center justify-center px-6 py-10 text-center" data-data-audit-no-record-state>
                                                <x-admin-action-icon name="null" class="h-10 w-10 text-red-200" data-data-audit-no-record-icon />
                                                <div class="mt-4 font-semibold text-red-200">{{ __('data_governance.audit.no_record') }}</div>
                                            </div>
                                        @elseif ($side === 'after' && $showDeletedState)
                                            <div class="flex min-h-64 flex-1 flex-col items-center justify-center px-6 py-10 text-center" data-data-audit-deleted-state>
                                                <x-admin-action-icon name="delete" class="h-10 w-10 text-red-200" data-data-audit-deleted-icon />
                                                <div class="mt-4 font-semibold text-red-200">{{ __('data_governance.audit.record_deleted') }}</div>
                                                <div class="mt-2 text-sm text-neutral-400" dir="ltr">{{ $this->deletionTimestamp($selectedActivity) }}</div>
                                            </div>
                                        @else
                                            <div class="overflow-x-auto">
                                                <table class="table-fixed text-sm">
                                                    <col class="w-[42%]">
                                                    <col class="w-[58%]">
                                                    <tbody>
                                                        @foreach ($changeRows as $row)
                                                            <tr wire:key="data-audit-bundle-{{ $side }}-{{ $row['key'] }}" data-data-audit-comparison-row>
                                                                <td class="align-middle px-4 py-3.5">
                                                                    <div @class([
                                                                        'font-medium',
                                                                        'text-neutral-100' => $side === 'before',
                                                                        'data-audit-after-accent' => $side === 'after',
                                                                    ])>{{ $row['field'] }}</div>
                                                                </td>
                                                                <td class="align-middle px-4 py-3.5" data-data-audit-change-{{ $side }}>
                                                                    <bdi dir="{{ $row[$side]['direction'] }}" class="block break-words text-neutral-200 [overflow-wrap:anywhere]">{{ $row[$side]['value'] }}</bdi>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @else
                        <div class="rounded-2xl border border-amber-300/20 bg-amber-400/[0.07] px-4 py-4" data-data-audit-no-effective-changes>
                            <div class="font-semibold text-amber-100">{{ __('data_governance.audit.no_effective_changes_title') }}</div>
                            <p class="mt-1 text-sm leading-6 text-neutral-300">{{ __('data_governance.audit.no_effective_changes_copy') }}</p>
                        </div>
                    @endif
                </section>
                <div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="closeDetails" class="pill-link pill-link--accent">{{ __('crud.common.actions.close') }}</button></div>
            </div>
        @endif
    </x-admin.modal>
</div>
