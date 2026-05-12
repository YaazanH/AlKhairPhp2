<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceReportTemplate;
use App\Services\FinanceReportService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithPagination;

    public ?int $editing_id = null;
    public string $name = '';
    public string $title = '';
    public string $subtitle = '';
    public string $language = FinanceReportTemplate::LANGUAGE_BOTH;
    public bool $is_default = false;
    public bool $include_exported_at = true;
    public bool $include_opening_balance = true;
    public bool $include_closing_balance = true;
    public array $columns = [];
    public bool $showTemplateModal = false;
    public int $perPage = 10;

    public function mount(): void
    {
        $this->authorizePermission('finance.report-templates.manage');
    }

    public function with(): array
    {
        return [
            'availableColumns' => app(FinanceReportService::class)->ledgerColumnDefinitions(),
            'languages' => FinanceReportTemplate::LANGUAGES,
            'templates' => FinanceReportTemplate::query()
                ->with('createdBy')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->paginate($this->perPage),
        ];
    }

    public function openTemplateModal(): void
    {
        $this->resetForm();
        $this->showTemplateModal = true;
    }

    public function editTemplate(int $templateId): void
    {
        $template = FinanceReportTemplate::query()->findOrFail($templateId);

        $this->editing_id = $template->id;
        $this->name = $template->name;
        $this->title = $template->title;
        $this->subtitle = $template->subtitle ?? '';
        $this->language = $template->language;
        $this->is_default = $template->is_default;
        $this->include_exported_at = $template->include_exported_at;
        $this->include_opening_balance = $template->include_opening_balance;
        $this->include_closing_balance = $template->include_closing_balance;
        $this->columns = $template->normalizedColumns();
        $this->showTemplateModal = true;
        $this->resetValidation();
    }

    public function closeTemplateModal(): void
    {
        $this->resetForm();
    }

    public function saveTemplate(): void
    {
        $availableColumns = array_keys(app(FinanceReportService::class)->ledgerColumnDefinitions());
        $this->columns = array_values(array_intersect($this->columns, $availableColumns));

        $validated = $this->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', Rule::in($availableColumns)],
            'include_closing_balance' => ['boolean'],
            'include_exported_at' => ['boolean'],
            'include_opening_balance' => ['boolean'],
            'is_default' => ['boolean'],
            'language' => ['required', Rule::in(FinanceReportTemplate::LANGUAGES)],
            'name' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:1000'],
            'title' => ['required', 'string', 'max:255'],
        ]);

        if ($validated['is_default'] || FinanceReportTemplate::query()->when($this->editing_id, fn ($query) => $query->whereKeyNot($this->editing_id))->doesntExist()) {
            FinanceReportTemplate::query()->whereKeyNot($this->editing_id ?: 0)->update(['is_default' => false]);
            $validated['is_default'] = true;
        }

        FinanceReportTemplate::query()->updateOrCreate(
            ['id' => $this->editing_id],
            [
                'columns' => $validated['columns'],
                'created_by' => $this->editing_id ? FinanceReportTemplate::query()->whereKey($this->editing_id)->value('created_by') : auth()->id(),
                'include_closing_balance' => $validated['include_closing_balance'],
                'include_exported_at' => $validated['include_exported_at'],
                'include_opening_balance' => $validated['include_opening_balance'],
                'is_default' => $validated['is_default'],
                'language' => $validated['language'],
                'name' => $validated['name'],
                'subtitle' => $validated['subtitle'] ?: null,
                'title' => $validated['title'],
            ],
        );

        $this->resetForm();
        session()->flash('status', __('finance.report_templates.messages.saved'));
    }

    public function deleteTemplate(int $templateId): void
    {
        if (FinanceReportTemplate::query()->count() <= 1) {
            $this->addError('deleteTemplate', __('finance.report_templates.messages.keep_one'));

            return;
        }

        $template = FinanceReportTemplate::query()->findOrFail($templateId);
        $wasDefault = $template->is_default;
        $template->delete();

        if ($wasDefault) {
            FinanceReportTemplate::query()->orderBy('name')->first()?->update(['is_default' => true]);
        }

        session()->flash('status', __('finance.report_templates.messages.deleted'));
    }

    protected function resetForm(): void
    {
        $this->editing_id = null;
        $this->name = '';
        $this->title = __('finance.report_templates.default_title');
        $this->subtitle = '';
        $this->language = FinanceReportTemplate::LANGUAGE_BOTH;
        $this->is_default = FinanceReportTemplate::query()->doesntExist();
        $this->include_exported_at = true;
        $this->include_opening_balance = true;
        $this->include_closing_balance = true;
        $this->columns = FinanceReportTemplate::DEFAULT_COLUMNS;
        $this->showTemplateModal = false;
        $this->resetValidation();
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.report_templates.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.report_templates.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif
    @error('deleteTemplate') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.report_templates.table_title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($templates->total())]) }}</div>
            </div>
            <button type="button" wire:click="openTemplateModal" class="pill-link pill-link--accent">{{ __('finance.report_templates.create') }}</button>
        </div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left">{{ __('finance.fields.name') }}</th>
                        <th class="px-5 py-3 text-left">{{ __('finance.report_templates.language') }}</th>
                        <th class="px-5 py-3 text-left">{{ __('finance.report_templates.columns') }}</th>
                        <th class="px-5 py-3 text-left">{{ __('finance.fields.state') }}</th>
                        <th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @foreach ($templates as $template)
                        <tr>
                            <td class="px-5 py-3">
                                <div class="font-medium text-white">{{ $template->name }}</div>
                                <div class="text-xs text-neutral-500">{{ $template->title }}</div>
                            </td>
                            <td class="px-5 py-3">{{ __('finance.report_templates.languages.'.$template->language) }}</td>
                            <td class="px-5 py-3">{{ count($template->normalizedColumns()) }}</td>
                            <td class="px-5 py-3">
                                @if ($template->is_default)
                                    <span class="status-chip status-chip--emerald">{{ __('finance.report_templates.default') }}</span>
                                @else
                                    <span class="status-chip status-chip--slate">{{ __('finance.common.active') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="admin-action-cluster admin-action-cluster--end">
                                    <button type="button" wire:click="editTemplate({{ $template->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit') }}</button>
                                    <button type="button" wire:click="deleteTemplate({{ $template->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($templates->hasPages()) <div class="border-t border-white/8 px-5 py-4">{{ $templates->links() }}</div> @endif
    </section>

    <x-admin.modal :show="$showTemplateModal" :title="$editing_id ? __('finance.report_templates.edit') : __('finance.report_templates.create')" :description="__('finance.report_templates.modal_subtitle')" close-method="closeTemplateModal" max-width="5xl">
        <form wire:submit="saveTemplate" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label>
                    <input wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.language') }}</label>
                    <select wire:model="language" class="w-full rounded-xl px-4 py-3 text-sm">
                        @foreach ($languages as $option)
                            <option value="{{ $option }}">{{ __('finance.report_templates.languages.'.$option) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.report_title') }}</label>
                <input wire:model="title" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('title') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.report_subtitle') }}</label>
                <textarea wire:model="subtitle" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
            </div>

            <div class="grid gap-3 rounded-3xl border border-white/10 bg-white/[0.03] p-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="flex items-center gap-3 text-sm"><input wire:model="is_default" type="checkbox" class="rounded"> {{ __('finance.report_templates.default') }}</label>
                <label class="flex items-center gap-3 text-sm"><input wire:model="include_opening_balance" type="checkbox" class="rounded"> {{ __('finance.report_templates.include_opening') }}</label>
                <label class="flex items-center gap-3 text-sm"><input wire:model="include_closing_balance" type="checkbox" class="rounded"> {{ __('finance.report_templates.include_closing') }}</label>
                <label class="flex items-center gap-3 text-sm"><input wire:model="include_exported_at" type="checkbox" class="rounded"> {{ __('finance.report_templates.include_exported_at') }}</label>
            </div>

            <div>
                <div class="admin-section-card__title">{{ __('finance.report_templates.columns') }}</div>
                <p class="mt-1 text-sm text-neutral-400">{{ __('finance.report_templates.columns_help') }}</p>
                <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($availableColumns as $column => $definition)
                        <label class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm">
                            <span class="flex items-center gap-3">
                                <input wire:model="columns" type="checkbox" value="{{ $column }}" class="rounded">
                                <span>{{ $definition['ar'] }} / {{ $definition['en'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('columns') <div class="mt-2 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeTemplateModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('settings.common.actions.save') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
