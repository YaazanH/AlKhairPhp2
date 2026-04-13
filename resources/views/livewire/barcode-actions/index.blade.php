<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AppSetting;
use App\Models\BarcodeAction;
use App\Services\BarcodeActions\BarcodeActionCatalogService;
use App\Services\IdCards\IdCardPrintLayoutService;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions;
    use WithFileUploads;

    public ?string $dump_command_image_path = null;
    public ?string $clear_command_image_path = null;
    public $dump_command_image = null;
    public $clear_command_image = null;

    public function mount(): void
    {
        $this->authorizePermission('barcode-actions.view');
        app(BarcodeActionCatalogService::class)->syncReferenceActions();
        $this->loadScannerSettings();
    }

    public function with(): array
    {
        app(BarcodeActionCatalogService::class)->syncReferenceActions();

        return [
            'actions' => BarcodeAction::query()->with(['attendanceStatus', 'pointType'])->orderBy('sort_order')->orderBy('name')->get(),
            'defaults' => array_merge(app(IdCardPrintLayoutService::class)->defaults(), ['label_width_mm' => 70, 'label_height_mm' => 35]),
        ];
    }

    public function saveScannerSettings(): void
    {
        $this->authorizePermission('barcode-actions.manage');

        $this->validate([
            'dump_command_image' => ['nullable', 'image', 'max:4096'],
            'clear_command_image' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($this->dump_command_image instanceof TemporaryUploadedFile) {
            if ($this->dump_command_image_path) {
                Storage::disk('public')->delete($this->dump_command_image_path);
            }

            $this->dump_command_image_path = $this->dump_command_image->store('scanner/commands', 'public');
            AppSetting::storeValue('scanner', 'dump_command_image_path', $this->dump_command_image_path);
            $this->dump_command_image = null;
        }

        if ($this->clear_command_image instanceof TemporaryUploadedFile) {
            if ($this->clear_command_image_path) {
                Storage::disk('public')->delete($this->clear_command_image_path);
            }

            $this->clear_command_image_path = $this->clear_command_image->store('scanner/commands', 'public');
            AppSetting::storeValue('scanner', 'clear_command_image_path', $this->clear_command_image_path);
            $this->clear_command_image = null;
        }

        session()->flash('status', __('barcodes.settings.messages.saved'));
    }

    public function toggleAction(int $actionId): void
    {
        $this->authorizePermission('barcode-actions.manage');

        $action = BarcodeAction::query()->findOrFail($actionId);
        $action->update(['is_active' => ! $action->is_active]);
        session()->flash('status', __('barcodes.actions.messages.status_updated'));
    }

    protected function loadScannerSettings(): void
    {
        $settings = AppSetting::groupValues('scanner');
        $this->dump_command_image_path = $settings->get('dump_command_image_path') ?: null;
        $this->clear_command_image_path = $settings->get('clear_command_image_path') ?: null;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('barcodes.actions.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('barcodes.actions.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('barcodes.actions.stats.actions') }}: {{ number_format($actions->count()) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('barcodes.actions.stats.active') }}: {{ number_format($actions->where('is_active', true)->count()) }}</span>
            @can('barcode-scans.import')
                <a href="{{ route('barcode-actions.import') }}" wire:navigate class="pill-link pill-link--compact">{{ __('barcodes.import.title') }}</a>
            @endcan
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section class="space-y-6">
            <form method="POST" action="{{ route('barcode-actions.print.preview') }}" target="_blank" class="surface-panel p-5 lg:p-6">
                @csrf
                <div class="admin-toolbar">
                    <div>
                        <div class="admin-toolbar__title">{{ __('barcodes.actions.print.title') }}</div>
                        <p class="admin-toolbar__subtitle">{{ __('barcodes.actions.print.subtitle') }}</p>
                    </div>
                    <div class="admin-toolbar__actions">
                        <button type="submit" class="pill-link pill-link--accent">{{ __('barcodes.actions.buttons.print_selected') }}</button>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-4">
                    <div class="admin-form-field"><label>{{ __('barcodes.print.fields.label_width_mm') }}</label><input name="label_width_mm" type="number" step="0.1" min="30" value="{{ $defaults['label_width_mm'] }}"></div>
                    <div class="admin-form-field"><label>{{ __('barcodes.print.fields.label_height_mm') }}</label><input name="label_height_mm" type="number" step="0.1" min="18" value="{{ $defaults['label_height_mm'] }}"></div>
                    @foreach (['page_width_mm', 'page_height_mm', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'gap_x_mm', 'gap_y_mm'] as $field)
                        <div class="admin-form-field"><label>{{ __('id_cards.print.setup.fields.'.$field) }}</label><input name="{{ $field }}" type="number" step="0.1" min="0" value="{{ $defaults[$field] }}"></div>
                    @endforeach
                </div>

                <section class="surface-table mt-6">
                    <div class="admin-grid-meta">
                        <div>
                            <div class="admin-grid-meta__title">{{ __('barcodes.actions.table.title') }}</div>
                            <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($actions->count())]) }}</div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.actions.table.headers.print') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.actions.table.headers.action') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.actions.table.headers.code') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.actions.table.headers.type') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.actions.table.headers.status') }}</th>
                                    <th class="px-5 py-4 text-right lg:px-6">{{ __('barcodes.actions.table.headers.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($actions as $action)
                                    <tr class="{{ $action->is_active ? '' : 'opacity-60' }}">
                                        <td class="px-5 py-4 lg:px-6"><input type="checkbox" name="action_ids[]" value="{{ $action->id }}" @disabled(! $action->is_active) class="rounded border-neutral-300 text-emerald-600"></td>
                                        <td class="px-5 py-4 lg:px-6"><div class="font-semibold text-white">{{ $action->name }}</div><div class="mt-1 text-xs text-neutral-500">{{ $action->notes ?: __('crud.common.not_available') }}</div></td>
                                        <td class="px-5 py-4 font-mono text-neutral-200 lg:px-6">{{ $action->code }}</td>
                                        <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('barcodes.actions.types.'.$action->type) }} @if ($action->isPoints()) <span class="{{ (int) $action->points >= 0 ? 'status-chip status-chip--emerald' : 'status-chip status-chip--rose' }}">{{ $action->points }}</span> @endif</td>
                                        <td class="px-5 py-4 lg:px-6"><span class="{{ $action->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">{{ $action->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</span></td>
                                        <td class="px-5 py-4 lg:px-6">
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                @can('barcode-actions.manage')
                                                    @if ($action->isAttendance())
                                                        <button type="button" wire:click="toggleAction({{ $action->id }})" class="pill-link pill-link--compact">{{ $action->is_active ? __('barcodes.actions.buttons.disable') : __('barcodes.actions.buttons.enable') }}</button>
                                                    @else
                                                        <span class="text-xs text-neutral-500">{{ __('barcodes.actions.table.synced_from_settings') }}</span>
                                                    @endif
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </form>
        </section>

        <aside class="space-y-6">
            @can('barcode-actions.manage')
                <section class="surface-panel p-5 lg:p-6">
                    <div class="admin-toolbar__title">{{ __('barcodes.actions.sync.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('barcodes.actions.sync.subtitle') }}</p>
                    <div class="mt-5 space-y-3 text-sm leading-6 text-neutral-300">
                        <p>{{ __('barcodes.actions.sync.points_source') }}</p>
                        <p>{{ __('barcodes.actions.sync.attendance_source') }}</p>
                    </div>
                    <div class="mt-5">
                        <a href="{{ route('settings.points') }}" wire:navigate class="pill-link pill-link--accent">{{ __('barcodes.actions.sync.manage_points') }}</a>
                    </div>
                </section>
            @endcan

            <section class="surface-panel p-5 lg:p-6">
                <div class="admin-toolbar__title">{{ __('barcodes.settings.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('barcodes.settings.subtitle') }}</p>
                <form wire:submit="saveScannerSettings" class="mt-5 space-y-5">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('barcodes.settings.fields.dump_command_image') }}</label>
                        @if ($dump_command_image_path)<img src="{{ asset('storage/'.ltrim($dump_command_image_path, '/')) }}" alt="" class="mb-3 max-h-32 rounded-xl border border-white/10 bg-white p-3">@endif
                        <input wire:model="dump_command_image" type="file" accept="image/*" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('barcodes.settings.fields.clear_command_image') }}</label>
                        @if ($clear_command_image_path)<img src="{{ asset('storage/'.ltrim($clear_command_image_path, '/')) }}" alt="" class="mb-3 max-h-32 rounded-xl border border-white/10 bg-white p-3">@endif
                        <input wire:model="clear_command_image" type="file" accept="image/*" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                    @can('barcode-actions.manage')
                        <button type="submit" class="pill-link pill-link--accent">{{ __('barcodes.settings.buttons.save') }}</button>
                    @endcan
                </form>
            </section>
        </aside>
    </div>
</div>
