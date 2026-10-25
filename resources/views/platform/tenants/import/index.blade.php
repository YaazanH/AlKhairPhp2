<x-platform-layout :title="__('tenant-import.title')">
    <div class="platform-management platform-import">
        <header class="platform-import__heading">
            <div><a class="platform-import__back" href="{{ route('platform.tenants.index') }}">{{ __('tenant-import.back_to_tenants') }}</a><p class="platform-import__eyebrow">{{ __('tenant-import.eyebrow') }}</p><h1>{{ __('tenant-import.title') }}</h1><p>{{ __('tenant-import.intro') }}</p></div>
            <span class="platform-import__badge">{{ __('tenant-import.new_tenant_only') }}</span>
        </header>
        <div class="platform-import__layout">
            <section class="platform-import__card">
                <span class="platform-import__number" aria-hidden="true">01</span>
                <h2>{{ __('tenant-import.source_upload_title') }}</h2><p>{{ __('tenant-import.source_upload_help') }}</p>
                <form data-tenant-import-upload data-start-url="{{ route('platform.tenants.imports.start') }}" data-chunk-bytes="{{ config('standalone-import.chunk_bytes') }}" data-maximum-bytes="{{ config('standalone-import.maximum_upload_bytes') }}" data-failure="{{ __('tenant-import.upload_failed') }}" data-too-large="{{ __('tenant-import.too_large') }}">
                    @csrf
                    <label for="transfer-package" class="platform-import__dropzone"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5"/></svg><strong>{{ __('tenant-import.choose_source_file') }}</strong><span>{{ __('tenant-import.source_file_hint', ['size' => number_format(config('standalone-import.maximum_upload_bytes') / 1024 / 1024)]) }}</span><input id="transfer-package" type="file" accept=".alkhair-backup,.alkhair-transfer" required data-import-file></label>
                    <div class="platform-import__upload-progress" hidden data-import-upload-progress><label for="import-upload-meter" data-import-upload-label>{{ __('tenant-import.uploading') }}</label><progress id="import-upload-meter" max="100" value="0"></progress></div>
                    <p role="alert" data-import-upload-error class="platform-import__error" hidden></p>
                    <button class="platform-import__primary" type="submit">{{ __('tenant-import.upload_continue') }}<span aria-hidden="true">↑</span></button>
                </form>
            </section>
            <aside class="platform-import__card platform-import__guide"><h2>{{ __('tenant-import.before_start') }}</h2><ol><li>{{ __('tenant-import.backup_guide') }}</li><li>{{ __('tenant-import.guide_snapshot') }}</li><li>{{ __('tenant-import.guide_review') }}</li></ol><a class="platform-import__secondary" href="{{ route('platform.tenants.imports.exporter') }}">{{ __('tenant-import.download_exporter') }}<span aria-hidden="true">↓</span></a><p>{{ __('tenant-import.optional_exporter_help') }}</p></aside>
        </div>
        <section class="platform-import__card"><div class="platform-import__section-heading"><h2>{{ __('tenant-import.recent') }}</h2><span class="platform-import__muted">{{ __('tenant-import.saved_automatically') }}</span></div><div class="platform-import__table-wrap"><table><thead><tr><th>{{ __('tenant-import.package') }}</th><th>{{ __('tenant-import.status') }}</th><th>{{ __('tenant-import.updated') }}</th><th><span class="sr-only">{{ __('tenant-import.open') }}</span></th></tr></thead><tbody>@forelse($operations as $operation)<tr><td><strong>{{ $operation->selection['name'] ?? $operation->summary['source_name'] ?? $operation->filename }}</strong><small>{{ $operation->filename }}</small></td><td><span class="platform-import__badge" data-state="{{ $operation->status }}">{{ __('tenant-import.states.'.$operation->status) }}</span></td><td>{{ $operation->updated_at->diffForHumans() }}</td><td><a class="platform-import__back" href="{{ route('platform.tenants.imports.show', $operation) }}">{{ __('tenant-import.open') }}</a></td></tr>@empty<tr><td colspan="4" class="platform-import__empty">{{ __('tenant-import.empty') }}</td></tr>@endforelse</tbody></table></div>{{ $operations->links() }}</section>
    </div>
</x-platform-layout>
