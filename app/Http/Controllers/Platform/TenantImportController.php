<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Models\Landlord\TenantImport;
use App\Services\Landlord\PlanModuleManager;
use App\Services\Landlord\TenantImportFiles;
use App\Services\Landlord\TenantImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class TenantImportController extends Controller
{
    public function __construct(private TenantImportService $imports, private TenantImportFiles $files) {}

    public function index(Request $request): View
    {
        $query = TenantImport::query();
        if (! $request->user('platform')->isPlatformOwner()) {
            $query->where('platform_administrator_id', $request->user('platform')->id);
        }

        return view('platform.tenants.import.index', ['operations' => $query->latest()->paginate(15)]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/\.(?:alkhair-transfer|alkhair-backup)$/D'],
            'bytes' => ['required', 'integer', 'min:1', 'max:'.config('standalone-import.maximum_upload_bytes')],
        ]);
        $operation = TenantImport::create([
            'uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id,
            'filename' => basename(str_replace('\\', '/', $data['filename'])), 'expected_bytes' => $data['bytes'],
            'status' => 'uploading', 'heartbeat_at' => now(),
        ]);

        return response()->json(['url' => route('platform.tenants.imports.show', $operation)], 201);
    }

    public function show(Request $request, TenantImport $operation): View
    {
        $this->authorizeOperation($request, $operation);
        $coverage = $operation->selection ? $this->coverageForView($operation) : null;

        return view('platform.tenants.import.show', [
            'operation' => $operation, 'coverage' => $coverage,
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(),
            'modules' => app(PlanModuleManager::class)->catalog(),
        ]);
    }

    public function status(Request $request, TenantImport $operation): JsonResponse
    {
        $this->authorizeOperation($request, $operation);

        return response()->json([
            'status' => $operation->status, 'label' => __('tenant-import.states.'.$operation->status),
            'phase' => __('tenant-import.phases.'.($operation->phase ?? 'waiting')),
            'progress' => $operation->progress, 'next_chunk' => $operation->next_chunk,
            'uploaded_bytes' => $operation->uploaded_bytes, 'expected_bytes' => $operation->expected_bytes,
            'error' => $operation->error_code ? __('tenant-import.errors.'.$operation->error_code) : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function chunk(Request $request, TenantImport $operation, int $index): JsonResponse
    {
        $this->authorizeOperation($request, $operation);
        abort_unless($index >= 0 && (int) $request->header('Content-Length') <= config('standalone-import.chunk_bytes'), 413);
        $this->files->append($operation, $index, $request->getContent(asResource: true));

        return response()->json(['next_chunk' => $operation->fresh()->next_chunk]);
    }

    public function validateUpload(Request $request, TenantImport $operation): RedirectResponse
    {
        $this->authorizeOperation($request, $operation);
        if ($operation->isSettingsBackup()) {
            $data = $request->validate([
                'source_application_key' => ['required', 'string', 'max:256'],
                'source_url' => ['required', 'string', 'url:http,https', 'max:2048'],
            ]);
            $this->imports->secure($operation, $data['source_application_key'], $data['source_url']);
        } else {
            $data = $request->validate(['transfer_code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]);
            $this->imports->secure($operation, $data['transfer_code']);
        }

        return redirect()->route('platform.tenants.imports.show', $operation);
    }

    public function preview(Request $request, TenantImport $operation): RedirectResponse
    {
        $this->authorizeOperation($request, $operation);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'max:63', Rule::notIn(config('tenancy.reserved_subdomains', [])), Rule::unique(Tenant::class, 'slug')->ignore($operation->tenant_id)],
            'locale' => ['required', Rule::in(array_keys(config('app.supported_locales')))],
            'timezone' => ['required', 'timezone'], 'owner_id' => ['required', 'integer', 'min:1'],
            'storage_limit_gb' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'plan_id' => ['required', 'integer', 'exists:landlord.plans,id'],
            'extras' => ['nullable', 'array'], 'extras.*' => ['required', 'string', 'distinct'],
        ]);
        $host = $data['slug'].'.'.config('tenancy.base_domain');
        if (TenantDomain::where('host', $host)->when($operation->tenant_id, fn ($query, $id) => $query->where('tenant_id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['slug' => __('tenant-import.errors.domain_taken')]);
        }
        $data['storage_limit_bytes'] = (int) round((float) $data['storage_limit_gb'] * 1024 * 1024 * 1024);
        $data['owner_id'] = (string) $data['owner_id'];
        $data['plan_id'] = (int) $data['plan_id'];
        $data['extras'] = $data['extras'] ?? [];
        unset($data['storage_limit_gb']);
        $this->imports->select($operation, $data);

        return redirect()->route('platform.tenants.imports.show', $operation)->with('status', __('tenant-import.preview_updated'));
    }

    public function prepare(Request $request, TenantImport $operation): RedirectResponse
    {
        $this->authorizeOperation($request, $operation);
        $data = $request->validate(['signature' => ['required', 'string', 'size:64'], 'confirm' => ['accepted']]);
        $this->imports->enqueue($operation, $data['signature']);

        return redirect()->route('platform.tenants.imports.show', $operation);
    }

    public function allowMissingFiles(Request $request, TenantImport $operation): RedirectResponse
    {
        $this->authorizeOperation($request, $operation);
        $data = $request->validate(['signature' => ['required', 'string', 'size:64'], 'confirm' => ['required', 'accepted']]);
        $this->imports->allowMissingFiles($operation, $data['signature'], $request->user('platform'));

        return redirect()->route('platform.tenants.imports.show', $operation);
    }

    public function activate(Request $request, TenantImport $operation): RedirectResponse
    {
        $this->authorizeOperation($request, $operation);
        abort_unless($request->user('platform')->hasPlatformPermission('manage.subscriptions'), 403);
        $data = $request->validate([
            'signature' => ['required', 'string', 'size:64'], 'confirm_slug' => ['required', Rule::in([$operation->tenant?->slug])],
            'reviewed' => ['accepted'], 'starts_at' => ['required', 'date', 'before_or_equal:today'],
            'subscription_status' => ['required', Rule::in(['active', 'trial'])],
            'trial_days' => ['nullable', 'required_if:subscription_status,trial', 'integer', 'min:1', 'max:365'],
        ]);
        $this->imports->activate($operation, $data['signature'], $request->user('platform'), $data);

        return redirect()->route('platform.tenants.imports.show', $operation)->with('status', __('tenant-import.completed'));
    }

    public function cancel(Request $request, TenantImport $operation): RedirectResponse
    {
        $this->authorizeOperation($request, $operation);
        $this->imports->cancel($operation);

        return redirect()->route('platform.tenants.imports.show', $operation);
    }

    public function exporter(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'alkhair-exporter-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ([
            'app/Services/BackupEncryptionService.php', 'app/Exceptions/BackupDecryptionException.php',
            'app/Services/StandaloneTransfer/TransferContract.php', 'app/Services/StandaloneTransfer/TransferExporter.php',
            'config/standalone-import.php', 'resources/standalone-transfer/v1-schema.json',
            'tools/export-standalone.php', 'docs/operations/standalone-tenant-transfer.md',
        ] as $file) {
            $zip->addFile(base_path($file), $file);
        }
        $zip->close();

        return response()->download($path, 'alkhair-v1-export-tool.zip')->deleteFileAfterSend();
    }

    private function authorizeOperation(Request $request, TenantImport $operation): void
    {
        abort_unless($operation->platform_administrator_id === $request->user('platform')->id || $request->user('platform')->isPlatformOwner(), 404);
    }

    private function coverageForView(TenantImport $operation): array
    {
        try {
            return $this->imports->coverage($operation, $operation->selection);
        } catch (ValidationException) {
            return ['required' => array_keys($operation->summary['required_modules']), 'effective' => [], 'missing' => array_keys($operation->summary['required_modules']), 'plan' => null];
        }
    }
}
