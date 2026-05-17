<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceReportTemplate;
use App\Services\FinanceReportService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithFileUploads;
    use WithPagination;

    public ?int $editing_id = null;
    public string $name = '';
    public string $title = '';
    public string $subtitle = '';
    public string $header_text = '';
    public string $footer_text = '';
    public string $custom_text = '';
    public string $date_mode = 'exported_at';
    public string $custom_date = '';
    public string $language = FinanceReportTemplate::LANGUAGE_BOTH;
    public bool $is_default = false;
    public bool $include_exported_at = true;
    public bool $include_opening_balance = true;
    public bool $include_closing_balance = true;
    public bool $show_issuer_name = true;
    public bool $show_page_numbers = false;
    public string $shape_type = '';
    public string $shape_color = '#0f7a3d';
    public string $shape_opacity = '0.12';
    public array $columns = [];
    public bool $showTemplateModal = false;
    public bool $remove_background_image = false;
    public bool $remove_logo_image = false;
    public ?string $existing_background_image = null;
    public ?string $existing_logo_image = null;
    public $background_image_upload = null;
    public $logo_image_upload = null;
    public int $perPage = 10;

    public function mount(): void
    {
        $this->authorizePermission('finance.report-templates.manage');
    }

    public function with(): array
    {
        $service = app(FinanceReportService::class);
        $availableColumns = $service->ledgerColumnDefinitions();
        $selectedColumnKeys = collect($this->columns ?: FinanceReportTemplate::DEFAULT_COLUMNS)
            ->filter(fn (string $column) => array_key_exists($column, $availableColumns))
            ->values();

        return [
            'availableColumnOptions' => collect($availableColumns)
                ->reject(fn (array $definition, string $column) => $selectedColumnKeys->contains($column))
                ->map(fn (array $definition, string $column) => [
                    'key' => $column,
                    'label' => $definition['ar'].' / '.$definition['en'],
                ])
                ->values()
                ->all(),
            'dateModes' => ['exported_at', 'today', 'custom'],
            'previewReport' => $this->showTemplateModal ? $this->previewReport() : null,
            'selectedColumns' => $selectedColumnKeys
                ->map(fn (string $column) => [
                    'key' => $column,
                    'label' => $availableColumns[$column]['ar'].' / '.$availableColumns[$column]['en'],
                ])
                ->all(),
            'shapeTypes' => ['rectangle', 'circle', 'triangle'],
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
        $this->header_text = $template->header_text ?? '';
        $this->footer_text = $template->footer_text ?? '';
        $this->custom_text = $template->custom_text ?? '';
        $this->date_mode = in_array($template->date_mode, ['exported_at', 'today', 'custom'], true) ? $template->date_mode : 'exported_at';
        $this->custom_date = $template->custom_date?->toDateString() ?? '';
        $this->language = in_array($template->language, FinanceReportTemplate::LANGUAGES, true) ? $template->language : FinanceReportTemplate::LANGUAGE_BOTH;
        $this->is_default = (bool) ($template->is_default ?? false);
        $this->include_exported_at = $template->include_exported_at ?? true;
        $this->include_opening_balance = $template->include_opening_balance ?? true;
        $this->include_closing_balance = $template->include_closing_balance ?? true;
        $this->show_issuer_name = $template->show_issuer_name ?? true;
        $this->show_page_numbers = $template->show_page_numbers ?? false;
        $this->shape_type = $template->shape_type ?? '';
        $this->shape_color = $template->shape_color ?: '#0f7a3d';
        $this->shape_opacity = (string) ($template->shape_opacity ?? '0.12');
        $this->columns = $template->normalizedColumns();
        $this->existing_background_image = $template->background_image;
        $this->existing_logo_image = $template->logo_image;
        $this->background_image_upload = null;
        $this->logo_image_upload = null;
        $this->remove_background_image = false;
        $this->remove_logo_image = false;
        $this->showTemplateModal = true;
        $this->resetValidation();
    }

    public function closeTemplateModal(): void
    {
        $this->resetForm();
    }

    public function addColumn(string $column): void
    {
        if (! in_array($column, $this->columns, true)) {
            $this->columns[] = $column;
        }
    }

    public function removeColumn(string $column): void
    {
        $this->columns = array_values(array_filter($this->columns, fn (string $selected) => $selected !== $column));
    }

    public function moveColumnUp(string $column): void
    {
        $this->moveColumn($column, -1);
    }

    public function moveColumnDown(string $column): void
    {
        $this->moveColumn($column, 1);
    }

    public function saveTemplate(): void
    {
        if (! $this->templateSchemaIsCurrent()) {
            $this->addError('saveTemplate', __('finance.report_templates.messages.schema_outdated'));

            return;
        }

        $availableColumns = array_keys(app(FinanceReportService::class)->ledgerColumnDefinitions());
        $this->columns = collect($this->columns)
            ->filter(fn (string $column) => in_array($column, $availableColumns, true))
            ->unique()
            ->values()
            ->all();

        $validated = $this->validate([
            'background_image_upload' => ['nullable', 'image', 'max:4096'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', Rule::in($availableColumns)],
            'custom_date' => ['nullable', 'date', Rule::requiredIf(fn () => $this->date_mode === 'custom')],
            'custom_text' => ['nullable', 'string', 'max:4000'],
            'date_mode' => ['required', Rule::in(['exported_at', 'today', 'custom'])],
            'footer_text' => ['nullable', 'string', 'max:4000'],
            'header_text' => ['nullable', 'string', 'max:4000'],
            'include_closing_balance' => ['boolean'],
            'include_exported_at' => ['boolean'],
            'include_opening_balance' => ['boolean'],
            'is_default' => ['boolean'],
            'language' => ['required', Rule::in(FinanceReportTemplate::LANGUAGES)],
            'logo_image_upload' => ['nullable', 'image', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
            'shape_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'shape_opacity' => ['required', 'numeric', 'between:0,1'],
            'shape_type' => ['nullable', Rule::in(['rectangle', 'circle', 'triangle'])],
            'show_issuer_name' => ['boolean'],
            'show_page_numbers' => ['boolean'],
            'subtitle' => ['nullable', 'string', 'max:1000'],
            'title' => ['required', 'string', 'max:255'],
        ]);

        if ($validated['is_default'] || FinanceReportTemplate::query()->when($this->editing_id, fn ($query) => $query->whereKeyNot($this->editing_id))->doesntExist()) {
            FinanceReportTemplate::query()->whereKeyNot($this->editing_id ?: 0)->update(['is_default' => false]);
            $validated['is_default'] = true;
        }

        $template = $this->editing_id
            ? FinanceReportTemplate::query()->findOrFail($this->editing_id)
            : new FinanceReportTemplate();

        if ($this->remove_background_image && $template->background_image) {
            Storage::disk('public')->delete($template->background_image);
            $template->background_image = null;
        }

        if ($validated['background_image_upload'] ?? null) {
            if ($template->background_image) {
                Storage::disk('public')->delete($template->background_image);
            }

            $template->background_image = $validated['background_image_upload']->store('finance/reports/backgrounds', 'public');
        }

        if ($this->remove_logo_image && $template->logo_image) {
            Storage::disk('public')->delete($template->logo_image);
            $template->logo_image = null;
        }

        if ($validated['logo_image_upload'] ?? null) {
            if ($template->logo_image) {
                Storage::disk('public')->delete($template->logo_image);
            }

            $template->logo_image = $validated['logo_image_upload']->store('finance/reports/logos', 'public');
        }

        $template->fill([
            'columns' => $validated['columns'],
            'created_by' => $template->created_by ?: auth()->id(),
            'custom_date' => $validated['date_mode'] === 'custom' ? $validated['custom_date'] : null,
            'custom_text' => $validated['custom_text'] ?: null,
            'date_mode' => $validated['date_mode'],
            'footer_text' => $validated['footer_text'] ?: null,
            'header_text' => $validated['header_text'] ?: null,
            'include_closing_balance' => $validated['include_closing_balance'],
            'include_exported_at' => $validated['include_exported_at'],
            'include_opening_balance' => $validated['include_opening_balance'],
            'is_default' => $validated['is_default'],
            'language' => $validated['language'],
            'name' => $validated['name'],
            'shape_color' => $validated['shape_type'] !== '' ? $validated['shape_color'] : null,
            'shape_opacity' => $validated['shape_type'] !== '' ? (float) $validated['shape_opacity'] : null,
            'shape_type' => $validated['shape_type'] ?: null,
            'show_issuer_name' => $validated['show_issuer_name'],
            'show_page_numbers' => $validated['show_page_numbers'],
            'subtitle' => $validated['subtitle'] ?: null,
            'title' => $validated['title'],
        ]);
        $template->save();

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

        if ($template->background_image) {
            Storage::disk('public')->delete($template->background_image);
        }

        if ($template->logo_image) {
            Storage::disk('public')->delete($template->logo_image);
        }

        $template->delete();

        if ($wasDefault) {
            FinanceReportTemplate::query()->orderBy('name')->first()?->update(['is_default' => true]);
        }

        session()->flash('status', __('finance.report_templates.messages.deleted'));
    }

    public function backgroundPreviewUrl(): ?string
    {
        if ($this->remove_background_image) {
            return null;
        }

        if ($this->background_image_upload) {
            return $this->background_image_upload->temporaryUrl();
        }

        return $this->existing_background_image
            ? (new FinanceReportTemplate(['background_image' => $this->existing_background_image]))->background_image_url
            : null;
    }

    public function logoPreviewUrl(): ?string
    {
        if ($this->remove_logo_image) {
            return null;
        }

        if ($this->logo_image_upload) {
            return $this->logo_image_upload->temporaryUrl();
        }

        return $this->existing_logo_image
            ? (new FinanceReportTemplate(['logo_image' => $this->existing_logo_image]))->logo_image_url
            : null;
    }

    protected function moveColumn(string $column, int $direction): void
    {
        $index = array_search($column, $this->columns, true);

        if ($index === false) {
            return;
        }

        $swapIndex = $index + $direction;

        if (! isset($this->columns[$swapIndex])) {
            return;
        }

        [$this->columns[$index], $this->columns[$swapIndex]] = [$this->columns[$swapIndex], $this->columns[$index]];
    }

    protected function previewReport(): array
    {
        $report = app(FinanceReportService::class)->previewLedgerReport($this->previewTemplate(), auth()->user());
        $report['template']['background_image_url'] = $this->backgroundPreviewUrl();
        $report['template']['logo_image_url'] = $this->logoPreviewUrl();

        return $report;
    }

    protected function previewTemplate(): FinanceReportTemplate
    {
        return new FinanceReportTemplate([
            'background_image' => $this->remove_background_image ? null : $this->existing_background_image,
            'columns' => $this->columns ?: FinanceReportTemplate::DEFAULT_COLUMNS,
            'custom_date' => $this->custom_date !== '' ? $this->custom_date : null,
            'custom_text' => $this->custom_text ?: null,
            'date_mode' => $this->date_mode,
            'footer_text' => $this->footer_text ?: null,
            'header_text' => $this->header_text ?: null,
            'include_closing_balance' => $this->include_closing_balance,
            'include_exported_at' => $this->include_exported_at,
            'include_opening_balance' => $this->include_opening_balance,
            'is_default' => $this->is_default,
            'language' => $this->language,
            'logo_image' => $this->remove_logo_image ? null : $this->existing_logo_image,
            'name' => $this->name !== '' ? $this->name : __('finance.report_templates.preview_name'),
            'shape_color' => $this->shape_color,
            'shape_opacity' => (float) $this->shape_opacity,
            'shape_type' => $this->shape_type !== '' ? $this->shape_type : null,
            'show_issuer_name' => $this->show_issuer_name,
            'show_page_numbers' => $this->show_page_numbers,
            'subtitle' => $this->subtitle ?: null,
            'title' => $this->title !== '' ? $this->title : __('finance.report_templates.default_title'),
        ]);
    }

    protected function templateSchemaIsCurrent(): bool
    {
        return Schema::hasColumns('finance_report_templates', [
            'header_text',
            'footer_text',
            'custom_text',
            'date_mode',
            'custom_date',
            'show_issuer_name',
            'show_page_numbers',
            'background_image',
            'logo_image',
            'shape_type',
            'shape_color',
            'shape_opacity',
        ]);
    }

    protected function resetForm(): void
    {
        $this->editing_id = null;
        $this->name = '';
        $this->title = __('finance.report_templates.default_title');
        $this->subtitle = '';
        $this->header_text = '';
        $this->footer_text = '';
        $this->custom_text = '';
        $this->date_mode = 'exported_at';
        $this->custom_date = '';
        $this->language = FinanceReportTemplate::LANGUAGE_BOTH;
        $this->is_default = FinanceReportTemplate::query()->doesntExist();
        $this->include_exported_at = true;
        $this->include_opening_balance = true;
        $this->include_closing_balance = true;
        $this->show_issuer_name = true;
        $this->show_page_numbers = false;
        $this->shape_type = '';
        $this->shape_color = '#0f7a3d';
        $this->shape_opacity = '0.12';
        $this->columns = FinanceReportTemplate::DEFAULT_COLUMNS;
        $this->remove_background_image = false;
        $this->remove_logo_image = false;
        $this->existing_background_image = null;
        $this->existing_logo_image = null;
        $this->background_image_upload = null;
        $this->logo_image_upload = null;
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
        <form wire:submit="saveTemplate" class="space-y-6">
            @error('saveTemplate') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                <div class="space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label>
                            <input wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.language') }}</label>
                            <select wire:model.live="language" class="w-full rounded-xl px-4 py-3 text-sm">
                                @foreach ($languages as $option)
                                    <option value="{{ $option }}">{{ __('finance.report_templates.languages.'.$option) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.report_title') }}</label>
                        <input wire:model.live="title" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('title') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.report_subtitle') }}</label>
                        <textarea wire:model.live="subtitle" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.header_text') }}</label>
                            <textarea wire:model.live="header_text" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.footer_text') }}</label>
                            <textarea wire:model.live="footer_text" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.custom_text') }}</label>
                        <textarea wire:model.live="custom_text" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.date_mode') }}</label>
                            <select wire:model.live="date_mode" class="w-full rounded-xl px-4 py-3 text-sm">
                                @foreach ($dateModes as $mode)
                                    <option value="{{ $mode }}">{{ __('finance.report_templates.date_modes.'.$mode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.custom_date') }}</label>
                            <input wire:model.live="custom_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($date_mode !== 'custom')>
                            @error('custom_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.shape_type') }}</label>
                            <select wire:model.live="shape_type" class="w-full rounded-xl px-4 py-3 text-sm">
                                <option value="">{{ __('finance.report_templates.no_shape') }}</option>
                                @foreach ($shapeTypes as $type)
                                    <option value="{{ $type }}">{{ __('finance.report_templates.shape_types.'.$type) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.shape_color') }}</label>
                            <input wire:model.live="shape_color" type="color" class="h-12 w-full rounded-xl px-3 py-2 text-sm">
                            @error('shape_color') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.shape_opacity') }}</label>
                            <input wire:model.live="shape_opacity" type="number" step="0.01" min="0" max="1" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('shape_opacity') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.background_image') }}</label>
                            <input wire:model="background_image_upload" type="file" accept="image/*" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('background_image_upload') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            @if ($background_image_upload || $existing_background_image)
                                <div class="mt-3 rounded-2xl border border-white/10 bg-white/[0.03] p-3">
                                    <img src="{{ $background_image_upload ? $background_image_upload->temporaryUrl() : $this->backgroundPreviewUrl() }}" alt="{{ __('finance.report_templates.background_image') }}" class="h-24 w-full rounded-xl object-cover">
                                    @if ($existing_background_image)
                                        <label class="mt-3 flex items-center gap-2 text-sm text-neutral-300">
                                            <input wire:model.live="remove_background_image" type="checkbox" class="rounded">
                                            <span>{{ __('finance.report_templates.remove_background_image') }}</span>
                                        </label>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.report_templates.logo_image') }}</label>
                            <input wire:model="logo_image_upload" type="file" accept="image/*" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('logo_image_upload') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            @if ($logo_image_upload || $existing_logo_image)
                                <div class="mt-3 rounded-2xl border border-white/10 bg-white/[0.03] p-3">
                                    <img src="{{ $logo_image_upload ? $logo_image_upload->temporaryUrl() : $this->logoPreviewUrl() }}" alt="{{ __('finance.report_templates.logo_image') }}" class="h-24 w-full rounded-xl object-contain bg-white/90 p-2">
                                    @if ($existing_logo_image)
                                        <label class="mt-3 flex items-center gap-2 text-sm text-neutral-300">
                                            <input wire:model.live="remove_logo_image" type="checkbox" class="rounded">
                                            <span>{{ __('finance.report_templates.remove_logo_image') }}</span>
                                        </label>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-3xl border border-white/10 bg-white/[0.03] p-4 md:grid-cols-2 xl:grid-cols-3">
                        <label class="flex items-center gap-3 text-sm"><input wire:model="is_default" type="checkbox" class="rounded"> {{ __('finance.report_templates.default') }}</label>
                        <label class="flex items-center gap-3 text-sm"><input wire:model="include_opening_balance" type="checkbox" class="rounded"> {{ __('finance.report_templates.include_opening') }}</label>
                        <label class="flex items-center gap-3 text-sm"><input wire:model="include_closing_balance" type="checkbox" class="rounded"> {{ __('finance.report_templates.include_closing') }}</label>
                        <label class="flex items-center gap-3 text-sm"><input wire:model="include_exported_at" type="checkbox" class="rounded"> {{ __('finance.report_templates.include_exported_at') }}</label>
                        <label class="flex items-center gap-3 text-sm"><input wire:model="show_issuer_name" type="checkbox" class="rounded"> {{ __('finance.report_templates.show_issuer_name') }}</label>
                        <label class="flex items-center gap-3 text-sm"><input wire:model="show_page_numbers" type="checkbox" class="rounded"> {{ __('finance.report_templates.show_page_numbers') }}</label>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-4">
                            <div class="admin-section-card__title">{{ __('finance.report_templates.selected_columns') }}</div>
                            <p class="mt-1 text-sm text-neutral-400">{{ __('finance.report_templates.columns_help') }}</p>
                            <div class="mt-4 space-y-2">
                                @forelse ($selectedColumns as $column)
                                    <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/10 px-3 py-3 text-sm">
                                        <span>{{ $column['label'] }}</span>
                                        <div class="admin-action-cluster">
                                            <button type="button" wire:click="moveColumnUp('{{ $column['key'] }}')" class="pill-link pill-link--compact">{{ __('finance.report_templates.move_up') }}</button>
                                            <button type="button" wire:click="moveColumnDown('{{ $column['key'] }}')" class="pill-link pill-link--compact">{{ __('finance.report_templates.move_down') }}</button>
                                            <button type="button" wire:click="removeColumn('{{ $column['key'] }}')" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.report_templates.remove_column') }}</button>
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-2xl border border-dashed border-white/10 px-4 py-5 text-sm text-neutral-400">{{ __('finance.report_templates.no_columns_selected') }}</div>
                                @endforelse
                            </div>
                            @error('columns') <div class="mt-2 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>

                        <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-4">
                            <div class="admin-section-card__title">{{ __('finance.report_templates.available_columns') }}</div>
                            <p class="mt-1 text-sm text-neutral-400">{{ __('finance.report_templates.columns_order_help') }}</p>
                            <div class="mt-4 space-y-2">
                                @forelse ($availableColumnOptions as $column)
                                    <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/10 px-3 py-3 text-sm">
                                        <span>{{ $column['label'] }}</span>
                                        <button type="button" wire:click="addColumn('{{ $column['key'] }}')" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.report_templates.add_column') }}</button>
                                    </div>
                                @empty
                                    <div class="rounded-2xl border border-dashed border-white/10 px-4 py-5 text-sm text-neutral-400">{{ __('finance.report_templates.all_columns_selected') }}</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <div class="admin-section-card__title">{{ __('finance.report_templates.preview') }}</div>
                        <p class="mt-1 text-sm text-neutral-400">{{ __('finance.report_templates.preview_help') }}</p>
                    </div>

                    @if ($previewReport)
                        <div class="max-h-[72vh] overflow-auto rounded-[2rem] border border-white/10 bg-[#edf3ea] p-4">
                            @include('reports.partials.finance-ledger-document', ['previewMode' => true, 'report' => $previewReport, 'service' => app(FinanceReportService::class)])
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeTemplateModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('settings.common.actions.save') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
