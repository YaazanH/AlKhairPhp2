@props([
    'show' => false,
    'title' => null,
    'description' => null,
    'closeMethod' => null,
    'maxWidth' => '4xl',
    'compact' => false,
    'fullViewport' => false,
])

@php
    $widthClass = match ($maxWidth) {
        'fit' => 'admin-modal__dialog--fit',
        'xl' => 'admin-modal__dialog--xl',
        '2xl' => 'admin-modal__dialog--2xl',
        '3xl' => 'admin-modal__dialog--3xl',
        '5xl' => 'admin-modal__dialog--5xl',
        '6xl' => 'admin-modal__dialog--6xl',
        '7xl' => 'admin-modal__dialog--7xl',
        '8xl' => 'admin-modal__dialog--8xl',
        default => 'admin-modal__dialog--4xl',
    };
@endphp

@if ($show)
    <div class="admin-modal {{ $fullViewport ? 'admin-modal--full-viewport' : '' }}">
        <div class="admin-modal__backdrop"></div>
        <div class="admin-modal__viewport">
            <div class="admin-modal__dialog {{ $widthClass }} {{ ($compact || $maxWidth === '8xl') ? 'admin-modal__dialog--compact' : '' }}">
                <div class="admin-modal__header">
                    <div>
                        @if ($title)
                            <h2 class="admin-modal__title">{{ $title }}</h2>
                        @endif

                    </div>

                    @if ($closeMethod)
                        <button type="button" wire:click.prevent.stop="{{ $closeMethod }}" class="admin-modal__close" aria-label="{{ __('crud.common.actions.close') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    @endif
                </div>

                <div class="admin-modal__body">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
@endif
