<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Course;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public bool $is_active = true;
    public string $search = '';
    public string $statusFilter = 'all';
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizePermission('courses.view');
    }

    public function with(): array
    {
        $baseQuery = Course::query()
            ->withCount('groups')
            ->orderBy('name');

        $filteredQuery = Course::query()
            ->withCount('groups')
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderBy('name');

        return [
            'courses' => $filteredQuery->get(),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => Course::query()->where('is_active', true)->count(),
            ],
            'filteredCount' => (clone $filteredQuery)->count(),
        ];
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('courses', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('courses.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'courses.update' : 'courses.create');

        $validated = $this->validate();

        Course::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        session()->flash(
            'status',
            $this->editingId ? __('crud.courses.messages.updated') : __('crud.courses.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $courseId): void
    {
        $this->authorizePermission('courses.update');

        $course = Course::query()->findOrFail($courseId);

        $this->editingId = $course->id;
        $this->name = $course->name;
        $this->description = $course->description ?? '';
        $this->is_active = $course->is_active;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->is_active = true;
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function delete(int $courseId): void
    {
        $this->authorizePermission('courses.delete');

        $course = Course::query()->withCount('groups')->findOrFail($courseId);

        if ($course->groups_count > 0) {
            $this->addError('delete', __('crud.courses.errors.delete_linked'));

            return;
        }

        $course->delete();

        if ($this->editingId === $courseId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.courses.messages.deleted'));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.academics') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.courses.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.courses.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.courses.stats.all') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.courses.stats.active') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
        </article>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.courses.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.courses.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="course-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="course-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="course-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="course-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('courses.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('courses.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.courses.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($courses->isEmpty())
            <div class="admin-empty-state">{{ __('crud.courses.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.groups') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.courses.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($courses as $course)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $course->name }}</div>
                                    <div class="mt-1 text-sm text-neutral-400">{{ $course->description ?: __('crud.courses.table.no_description') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($course->groups_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $course->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ $course->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('courses.update')
                                            <button type="button" wire:click="edit({{ $course->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                        @endcan
                                        @can('courses.delete')
                                            <button type="button" wire:click="delete({{ $course->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
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

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.courses.form.edit_title') : __('crud.courses.form.create_title')"
        :description="__('crud.courses.form.help')"
        close-method="cancel"
        max-width="3xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div>
                <label for="course-name" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.name') }}</label>
                <input id="course-name" wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('name')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="course-description" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.description') }}</label>
                <textarea id="course-description" wire:model="description" rows="5" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('description')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.courses.form.active_course') }}</span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.courses.form.update_submit') : __('crud.courses.form.create_submit') }}
                </button>
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
