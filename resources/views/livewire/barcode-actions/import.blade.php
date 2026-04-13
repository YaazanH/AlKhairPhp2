<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AppSetting;
use App\Models\BarcodeScanImport;
use App\Models\Course;
use App\Services\BarcodeActions\ScannerDumpImportService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $course_id = null;
    public string $attendance_date = '';
    public string $raw_dump = '';
    public array $preview = [];
    public ?int $import_id = null;

    public function mount(): void
    {
        $this->authorizePermission('barcode-scans.import');
        $this->attendance_date = now()->toDateString();
    }

    public function resetAttendanceDateToToday(): void
    {
        $this->attendance_date = now()->toDateString();
    }

    public function with(): array
    {
        $settings = AppSetting::groupValues('scanner');

        return [
            'courses' => Course::query()
                ->whereHas('groups', function (Builder $query) {
                    $this->scopeGroupsQuery($query->where('is_active', true));
                })
                ->orderBy('name')
                ->get(),
            'recentImports' => BarcodeScanImport::query()
                ->with('course')
                ->where('created_by', auth()->id())
                ->latest()
                ->limit(8)
                ->get(),
            'scannerSettings' => [
                'dump_command_image_path' => $settings->get('dump_command_image_path'),
                'clear_command_image_path' => $settings->get('clear_command_image_path'),
            ],
        ];
    }

    public function applyDump(): void
    {
        $validated = $this->validatedInput();
        $result = app(ScannerDumpImportService::class)->apply(
            (int) $validated['course_id'],
            $validated['attendance_date'],
            $validated['raw_dump'],
            auth()->user(),
        );

        $this->preview = collect($result)->except('import')->all();
        $this->import_id = $result['import']?->id;

        if (($result['error_count'] ?? 0) > 0) {
            $this->addError('raw_dump', __('barcodes.import.errors.fix_preview_errors'));

            return;
        }

        if (($result['ready_count'] ?? 0) < 1) {
            $this->addError('raw_dump', __('barcodes.import.errors.no_ready_rows'));

            return;
        }

        session()->flash('status', __('barcodes.import.messages.imported', ['count' => number_format($result['ready_count'])]));
    }

    public function previewDump(): void
    {
        $validated = $this->validatedInput();
        $this->preview = app(ScannerDumpImportService::class)->preview(
            (int) $validated['course_id'],
            $validated['attendance_date'],
            $validated['raw_dump'],
            auth()->user(),
        );
        $this->import_id = null;
    }

    protected function validatedInput(): array
    {
        return $this->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'attendance_date' => ['required', 'date'],
            'raw_dump' => ['required', 'string'],
        ], [], [
            'course_id' => __('barcodes.import.fields.course'),
            'attendance_date' => __('barcodes.import.fields.attendance_date'),
            'raw_dump' => __('barcodes.import.fields.raw_dump'),
        ]);
    }
}; ?>

<div class="page-stack" wire:init="resetAttendanceDateToToday">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('barcodes.import.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('barcodes.import.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('barcode-actions.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('barcodes.import.actions.manage_actions') }}</a>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar__title">{{ __('barcodes.import.form.title') }}</div>
            <p class="admin-toolbar__subtitle">{{ __('barcodes.import.form.subtitle') }}</p>

            <div class="mt-6 admin-form-grid">
                <div class="admin-form-field">
                    <label>{{ __('barcodes.import.fields.course') }}</label>
                    <select wire:model="course_id">
                        <option value="">{{ __('barcodes.import.placeholders.course') }}</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                    @error('course_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="admin-form-field">
                    <label>{{ __('barcodes.import.fields.attendance_date') }}</label>
                    <input wire:model="attendance_date" type="date">
                    @error('attendance_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="admin-form-field admin-form-field--full">
                    <label>{{ __('barcodes.import.fields.raw_dump') }}</label>
                    <textarea wire:model="raw_dump" rows="10" placeholder="{{ __('barcodes.import.placeholders.raw_dump') }}"></textarea>
                    @error('raw_dump') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mt-5 admin-action-cluster">
                <button type="button" wire:click="previewDump" class="pill-link">{{ __('barcodes.import.actions.preview') }}</button>
                <button type="button" wire:click="applyDump" class="pill-link pill-link--accent">{{ __('barcodes.import.actions.apply') }}</button>
            </div>
        </section>

        <aside class="space-y-6">
            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('barcodes.import.scanner_commands.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('barcodes.import.scanner_commands.subtitle') }}</p>
                <div class="mt-5 space-y-5">
                    @foreach (['dump_command_image_path' => __('barcodes.settings.fields.dump_command_image'), 'clear_command_image_path' => __('barcodes.settings.fields.clear_command_image')] as $key => $label)
                        <div>
                            <div class="mb-2 text-sm font-semibold text-white">{{ $label }}</div>
                            @if ($scannerSettings[$key])
                                <img src="{{ asset('storage/'.ltrim($scannerSettings[$key], '/')) }}" alt="{{ $label }}" class="max-h-32 rounded-xl border border-white/10 bg-white p-3">
                            @else
                                <div class="rounded-xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">{{ __('barcodes.import.scanner_commands.missing') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('barcodes.import.history.title') }}</div>
                @if ($recentImports->isEmpty())
                    <div class="mt-4 text-sm text-neutral-400">{{ __('barcodes.import.history.empty') }}</div>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($recentImports as $import)
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm">
                                <div class="font-semibold text-white">{{ $import->course?->name }}</div>
                                <div class="mt-1 text-neutral-400">{{ $import->attendance_date?->format('Y-m-d') }} | {{ __('barcodes.import.history.processed', ['count' => number_format($import->processed_count)]) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </aside>
    </div>

    @if ($preview !== [])
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('barcodes.import.preview.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('barcodes.import.preview.summary', ['ready' => number_format($preview['ready_count'] ?? 0), 'skipped' => number_format($preview['skipped_count'] ?? 0), 'errors' => number_format($preview['error_count'] ?? 0)]) }}</div>
                </div>
            </div>

            @if (! empty($preview['messages']))
                <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($preview['messages'] as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($preview['rows']))
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.sequence') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.value') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.action') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.student') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.result') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('barcodes.import.preview.headers.message') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($preview['rows'] as $row)
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['sequence_no'] }}</td>
                                    <td class="px-5 py-4 font-mono text-white lg:px-6">{{ $row['normalized_value'] }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['action_name'] ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['student_name'] ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['group_name'] ?: __('workflow.common.no_group') }}</td>
                                    <td class="px-5 py-4 lg:px-6"><span class="{{ in_array($row['result'], ['ready', 'applied'], true) ? 'status-chip status-chip--emerald' : ($row['result'] === 'error' ? 'status-chip status-chip--rose' : 'status-chip status-chip--slate') }}">{{ __('barcodes.import.results.'.$row['result']) }}</span></td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
