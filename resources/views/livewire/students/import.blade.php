<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\StudentRosterImportService;
use App\Services\XlsxExportService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesPermissions, WithFileUploads;

    public $importFile = null;

    public array $previewRows = [];

    public array $credentials = [];

    public int $validCount = 0;

    public int $errorCount = 0;

    public int $warningCount = 0;

    public bool $parentsEnabled = false;

    public function mount(): void
    {
        $this->authorizePermission('students.create');
        $this->parentsEnabled = app(CurrentModuleAccess::class)->enabled('parents');
    }

    public function preview(): void
    {
        $this->authorizePermission('students.create');
        $this->validateFile();

        try {
            $preview = app(StudentRosterImportService::class)->preview(
                $this->importFile->getRealPath(),
                $this->importFile->getClientOriginalExtension(),
                $this->currentParentsEnabled(),
            );
        } catch (RuntimeException $exception) {
            $this->addError('importFile', $exception->getMessage());

            return;
        }

        if ($preview['has_parent_data']) {
            $this->authorizePermission('parents.create');
        }

        $this->previewRows = $preview['rows'];
        $this->validCount = $preview['valid_count'];
        $this->warningCount = $preview['warning_count'];
        $this->errorCount = $preview['error_count'];
        $this->credentials = [];
    }

    public function import(): void
    {
        $this->authorizePermission('students.create');
        $this->validateFile();
        $parentsEnabled = $this->currentParentsEnabled();

        try {
            $preview = app(StudentRosterImportService::class)->preview(
                $this->importFile->getRealPath(),
                $this->importFile->getClientOriginalExtension(),
                $parentsEnabled,
            );

            if ($preview['has_parent_data']) {
                $this->authorizePermission('parents.create');
            }

            $credentials = app(StudentRosterImportService::class)->import(
                $this->importFile->getRealPath(),
                $this->importFile->getClientOriginalExtension(),
                $parentsEnabled,
            );
        } catch (RuntimeException $exception) {
            $this->addError('importFile', $exception->getMessage());

            return;
        }

        $this->credentials = $credentials;
        $this->previewRows = [];
        $this->validCount = 0;
        $this->warningCount = 0;
        $this->errorCount = 0;
        $this->importFile = null;
        session()->flash('status', __('crud.students.import.saved', ['count' => collect($credentials)->where('role', 'student')->count()]));
    }

    public function downloadCredentials(): mixed
    {
        $this->authorizePermission('students.create');
        abort_if($this->credentials === [], 404);

        return app(XlsxExportService::class)->download(
            'student-import-credentials',
            [
                __('crud.students.import.credentials.name'),
                __('crud.students.import.credentials.role'),
                __('crud.students.import.credentials.number'),
                __('crud.students.import.credentials.login'),
                __('crud.students.import.credentials.email'),
                __('crud.students.import.credentials.password'),
            ],
            collect($this->credentials)->map(fn (array $credential) => [
                $credential['name'],
                __('crud.students.import.roles.'.$credential['role']),
                $credential['number'],
                $credential['login'],
                $credential['email'],
                $credential['password'],
            ])->all(),
        );
    }

    private function currentParentsEnabled(): bool
    {
        $enabled = app(CurrentModuleAccess::class)->enabled('parents');

        if ($enabled !== $this->parentsEnabled) {
            throw ValidationException::withMessages([
                'importFile' => __('crud.students.import.errors.modules_changed'),
            ]);
        }

        return $enabled;
    }

    private function validateFile(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
        ]);
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.students') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.students.import.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.students.import.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-6">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div>
                <label class="form-label" for="student-import-file">{{ __('crud.students.import.file') }}</label>
                <input id="student-import-file" wire:model="importFile" type="file" accept=".csv,.txt,.xlsx" class="mt-2 block w-full rounded-2xl border border-white/10 px-4 py-4 text-sm">
                <p class="mt-2 text-sm text-neutral-400">{{ __('crud.students.import.help') }}</p>
                @error('importFile') <div class="mt-2 text-sm text-red-300">{{ $message }}</div> @enderror
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('students.import.template') }}" class="pill-link">{{ __('crud.students.import.download_template') }}</a>
                <button type="button" wire:click="preview" wire:loading.attr="disabled" class="pill-link pill-link--accent">
                    {{ __('crud.students.import.preview') }}
                </button>
            </div>
        </div>

        <div class="mt-5 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-neutral-300">
            {{ $parentsEnabled ? __('crud.students.import.parents_enabled') : __('crud.students.import.parents_disabled') }}
        </div>
    </section>

    @if ($previewRows !== [])
        <section class="surface-panel overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 p-5">
                <div>
                    <h2 class="text-lg font-semibold text-white">{{ __('crud.students.import.preview_title') }}</h2>
                    <p class="mt-1 text-sm text-neutral-400">{{ __('crud.students.import.preview_counts', ['valid' => $validCount, 'warnings' => $warningCount, 'errors' => $errorCount]) }}</p>
                </div>
                <button type="button" wire:click="import" wire:loading.attr="disabled" @disabled($errorCount > 0) class="pill-link pill-link--accent disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('crud.students.import.confirm') }}
                </button>
            </div>
            <div class="surface-table-wrapper overflow-x-auto">
                <table class="table-content text-sm">
                    <thead><tr>
                        <th>{{ __('crud.students.import.columns.line') }}</th>
                        <th>{{ __('crud.students.import.columns.student') }}</th>
                        <th>{{ __('crud.students.import.columns.birth_date') }}</th>
                        <th>{{ __('crud.students.import.columns.gender') }}</th>
                        <th>{{ __('crud.students.import.columns.school') }}</th>
                        @if ($parentsEnabled)<th>{{ __('crud.students.import.columns.parent') }}</th>@endif
                        <th>{{ __('crud.students.import.columns.result') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($previewRows as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td class="font-medium">{{ $row['first_name'] }} {{ $row['last_name'] }}</td>
                                <td>{{ $row['birth_date'] }}</td>
                                <td>{{ $row['gender'] }}</td>
                                <td>{{ $row['school'] ?: '—' }}</td>
                                @if ($parentsEnabled)<td>{{ $row['parent'] ?: '—' }}</td>@endif
                                <td>
                                    @if ($row['status'] === 'ready')
                                        <span class="text-emerald-300">{{ __('crud.students.import.ready') }}</span>
                                    @else
                                        <ul class="space-y-1 text-red-300">
                                            @foreach ($row['errors'] as $error)<li>{{ $error }}</li>@endforeach
                                        </ul>
                                    @endif
                                    @if ($row['warnings'] !== [])
                                        <ul class="mt-1 space-y-1 text-amber-300">
                                            @foreach ($row['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($credentials !== [])
        <section class="surface-panel overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 p-5">
                <div>
                    <h2 class="text-lg font-semibold text-white">{{ __('crud.students.import.credentials_title') }}</h2>
                    <p class="mt-1 text-sm text-neutral-400">{{ __('crud.students.import.credentials_help') }}</p>
                </div>
                <button type="button" wire:click="downloadCredentials" class="pill-link pill-link--accent">{{ __('crud.students.import.download_credentials') }}</button>
            </div>
            <div class="surface-table-wrapper overflow-x-auto">
                <table class="table-content text-sm">
                    <thead><tr>
                        <th>{{ __('crud.students.import.credentials.name') }}</th>
                        <th>{{ __('crud.students.import.credentials.role') }}</th>
                        <th>{{ __('crud.students.import.credentials.login') }}</th>
                        <th>{{ __('crud.students.import.credentials.password') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($credentials as $credential)
                            <tr>
                                <td class="font-medium">{{ $credential['name'] }}</td>
                                <td>{{ __('crud.students.import.roles.'.$credential['role']) }}</td>
                                <td dir="ltr">{{ $credential['login'] }}</td>
                                <td dir="ltr" class="font-mono">{{ $credential['password'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
