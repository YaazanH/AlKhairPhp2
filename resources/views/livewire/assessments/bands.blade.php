<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\PointType;
use App\Services\AssessmentService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $editingId = null;
    public ?int $assessment_type_id = null;
    public string $name = '';
    public string $from_mark = '';
    public string $to_mark = '';
    public ?int $point_type_id = null;
    public string $points = '';
    public bool $is_fail = false;
    public bool $is_active = true;
    public bool $showForm = false;

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
            'points' => ['nullable', 'integer'],
            'is_fail' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function create(): void
    {
        $this->authorizePermission('assessment-score-bands.manage');

        $this->cancel(closeForm: false);
        $this->showForm = true;
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
                'points' => $validated['points'] === '' ? null : $validated['points'],
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
        $this->points = $band->points !== null ? (string) $band->points : '';
        $this->is_fail = $band->is_fail;
        $this->is_active = $band->is_active;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function cancel(bool $closeForm = true): void
    {
        $this->editingId = null;
        $this->assessment_type_id = null;
        $this->name = '';
        $this->from_mark = '';
        $this->to_mark = '';
        $this->point_type_id = null;
        $this->points = '';
        $this->is_fail = false;
        $this->is_active = true;

        if ($closeForm) {
            $this->showForm = false;
        }

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

    public function effectivePoints(AssessmentScoreBand $band): int
    {
        return app(AssessmentService::class)->effectiveBandPoints($band);
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('assessments.index') }}" wire:navigate class="text-sm font-medium text-neutral-300 hover:text-white">{{ __('workflow.common.back_to_assessments') }}</a>
                <div class="eyebrow mt-4">{{ __('ui.nav.assessments') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.assessments.bands.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.assessments.bands.subtitle') }}</p>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="space-y-6">
        @if ($showForm)
            <section class="admin-modal" role="dialog" aria-modal="true">
                <div class="admin-modal__backdrop" wire:click="cancel"></div>
                <div class="admin-modal__viewport">
                    <div class="admin-modal__dialog admin-modal__dialog--3xl">
                        <div class="admin-modal__header">
                            <div>
                                <div class="admin-modal__title">{{ $editingId ? __('workflow.assessments.bands.form.edit_title') : __('workflow.assessments.bands.form.create_title') }}</div>
                                <p class="admin-modal__description">{{ __('workflow.assessments.bands.form.help') }}</p>
                            </div>
                            <button type="button" wire:click="cancel" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                        </div>

                        <div class="admin-modal__body">
                            @if (auth()->user()->can('assessment-score-bands.manage'))
                                <form wire:submit="save" class="space-y-5">
                                    <div class="admin-form-grid md:grid-cols-2">
                                        <div class="admin-form-field">
                                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.assessment_type') }}</label>
                                            <select wire:model="assessment_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                                <option value="">{{ __('workflow.assessments.bands.form.select_type') }}</option>
                                                @foreach ($types as $type)
                                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('assessment_type_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                        </div>

                                        <div class="admin-form-field">
                                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.band_name') }}</label>
                                            <input wire:model="name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                            @error('name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                        </div>

                                        <div class="admin-form-field">
                                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.from_mark') }}</label>
                                            <input wire:model="from_mark" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                            @error('from_mark') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                        </div>

                                        <div class="admin-form-field">
                                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.to_mark') }}</label>
                                            <input wire:model="to_mark" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                            @error('to_mark') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                        </div>

                                        <div class="admin-form-field">
                                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.point_type') }}</label>
                                            <select wire:model="point_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                                <option value="">{{ __('workflow.assessments.bands.form.no_point_type') }}</option>
                                                @foreach ($pointTypes as $pointType)
                                                    <option value="{{ $pointType->id }}">{{ $pointType->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('point_type_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                        </div>

                                        <div class="admin-form-field">
                                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.bands.form.points') }}</label>
                                            <input wire:model="points" type="number" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                            <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.assessments.bands.form.points_help') }}</div>
                                            @error('points') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                        </div>

                                        <label class="admin-checkbox">
                                            <input wire:model="is_fail" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                                            <span>{{ __('workflow.assessments.bands.form.fail_band') }}</span>
                                        </label>

                                        <label class="admin-checkbox">
                                            <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                                            <span>{{ __('workflow.assessments.bands.form.active_band') }}</span>
                                        </label>
                                    </div>

                                    <div class="admin-action-cluster admin-action-cluster--end">
                                        <button type="button" wire:click="cancel" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                                        <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('workflow.assessments.bands.form.update_submit') : __('workflow.assessments.bands.form.create_submit') }}</button>
                                    </div>
                                </form>
                            @else
                                <div class="admin-empty-state">{{ __('workflow.assessments.bands.read_only') }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.assessments.bands.table.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($bands->count())]) }}</div>
                </div>

                @can('assessment-score-bands.manage')
                    <button type="button" wire:click="create" class="pill-link pill-link--accent">
                        {{ __('workflow.assessments.bands.form.create_title') }}
                    </button>
                @endcan
            </div>

            @if ($bands->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.assessments.bands.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
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
                                    <td class="px-5 py-3">
                                        {{ number_format((float) $band->from_mark, 2) }} - {{ number_format((float) $band->to_mark, 2) }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <div>{{ $band->pointType?->name ?: __('workflow.assessments.bands.form.no_point_type') }}</div>
                                        <div class="text-xs text-neutral-500">{{ __('workflow.assessments.bands.form.points') }}: {{ number_format($this->effectivePoints($band)) }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="admin-action-cluster">
                                            <span class="status-chip {{ $band->is_fail ? 'status-chip--rose' : 'status-chip--emerald' }}">
                                                {{ $band->is_fail ? __('workflow.common.flags.fail') : __('workflow.common.flags.pass') }}
                                            </span>
                                            <span class="status-chip {{ $band->is_active ? 'status-chip--gold' : 'status-chip--slate' }}">
                                                {{ $band->is_active ? __('workflow.common.flags.active') : __('workflow.common.flags.inactive') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            @can('assessment-score-bands.manage')
                                                <button type="button" wire:click="edit({{ $band->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="delete({{ $band->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
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
