@props(['message'])

<div class="quick-entry-disabled" data-quick-entry-disabled>
    <div class="quick-entry-disabled__message" role="status">
        <div class="quick-entry-disabled__warning-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" focusable="false">
                <path d="M10.19 3.9 2.2 17.94A2.04 2.04 0 0 0 3.97 21h16.06a2.04 2.04 0 0 0 1.77-3.06L13.81 3.9a2.08 2.08 0 0 0-3.62 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M12 8.2v5.9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                <circle cx="12" cy="17.2" r="1.05" fill="currentColor" />
            </svg>
        </div>
        <p>{{ $message }}</p>
        <small>{{ __('quick-tests.disabled_help') }}</small>
    </div>
</div>
