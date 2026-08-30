@props([
    'show' => false,
    'title' => null,
    'description' => null,
    'closeMethod' => null,
    'dismissible' => true,
    'hideHeader' => false,
    'maxWidth' => '4xl',
    'compact' => false,
    'fullViewport' => false,
])

@php
    $widthClass = match ($maxWidth) {
        'fit' => 'admin-modal__dialog--fit',
        'sm' => 'admin-modal__dialog--sm',
        'md' => 'admin-modal__dialog--md',
        'xl' => 'admin-modal__dialog--xl',
        '2xl' => 'admin-modal__dialog--2xl',
        '3xl' => 'admin-modal__dialog--3xl',
        '5xl' => 'admin-modal__dialog--5xl',
        '6xl' => 'admin-modal__dialog--6xl',
        '7xl' => 'admin-modal__dialog--7xl',
        '8xl' => 'admin-modal__dialog--8xl',
        default => 'admin-modal__dialog--4xl',
    };

    $modalBody = (string) $slot;

    if ($closeMethod) {
        $redundantDismissLabels = collect([
            __('crud.common.actions.cancel'),
            __('crud.common.actions.close'),
            __('finance.actions.cancel'),
            __('access.roles.actions.cancel'),
            __('activities.common.actions.cancel'),
            __('crud.students.form.parent_shortcut.cancel'),
        ])->filter()->unique();

        foreach ($redundantDismissLabels as $dismissLabel) {
            $pattern = '#<(button|a)\b[^>]*>\s*'.preg_quote(e($dismissLabel), '#').'\s*</\\1>#u';
            $modalBody = preg_replace($pattern, '', $modalBody) ?? $modalBody;
        }
    }
@endphp

@if ($show)
    <div class="admin-modal {{ $fullViewport ? 'admin-modal--full-viewport' : '' }}">
        <div class="admin-modal__backdrop"></div>
        <div class="admin-modal__viewport">
            <div class="admin-modal__dialog {{ $widthClass }} {{ ($compact || $maxWidth === '8xl') ? 'admin-modal__dialog--compact' : '' }}">
                @unless ($hideHeader)
                    <div class="admin-modal__header">
                        <div>
                            @if ($title)
                                <h2 class="admin-modal__title">{{ $title }}</h2>
                            @endif

                        </div>

                        <div class="admin-modal__header-actions">
                            @isset($headerActions)
                                {{ $headerActions }}
                            @endisset
                            @if ($closeMethod && $dismissible)
                                <button type="button" wire:click="{{ $closeMethod }}" class="admin-modal__close" aria-label="{{ __('crud.common.actions.close') }}">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endunless

                <div class="admin-modal__body">
                    {!! $modalBody !!}
                </div>
            </div>
        </div>
    </div>
@endif
