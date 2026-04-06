<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\GradeLevel;
use App\Models\PointPolicy;
use App\Models\PointTransaction;
use App\Models\PointType;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $point_type_editing_id = null;
    public string $point_type_name = '';
    public string $point_type_code = '';
    public string $point_type_category = '';
    public string $point_type_default_points = '0';
    public bool $point_type_allow_manual_entry = true;
    public bool $point_type_allow_negative = true;
    public bool $point_type_is_active = true;

    public ?int $point_policy_editing_id = null;
    public ?int $point_policy_point_type_id = null;
    public string $point_policy_name = '';
    public string $point_policy_source_type = '';
    public string $point_policy_trigger_key = '';
    public ?int $point_policy_grade_level_id = null;
    public string $point_policy_from_value = '';
    public string $point_policy_to_value = '';
    public string $point_policy_points = '0';
    public string $point_policy_priority = '0';
    public bool $point_policy_is_active = true;

    public function mount(): void
    {
        $this->authorizePermission('settings.manage');
    }

    public function deletePointPolicy(int $pointPolicyId): void
    {
        $this->authorizePermission('settings.manage');

        $pointPolicy = PointPolicy::query()->findOrFail($pointPolicyId);

        if (PointTransaction::query()->where('policy_id', $pointPolicy->id)->exists()) {
            $this->addError('pointPolicyDelete', __('settings.points.errors.point_policy_delete_linked'));

            return;
        }

        $pointPolicy->delete();

        if ($this->point_policy_editing_id === $pointPolicyId) {
            $this->cancelPointPolicy();
        }

        session()->flash('status', __('settings.points.messages.point_policy_deleted'));
    }

    public function deletePointType(int $pointTypeId): void
    {
        $this->authorizePermission('settings.manage');

        $pointType = PointType::query()->withCount(['assessmentScoreBands', 'policies', 'transactions'])->findOrFail($pointTypeId);

        if ($pointType->policies_count > 0 || $pointType->transactions_count > 0 || $pointType->assessment_score_bands_count > 0) {
            $this->addError('pointTypeDelete', __('settings.points.errors.point_type_delete_linked'));

            return;
        }

        $pointType->delete();

        if ($this->point_type_editing_id === $pointTypeId) {
            $this->cancelPointType();
        }

        session()->flash('status', __('settings.points.messages.point_type_deleted'));
    }

    public function editPointPolicy(int $pointPolicyId): void
    {
        $this->authorizePermission('settings.manage');

        $pointPolicy = PointPolicy::query()->findOrFail($pointPolicyId);

        $this->point_policy_editing_id = $pointPolicy->id;
        $this->point_policy_point_type_id = $pointPolicy->point_type_id;
        $this->point_policy_name = $pointPolicy->name;
        $this->point_policy_source_type = $pointPolicy->source_type;
        $this->point_policy_trigger_key = $pointPolicy->trigger_key;
        $this->point_policy_grade_level_id = $pointPolicy->grade_level_id;
        $this->point_policy_from_value = $pointPolicy->from_value !== null ? number_format((float) $pointPolicy->from_value, 2, '.', '') : '';
        $this->point_policy_to_value = $pointPolicy->to_value !== null ? number_format((float) $pointPolicy->to_value, 2, '.', '') : '';
        $this->point_policy_points = (string) $pointPolicy->points;
        $this->point_policy_priority = (string) $pointPolicy->priority;
        $this->point_policy_is_active = $pointPolicy->is_active;

        $this->resetValidation();
    }

    public function editPointType(int $pointTypeId): void
    {
        $this->authorizePermission('settings.manage');

        $pointType = PointType::query()->findOrFail($pointTypeId);

        $this->point_type_editing_id = $pointType->id;
        $this->point_type_name = $pointType->name;
        $this->point_type_code = $pointType->code;
        $this->point_type_category = $pointType->category;
        $this->point_type_default_points = (string) $pointType->default_points;
        $this->point_type_allow_manual_entry = $pointType->allow_manual_entry;
        $this->point_type_allow_negative = $pointType->allow_negative;
        $this->point_type_is_active = $pointType->is_active;

        $this->resetValidation();
    }

    public function pointPolicyRules(): array
    {
        return [
            'point_policy_from_value' => ['nullable', 'numeric'],
            'point_policy_grade_level_id' => ['nullable', 'integer', Rule::exists('grade_levels', 'id')],
            'point_policy_is_active' => ['boolean'],
            'point_policy_name' => ['required', 'string', 'max:255'],
            'point_policy_point_type_id' => ['required', 'integer', Rule::exists('point_types', 'id')],
            'point_policy_points' => ['required', 'integer'],
            'point_policy_priority' => ['required', 'integer', 'min:0'],
            'point_policy_source_type' => ['required', 'string', 'max:50'],
            'point_policy_to_value' => ['nullable', 'numeric'],
            'point_policy_trigger_key' => ['required', 'string', 'max:100'],
        ];
    }

    public function pointTypeRules(): array
    {
        return [
            'point_type_allow_manual_entry' => ['boolean'],
            'point_type_allow_negative' => ['boolean'],
            'point_type_category' => ['required', 'string', 'max:50'],
            'point_type_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('point_types', 'code')->ignore($this->point_type_editing_id),
            ],
            'point_type_default_points' => ['required', 'integer'],
            'point_type_is_active' => ['boolean'],
            'point_type_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function savePointPolicy(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->pointPolicyRules());

        $fromValue = $validated['point_policy_from_value'] === '' ? null : $validated['point_policy_from_value'];
        $toValue = $validated['point_policy_to_value'] === '' ? null : $validated['point_policy_to_value'];

        if ($fromValue !== null && $toValue !== null && (float) $fromValue > (float) $toValue) {
            $this->addError('point_policy_to_value', __('settings.points.errors.range_invalid'));

            return;
        }

        PointPolicy::query()->updateOrCreate(
            ['id' => $this->point_policy_editing_id],
            [
                'from_value' => $fromValue,
                'grade_level_id' => $validated['point_policy_grade_level_id'] ?? null,
                'is_active' => $validated['point_policy_is_active'],
                'name' => $validated['point_policy_name'],
                'point_type_id' => $validated['point_policy_point_type_id'],
                'points' => (int) $validated['point_policy_points'],
                'priority' => (int) $validated['point_policy_priority'],
                'source_type' => $validated['point_policy_source_type'],
                'to_value' => $toValue,
                'trigger_key' => $validated['point_policy_trigger_key'],
            ],
        );

        session()->flash(
            'status',
            $this->point_policy_editing_id
                ? __('settings.points.messages.point_policy_updated')
                : __('settings.points.messages.point_policy_created'),
        );
        $this->cancelPointPolicy();
    }

    public function savePointType(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->pointTypeRules());

        PointType::query()->updateOrCreate(
            ['id' => $this->point_type_editing_id],
            [
                'allow_manual_entry' => $validated['point_type_allow_manual_entry'],
                'allow_negative' => $validated['point_type_allow_negative'],
                'category' => $validated['point_type_category'],
                'code' => $validated['point_type_code'],
                'default_points' => (int) $validated['point_type_default_points'],
                'is_active' => $validated['point_type_is_active'],
                'name' => $validated['point_type_name'],
            ],
        );

        session()->flash(
            'status',
            $this->point_type_editing_id
                ? __('settings.points.messages.point_type_updated')
                : __('settings.points.messages.point_type_created'),
        );
        $this->cancelPointType();
    }

    public function with(): array
    {
        return [
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'pointPolicies' => PointPolicy::query()->with(['gradeLevel', 'pointType'])->orderByDesc('priority')->orderBy('name')->get(),
            'pointTypes' => PointType::query()->withCount(['assessmentScoreBands', 'policies', 'transactions'])->orderBy('name')->get(),
            'totals' => [
                'active_policies' => PointPolicy::query()->where('is_active', true)->count(),
                'point_policies' => PointPolicy::count(),
                'point_types' => PointType::count(),
            ],
        ];
    }

    protected function cancelPointPolicy(): void
    {
        $this->point_policy_editing_id = null;
        $this->point_policy_point_type_id = null;
        $this->point_policy_name = '';
        $this->point_policy_source_type = '';
        $this->point_policy_trigger_key = '';
        $this->point_policy_grade_level_id = null;
        $this->point_policy_from_value = '';
        $this->point_policy_to_value = '';
        $this->point_policy_points = '0';
        $this->point_policy_priority = '0';
        $this->point_policy_is_active = true;
        $this->resetValidation();
    }

    protected function cancelPointType(): void
    {
        $this->point_type_editing_id = null;
        $this->point_type_name = '';
        $this->point_type_code = '';
        $this->point_type_category = '';
        $this->point_type_default_points = '0';
        $this->point_type_allow_manual_entry = true;
        $this->point_type_allow_negative = true;
        $this->point_type_is_active = true;
        $this->resetValidation();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div>
        <flux:heading size="xl">{{ __('settings.points.title') }}</flux:heading>
        <flux:subheading>{{ __('settings.points.subtitle') }}</flux:subheading>
    </div>

    <x-settings.admin-nav />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.points.stats.point_types') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['point_types']) }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.points.stats.point_policies') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['point_policies']) }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.points.stats.active_policies') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['active_policies']) }}</div></div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $point_type_editing_id ? __('settings.points.sections.point_type.edit') : __('settings.points.sections.point_type.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.points.sections.point_type.copy') }}</p></div>
                <form wire:submit="savePointType" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.name') }}</label><input wire:model="point_type_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_type_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.code') }}</label><input wire:model="point_type_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_type_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.category') }}</label><input wire:model="point_type_category" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_type_category') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.default_points') }}</label><input wire:model="point_type_default_points" type="number" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_type_default_points') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="point_type_allow_manual_entry" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.points.fields.allow_manual_entry') }}</span></label>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="point_type_allow_negative" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.points.fields.allow_negative') }}</span></label>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="point_type_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.points.fields.is_active') }}</span></label>
                    @error('pointTypeDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $point_type_editing_id ? __('settings.points.actions.update_point_type') : __('settings.points.actions.create_point_type') }}</button>@if ($point_type_editing_id)<button type="button" wire:click="cancelPointType" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $point_policy_editing_id ? __('settings.points.sections.point_policy.edit') : __('settings.points.sections.point_policy.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.points.sections.point_policy.copy') }}</p></div>
                <form wire:submit="savePointPolicy" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.point_type') }}</label>
                        <select wire:model="point_policy_point_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('settings.points.fields.point_type') }}</option>
                            @foreach ($pointTypes as $pointType)
                                <option value="{{ $pointType->id }}">{{ $pointType->name }}</option>
                            @endforeach
                        </select>
                        @error('point_policy_point_type_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.policy_name') }}</label><input wire:model="point_policy_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.source_type') }}</label><input wire:model="point_policy_source_type" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_source_type') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.trigger_key') }}</label><input wire:model="point_policy_trigger_key" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_trigger_key') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.grade_level') }}</label>
                        <select wire:model="point_policy_grade_level_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('settings.points.fields.all_grade_levels') }}</option>
                            @foreach ($gradeLevels as $gradeLevel)
                                <option value="{{ $gradeLevel->id }}">{{ $gradeLevel->name }}</option>
                            @endforeach
                        </select>
                        @error('point_policy_grade_level_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.from_value') }}</label><input wire:model="point_policy_from_value" type="number" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_from_value') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.to_value') }}</label><input wire:model="point_policy_to_value" type="number" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_to_value') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.points') }}</label><input wire:model="point_policy_points" type="number" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_points') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.points.fields.priority') }}</label><input wire:model="point_policy_priority" type="number" min="0" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('point_policy_priority') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="point_policy_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.points.fields.is_active') }}</span></label>
                    @error('pointPolicyDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $point_policy_editing_id ? __('settings.points.actions.update_policy') : __('settings.points.actions.create_policy') }}</button>@if ($point_policy_editing_id)<button type="button" wire:click="cancelPointPolicy" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>
        </section>

        <section class="space-y-6">
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">{{ __('settings.points.sections.point_type.table') }}</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.type') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.category') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.usage') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.points.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($pointTypes as $pointType)
                                <tr>
                                    <td class="px-5 py-3"><div class="font-medium">{{ $pointType->name }}</div><div class="text-xs text-neutral-500">{{ $pointType->code }}</div></td>
                                    <td class="px-5 py-3">{{ $pointType->category }}</td>
                                    <td class="px-5 py-3">{{ __('settings.points.labels.point_type_usage', ['policies' => $pointType->policies_count, 'transactions' => $pointType->transactions_count, 'bands' => $pointType->assessment_score_bands_count]) }}</td>
                                    <td class="px-5 py-3">{{ $pointType->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editPointType({{ $pointType->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deletePointType({{ $pointType->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">{{ __('settings.points.sections.point_policy.table') }}</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.policy') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.trigger') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.range') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.points') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.points.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.points.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($pointPolicies as $pointPolicy)
                                <tr>
                                    <td class="px-5 py-3"><div class="font-medium">{{ $pointPolicy->name }}</div><div class="text-xs text-neutral-500">{{ __('settings.points.labels.point_policy_meta', ['pointType' => $pointPolicy->pointType?->name ?: __('crud.common.not_available'), 'gradeLevel' => $pointPolicy->gradeLevel?->name ?: __('settings.points.fields.all_grade_levels')]) }}</div></td>
                                    <td class="px-5 py-3">{{ $pointPolicy->source_type }} / {{ $pointPolicy->trigger_key }}</td>
                                    <td class="px-5 py-3">{{ __('settings.points.labels.point_policy_range', ['from' => $pointPolicy->from_value ?? __('crud.common.not_available'), 'to' => $pointPolicy->to_value ?? __('crud.common.not_available')]) }}</td>
                                    <td class="px-5 py-3">{{ __('settings.points.labels.point_policy_points', ['points' => $pointPolicy->points, 'priority' => $pointPolicy->priority]) }}</td>
                                    <td class="px-5 py-3">{{ $pointPolicy->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editPointPolicy({{ $pointPolicy->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deletePointPolicy({{ $pointPolicy->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
