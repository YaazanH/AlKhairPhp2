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
    @php
        $readyCount = (int) ($preview['ready_count'] ?? 0);
        $skippedCount = (int) ($preview['skipped_count'] ?? 0);
        $errorCount = (int) ($preview['error_count'] ?? 0);
    @endphp

    <section class="page-hero overflow-hidden p-6 lg:p-8">
        <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('barcodes.import.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('barcodes.import.subtitle') }}</p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="#scanner-command-panel" class="pill-link pill-link--compact">{{ __('barcodes.import.scanner_commands.title') }}</a>
                <a href="{{ route('barcode-actions.index') }}" wire:navigate class="pill-link pill-link--accent pill-link--compact">{{ __('barcodes.import.actions.manage_actions') }}</a>
            </div>
        </div>

        <div class="relative z-10 mt-8 grid gap-3 md:grid-cols-3">
            @foreach ([
                ['number' => '01', 'title' => __('barcodes.import.workflow.print.title'), 'copy' => __('barcodes.import.workflow.print.copy')],
                ['number' => '02', 'title' => __('barcodes.import.workflow.scan.title'), 'copy' => __('barcodes.import.workflow.scan.copy')],
                ['number' => '03', 'title' => __('barcodes.import.workflow.review.title'), 'copy' => __('barcodes.import.workflow.review.copy')],
            ] as $step)
                <article class="rounded-3xl border border-white/10 bg-white/[0.06] p-4 shadow-2xl shadow-black/10 backdrop-blur">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-emerald-300/20 bg-emerald-400/10 font-display text-lg text-emerald-100">{{ $step['number'] }}</div>
                        <div>
                            <div class="font-semibold text-white">{{ $step['title'] }}</div>
                            <p class="mt-1 text-sm leading-6 text-neutral-300">{{ $step['copy'] }}</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
        <section class="surface-panel overflow-hidden p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('barcodes.import.form.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('barcodes.import.form.subtitle') }}</p>
                </div>
                <div class="admin-toolbar__actions">
                    <span class="status-chip status-chip--gold">{{ __('barcodes.import.context.today', ['date' => $attendance_date ?: now()->toDateString()]) }}</span>
                </div>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <div class="soft-callout p-4">
                    <div class="kpi-label">{{ __('barcodes.import.context.title') }}</div>
                    <p class="mt-2 text-sm leading-6">{{ __('barcodes.import.context.copy') }}</p>
                </div>

                <div class="soft-callout p-4">
                    <div class="kpi-label">{{ __('barcodes.import.dump.title') }}</div>
                    <p class="mt-2 text-sm leading-6">{{ __('barcodes.import.dump.copy') }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
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
            </div>

            <div class="admin-form-field mt-5">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <label>{{ __('barcodes.import.fields.raw_dump') }}</label>
                    <span class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-100/70">{{ __('barcodes.import.dump.focus_hint') }}</span>
                </div>
                <textarea
                    wire:model="raw_dump"
                    rows="16"
                    placeholder="{{ __('barcodes.import.placeholders.raw_dump') }}"
                    class="min-h-[24rem] font-mono text-base leading-7"
                    autofocus
                ></textarea>
                @error('raw_dump') <div class="mt-2 rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror
            </div>

            <div class="mt-5 flex flex-col gap-3 rounded-3xl border border-white/10 bg-black/15 p-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-6 text-neutral-300">{{ __('barcodes.import.dump.review_hint') }}</p>
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="previewDump" wire:loading.attr="disabled" wire:target="previewDump,applyDump" class="pill-link">{{ __('barcodes.import.actions.preview') }}</button>
                    <button type="button" wire:click="applyDump" wire:loading.attr="disabled" wire:target="previewDump,applyDump" class="pill-link pill-link--accent">{{ __('barcodes.import.actions.apply') }}</button>
                </div>
            </div>
        </section>

        <aside class="space-y-6">
            <section id="scanner-command-panel" class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('barcodes.import.scanner_commands.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('barcodes.import.scanner_commands.subtitle') }}</p>

                <div class="mt-5 grid gap-4">
                    @foreach ([
                        'dump_command_image_path' => ['label' => __('barcodes.settings.fields.dump_command_image'), 'tone' => 'emerald'],
                        'clear_command_image_path' => ['label' => __('barcodes.settings.fields.clear_command_image'), 'tone' => 'rose'],
                    ] as $key => $command)
                        <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div class="font-semibold text-white">{{ $command['label'] }}</div>
                                <span class="status-chip {{ $command['tone'] === 'emerald' ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ __('barcodes.import.scanner_commands.command') }}</span>
                            </div>

                            @if ($scannerSettings[$key])
                                <div class="rounded-2xl border border-white/10 bg-white p-3">
                                    <img src="{{ asset('storage/'.ltrim($scannerSettings[$key], '/')) }}" alt="{{ $command['label'] }}" class="mx-auto max-h-40 rounded-lg object-contain">
                                </div>
                            @else
                                <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-4 text-sm leading-6 text-amber-100">{{ __('barcodes.import.scanner_commands.missing') }}</div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="surface-panel p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="admin-toolbar__title">{{ __('barcodes.import.history.title') }}</div>
                        <p class="admin-toolbar__subtitle">{{ __('barcodes.import.history.subtitle') }}</p>
                    </div>
                    <span class="status-chip status-chip--slate">{{ number_format($recentImports->count()) }}</span>
                </div>

                @if ($recentImports->isEmpty())
                    <div class="mt-4 rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-5 text-sm text-neutral-400">{{ __('barcodes.import.history.empty') }}</div>
                @else
                    <div class="mt-5 space-y-3">
                        @foreach ($recentImports as $import)
                            <article class="rounded-3xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-white">{{ $import->course?->name }}</div>
                                        <div class="mt-1 text-neutral-400">{{ $import->attendance_date?->format('Y-m-d') }}</div>
                                    </div>
                                    <span class="{{ $import->error_count > 0 ? 'status-chip status-chip--rose' : 'status-chip status-chip--emerald' }}">{{ $import->status }}</span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-neutral-300">
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">{{ __('barcodes.import.history.processed', ['count' => number_format($import->processed_count)]) }}</span>
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">{{ __('barcodes.import.stats.errors') }}: {{ number_format($import->error_count) }}</span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </aside>
    </div>

    @if ($preview !== [])
        <section class="grid gap-4 md:grid-cols-3">
            <article class="stat-card">
                <div class="kpi-label">{{ __('barcodes.import.stats.ready') }}</div>
                <div class="metric-value mt-4">{{ number_format($readyCount) }}</div>
            </article>
            <article class="stat-card">
                <div class="kpi-label">{{ __('barcodes.import.stats.skipped') }}</div>
                <div class="metric-value mt-4">{{ number_format($skippedCount) }}</div>
            </article>
            <article class="stat-card">
                <div class="kpi-label">{{ __('barcodes.import.stats.errors') }}</div>
                <div class="metric-value mt-4">{{ number_format($errorCount) }}</div>
            </article>
        </section>

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('barcodes.import.preview.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('barcodes.import.preview.summary', ['ready' => number_format($readyCount), 'skipped' => number_format($skippedCount), 'errors' => number_format($errorCount)]) }}</div>
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
