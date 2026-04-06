<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\PointType;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $editingId = null;
    public ?int $assessment_type_id = null;
    public string $name = '';
    public string $from_mark = '';
    public string $to_mark = '';
    public ?int $point_type_id = null;
    public string $points = '0';
    public bool $is_fail = false;
    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorizePermission('assessment-score-bands.view');
    }

    public function with(): array
    {
        return [
            'types' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(),
            'pointTypes' => PointType::query()->where('category', 'assessment')->where('is_active', true)->orderBy('name')->get(),
            'bands' => AssessmentScoreBand::query()->with(['assessmentType', 'pointType'])->orderBy('assessment_type_id')->orderByDesc('from_mark')->get(),
        ];
    }

    public function rules(): array
    {
        return [
            'assessment_type_id' => ['required', 'exists:assessment_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'from_mark' => ['required', 'numeric', 'min:0'],
            'to_mark' => ['required', 'numeric', 'gte:from_mark'],
            'point_type_id' => ['nullable', 'exists:point_types,id'],
            'points' => ['required', 'integer'],
            'is_fail' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->authorizePermission('assessment-score-bands.manage');

        $validated = $this->validate();

        AssessmentScoreBand::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'assessment_type_id' => $validated['assessment_type_id'],
                'name' => $validated['name'],
                'from_mark' => $validated['from_mark'],
                'to_mark' => $validated['to_mark'],
                'point_type_id' => $validated['point_type_id'] ?: null,
                'points' => $validated['points'],
                'is_fail' => $validated['is_fail'],
                'is_active' => $validated['is_active'],
            ],
        );

        session()->flash('status', $this->editingId ? __('workflow.assessments.bands.messages.updated') : __('workflow.assessments.bands.messages.created'));
        $this->cancel();
    }

    public function edit(int $bandId): void
    {
        $this->authorizePermission('assessment-score-bands.manage');

        $band = AssessmentScoreBand::query()->findOrFail($bandId);

        $this->editingId = $band->id;
        $this->assessment_type_id = $band->assessment_type_id;
        $this->name = $band->name;
        $this->from_mark = number_format((float) $band->from_mark, 2, '.', '');
        $this->to_mark = number_format((float) $band->to_mark, 2, '.', '');
        $this->point_type_id = $band->point_type_id;
        $this->points = (string) ($band->points ?? 0);
        $this->is_fail = $band->is_fail;
        $this->is_active = $band->is_active;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->assessment_type_id = null;
        $this->name = '';
        $this->from_mark = '';
        $this->to_mark = '';
        $this->point_type_id = null;
        $this->points = '0';
        $this->is_fail = false;
        $this->is_active = true;

        $this->resetValidation();
    }

    public function delete(int $bandId): void
    {
        $this->authorizePermission('assessment-score-bands.manage');

        $band = AssessmentScoreBand::query()->findOrFail($bandId);
        $band->delete();

        if ($this->editingId === $bandId) {
            $this->cancel();
        }

        session()->flash('status', __('workflow.assessments.bands.messages.deleted'));
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div>
        <a href="{{ route('assessments.index') }}" wire:navigate class="text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:hover:text-white">{{ __('workflow.common.back_to_assessments') }}</a>
        <flux:heading size="xl" class="mt-2">{{ __('workflow.assessments.bands.title') }}</flux:heading>
        <flux:subheading>{{ __('workflow.assessments.bands.subtitle') }}</flux:subheading>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">
        <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            @if (auth()->user()->can('assessment-score-bands.manage'))
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ $editingId ? __('workflow.assessments.bands.form.edit_title') : __('workflow.assessments.bands.form.create_title') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('workflow.assessments.bands.form.help') }}</p>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.assessment_type') }}</label>
                        <select wire:model="assessment_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('workflow.assessments.bands.form.select_type') }}</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('assessment_type_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.band_name') }}</label>
                        <input wire:model="name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.from_mark') }}</label>
                            <input wire:model="from_mark" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('from_mark') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.to_mark') }}</label>
                            <input wire:model="to_mark" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('to_mark') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.point_type') }}</label>
                        <select wire:model="point_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('workflow.assessments.bands.form.no_point_type') }}</option>
                            @foreach ($pointTypes as $pointType)
                                <option value="{{ $pointType->id }}">{{ $pointType->name }}</option>
                            @endforeach
                        </select>
                        @error('point_type_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.points') }}</label>
                        <input wire:model="points" type="number" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('points') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="is_fail" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('workflow.assessments.bands.form.fail_band') }}</span>
                    </label>

                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('workflow.assessments.bands.form.active_band') }}</span>
                    </label>

                    <div class="flex gap-3">
                        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $editingId ? __('workflow.assessments.bands.form.update_submit') : __('workflow.assessments.bands.form.create_submit') }}</button>
                        @if ($editingId)
                            <button type="button" wire:click="cancel" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            @else
                <div class="text-sm text-neutral-500">{{ __('workflow.assessments.bands.read_only') }}</div>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">{{ __('workflow.assessments.bands.table.title') }}</div>

            @if ($bands->isEmpty())
                <div class="px-5 py-10 text-sm text-neutral-500">{{ __('workflow.assessments.bands.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                            <tr>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.bands.table.headers.band') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.bands.table.headers.range') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.bands.table.headers.points') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.bands.table.headers.flags') }}</th>
                                <th class="px-5 py-3 text-right font-medium">{{ __('workflow.assessments.bands.table.headers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($bands as $band)
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="font-medium">{{ $band->name }}</div>
                                        <div class="text-xs text-neutral-500">{{ $band->assessmentType?->name ?: __('workflow.common.not_available') }}</div>
                                    </td>
                                    <td class="px-5 py-3">{{ number_format((float) $band->from_mark, 2) }} - {{ number_format((float) $band->to_mark, 2) }}</td>
                                    <td class="px-5 py-3">{{ $band->pointType?->name ?: __('workflow.assessments.bands.form.no_point_type') }} | {{ $band->points ?? 0 }}</td>
                                    <td class="px-5 py-3">{{ $band->is_fail ? __('workflow.common.flags.fail') : __('workflow.common.flags.pass') }} | {{ $band->is_active ? __('workflow.common.flags.active') : __('workflow.common.flags.inactive') }}</td>
                                    <td class="px-5 py-3">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            @can('assessment-score-bands.manage')
                                                <button type="button" wire:click="edit({{ $band->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="delete({{ $band->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
